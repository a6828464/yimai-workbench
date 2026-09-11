<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Services\BackupService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BackupController extends Controller
{
    /** GET /backup/config */
    public function configShow(Request $r)
    {
        requireSuper($r);

        return ok(['config' => BackupService::config(), 'status' => BackupService::status()]);
    }

    /** PUT /backup/config */
    public function configUpdate(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'enabled' => 'required|boolean',
            'runAt' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'keepLocal' => 'required|integer|min:1|max:90',
            'keepEnv' => 'required|boolean',
            'remote.type' => 'required|in:none,webdav',
            'remote.url' => 'nullable|url|max:300',
            'remote.username' => 'nullable|string|max:200',
            'remote.password' => 'nullable|string|max:300',
            'remote.path' => 'nullable|string|max:200',
        ]);
        $config = [
            'enabled' => (bool) $d['enabled'],
            'run_at' => $d['runAt'],
            'keep_local' => (int) $d['keepLocal'],
            'keep_env' => (bool) $d['keepEnv'],
            'remote' => [
                'type' => $d['remote']['type'],
                'url' => (string) ($d['remote']['url'] ?? ''),
                'username' => (string) ($d['remote']['username'] ?? ''),
                'password' => (string) ($d['remote']['password'] ?? ''),
                'path' => (string) ($d['remote']['path'] ?? 'yimai-backup'),
            ],
        ];
        if ($config['remote']['type'] === 'webdav' && $config['remote']['url'] === '') {
            abort(422, '启用 WebDAV 远端时必须填写地址');
        }
        BackupService::saveConfig($config);
        audit($r, '修改', '数据备份', 0, '备份配置', '双店', sprintf(
            '自动备份 %s · 时间 %s · 本地保留 %d 份 · %s',
            $config['enabled'] ? '开' : '关', $config['run_at'], $config['keep_local'],
            $config['remote']['type'] === 'webdav' ? '远端 WebDAV' : '仅本地'
        ));

        return ok(['config' => BackupService::config(), 'status' => BackupService::status()]);
    }

    /** POST /backup/test-connection：支持用页面里尚未保存的草稿参数测试 */
    public function testConnection(Request $r)
    {
        requireSuper($r);
        if (is_array($r->json('remote'))) {
            $saved = BackupService::config();
            $draft = $r->json()->all();
            // 密码留空 = 保持已保存的密码
            if ((string) ($draft['remote']['password'] ?? '') === '') {
                $draft['remote']['password'] = (string) ($saved['remote']['password'] ?? '');
            }
            BackupService::saveConfig(array_replace_recursive($saved, $draft));
        }
        $message = BackupService::testWebDav();
        audit($r, '测试连接', '数据备份', 0, 'WebDAV', '双店', $message);

        return ok(['message' => $message]);
    }

    /** POST /backup/run：立即备份（本地 + 可选远端上传），后台异步执行 */
    public function run(Request $r)
    {
        requireSuper($r);
        $uploadRemote = $r->boolean('uploadRemote', true);
        [$job, $ack, $lock] = $this->startJob($r, '数据备份', '数据备份', ['uploadRemote' => $uploadRemote]);
        $run = function () use ($job, $uploadRemote) {
            $result = BackupService::create('手动', uploadRemote: $uploadRemote);
            $job->update([
                'total_count' => $result['rows'],
                'success_count' => 1,
                'fail_count' => 0,
                'status' => '成功',
                'finished_at' => now(),
                'detail' => "{$result['file']}（表 {$result['tables']} 张 / {$result['rows']} 行 / 文件 {$result['files_count']} 个）；{$result['remote_note']}",
            ]);
        };

        return $this->finishJob($r, $job, $ack, $lock, $run, '数据备份');
    }

    /** GET /backup/files?scope=local|remote */
    public function files(Request $r)
    {
        requireSuper($r);
        $scope = (string) $r->query('scope', 'local');
        abort_unless(in_array($scope, ['local', 'remote'], true), 422, 'scope 无效');

        return ok(['files' => $scope === 'local' ? BackupService::listLocal() : BackupService::listRemote()]);
    }

    /** GET /backup/download?scope=local|remote&name=... */
    public function download(Request $r)
    {
        requireSuper($r);
        $scope = (string) $r->query('scope', 'local');
        $name = (string) $r->query('name', '');
        abort_unless(in_array($scope, ['local', 'remote'], true), 422, 'scope 无效');
        audit($r, '下载', '数据备份', 0, $name, '双店', '下载'.($scope === 'remote' ? '远端' : '本地').'备份');

        if ($scope === 'local') {
            return response()->download(BackupService::localPath($name), $name);
        }
        $tmp = BackupService::fetchRemote($name);

        return response()->download($tmp, $name)->deleteFileAfterSend(true);
    }

    /** DELETE /backup/file?scope=local|remote&name=... */
    public function deleteFile(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'scope' => 'required|in:local,remote',
            'name' => 'required|string|max:200',
        ]);
        if ($d['scope'] === 'local') {
            BackupService::deleteLocal($d['name']);
        } else {
            BackupService::deleteRemote($d['name']);
        }
        audit($r, '删除', '数据备份', 0, $d['name'], '双店', '删除'.($d['scope'] === 'remote' ? '远端' : '本地').'备份');

        return ok(['deleted' => $d['name']]);
    }

    /**
     * POST /backup/restore：恢复。multipart 上传（file 字段）或 JSON {scope, name}，后台异步执行。
     */
    public function restore(Request $r)
    {
        requireSuper($r);
        $source = 'upload';
        $name = '';
        $zipPath = null;
        if ($r->hasFile('file')) {
            $r->validate(['file' => 'required|file|mimes:zip|max:512000']);
            $uploaded = $r->file('file');
            $safeName = 'restore-'.now()->format('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9._-]/', '', (string) $uploaded->getClientOriginalName());
            $uploaded->move(BackupService::backupDir(), $safeName);
            $zipPath = BackupService::backupDir().'/'.$safeName;
            $name = $safeName;
        } else {
            $d = $r->validate(['scope' => 'required|in:local,remote', 'name' => 'required|string|max:200']);
            $source = $d['scope'];
            $name = $d['name'];
        }
        [$job, $ack, $lock] = $this->startJob($r, '数据恢复', '数据恢复', ['source' => $source, 'name' => $name]);
        $run = function () use ($job, $source, $name, $zipPath) {
            if ($zipPath === null) {
                $zipPath = $source === 'remote' ? BackupService::fetchRemote($name) : BackupService::localPath($name);
            }
            try {
                $result = BackupService::restore($zipPath, '恢复');
            } finally {
                // 上传/远端中转的临时文件用完即删
                if (str_starts_with(basename((string) $zipPath), 'restore-') || str_starts_with(basename((string) $zipPath), 'tmp-')) {
                    @unlink((string) $zipPath);
                }
            }
            $job->update([
                'total_count' => array_sum($result['restored']),
                'success_count' => 1,
                'fail_count' => count($result['skipped']),
                'status' => '成功',
                'finished_at' => now(),
                'detail' => $result['summary'],
            ]);
        };

        return $this->finishJob($r, $job, $ack, $lock, $run, '数据恢复');
    }

    /** POST /backup/verify {scope, name}：校验备份包完整性（逐文件 sha256），后台异步执行 */
    public function verify(Request $r)
    {
        requireSuper($r);
        $d = $r->validate(['scope' => 'required|in:local,remote', 'name' => 'required|string|max:200']);
        [$job, $ack, $lock] = $this->startJob($r, '备份校验', '备份校验', $d);
        $run = function () use ($job, $d) {
            $zipPath = $d['scope'] === 'remote' ? BackupService::fetchRemote($d['name']) : BackupService::localPath($d['name']);
            try {
                $manifest = BackupService::verifyZip($zipPath);
                $job->update([
                    'success_count' => 1,
                    'status' => '成功',
                    'finished_at' => now(),
                    'detail' => sprintf(
                        '校验通过：%s · %s 生成 · %d 张表 %d 行 · %d 个文件校验和全部匹配',
                        $d['name'], $manifest['created_at'] ?? '-', count($manifest['tables'] ?? []),
                        array_sum($manifest['tables'] ?? []), count($manifest['checksums'] ?? [])
                    ),
                ]);
            } finally {
                if ($d['scope'] === 'remote') {
                    @unlink($zipPath);
                }
            }
        };

        return $this->finishJob($r, $job, $ack, $lock, $run, '备份校验');
    }

    // ==================== 异步任务骨架（与 /ky/import 同模式） ====================

    /** @return array{0: SyncJob, 1: array, 2: Lock} */
    private function startJob(Request $r, string $dataType, string $shortName, array $metadata): array
    {
        // 回收僵尸任务：进程被外部掐断时走不到 catch，把超时未收尾的判失败
        SyncJob::whereIn('data_type', ['数据备份', '数据恢复', '备份校验'])
            ->where('status', '进行中')
            ->where('started_at', '<', now()->subMinutes(130))
            ->update(['status' => '失败', 'finished_at' => now(), 'error_message' => '备份/恢复超时未完成（疑似进程被中断），已自动回收']);
        // 备份与恢复互斥（与定时备份命令共用同一把锁）
        $lock = Cache::lock('backup:run', 7200);
        abort_unless($lock->get(), 409, '已有备份/恢复任务在执行，请等待完成后再试');
        $batch = 'BAK-'.now()->format('Ymd-His').'-'.substr((string) mt_rand(1000, 9999), 0, 4);
        $job = SyncJob::create([
            'batch_no' => $batch,
            'run_key' => $batch,
            'display_name' => now()->format('Y-m-d H:i').' '.$shortName,
            'data_type' => $dataType,
            'venue' => '双店',
            'status' => '进行中',
            'operator' => $r->user()->name,
            'started_at' => now(),
            'metadata' => $metadata + ['operatorId' => $r->user()->id],
        ]);
        $ack = [
            'jobId' => $job->id,
            'batchNo' => $batch,
            'status' => '进行中',
            'background' => function_exists('fastcgi_finish_request'),
        ];

        return [$job, $ack, $lock];
    }

    private function finishJob(Request $r, SyncJob $job, array $ack, Lock $lock, callable $run, string $label)
    {
        // 本地 serve / 测试环境：同步执行完再返回
        if (! $ack['background']) {
            try {
                $run();

                return ok($ack + ['status' => $job->fresh()?->status, 'detail' => $job->fresh()?->detail]);
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 1000);
                $job->update(['status' => '失败', 'finished_at' => now(), 'error_message' => $message]);

                return response()->json(['code' => 1, 'message' => $label.'失败：'.$message]);
            } finally {
                $lock->release();
            }
        }
        // 生产 PHP-FPM：先交还「已受理」响应，浏览器不再挂起，同进程继续执行（僵尸回收与互斥锁已兜底中断场景）
        response()->json(['code' => 0, 'data' => $ack + ['message' => "{$label}任务已受理，服务器后台执行中，完成后自动更新"]])->send();
        fastcgi_finish_request();
        try {
            $run();
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $job->update(['status' => '失败', 'finished_at' => now(), 'error_message' => $message]);
            Log::channel('system_error')->error('Backup job failed', [
                'batch' => $job->batch_no, 'job_id' => $job->id, 'error' => $message,
            ]);
            $this->auditFallback($r, $label.'失败', $job->id, $message);
        } finally {
            $lock->release();
        }
        exit(0);
    }

    private function auditFallback(Request $r, string $action, int $jobId, string $detail): void
    {
        AuditLog::create([
            'operator_id' => $r->user()->id,
            'operator_name' => $r->user()->name,
            'operator_role' => $r->user()->role,
            'action' => $action,
            'module' => '数据备份',
            'target_id' => (string) $jobId,
            'target_label' => '备份批次',
            'venue' => '双店',
            'detail' => mb_substr($detail, 0, 2000),
        ]);
    }
}
