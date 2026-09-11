<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackupRun extends Command
{
    protected $signature = 'backup:run {--manual= : 指定触发方名称（如页面手动备份的操作人），缺省为系统定时}';

    protected $description = '工作台数据备份：本地 ZIP + WebDAV 远端上传（配置了远端时），按 GFS 策略清理过期';

    public function handle(): int
    {
        $config = BackupService::config();
        if (! $config['enabled']) {
            $this->line('自动备份未启用，跳过');

            return self::SUCCESS;
        }

        // 调度按每 10 分钟触发，由命令自行判断是否落在配置的时间窗口内（±10 分钟），并保证每天只跑一次
        $target = $config['run_at'] ?: '03:30';
        [$h, $m] = array_map('intval', explode(':', $target));
        $targetMin = $h * 60 + $m;
        $nowMin = (int) now()->format('H') * 60 + (int) now()->format('i');
        $diff = abs($nowMin - $targetMin);
        $diff = min($diff, 1440 - $diff);
        if ($diff >= 10) {
            return self::SUCCESS;
        }
        if (($config['status']['last_run_date'] ?? '') === today()->toDateString()) {
            $this->line('今天已完成自动备份，跳过');

            return self::SUCCESS;
        }

        $lock = Cache::lock('backup:run', 7200);
        if (! $lock->get()) {
            $this->line('跳过：其他备份/恢复任务执行中');

            return self::SUCCESS;
        }

        $operator = (string) ($this->option('manual') ?: '系统定时');
        $batch = 'BAK-'.now()->format('Ymd-His').'-'.substr((string) mt_rand(1000, 9999), 0, 4);
        $job = SyncJob::create([
            'batch_no' => $batch,
            'run_key' => $batch,
            'display_name' => now()->format('Y-m-d H:i').' 数据备份（自动）',
            'data_type' => '数据备份',
            'venue' => '双店',
            'status' => '进行中',
            'operator' => $operator,
            'started_at' => now(),
            'metadata' => ['trigger' => $operator === '系统定时' ? 'schedule' : 'manual'],
        ]);

        try {
            @set_time_limit(0);
            $result = BackupService::create('定时', uploadRemote: true);
            $job->update([
                'total_count' => $result['rows'],
                'success_count' => 1,
                'fail_count' => 0,
                'status' => '成功',
                'finished_at' => now(),
                'detail' => $result['remote_uploaded']
                    ? "{$result['file']}（表 {$result['tables']} 张 / {$result['rows']} 行），已上传远端"
                    : "{$result['file']}（表 {$result['tables']} 张 / {$result['rows']} 行）；{$result['remote_note']}",
            ]);
            $this->audit('定时备份', $job->id, (string) $job->detail);
            $this->info('备份完成：'.$result['file']);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $job->update(['status' => '失败', 'finished_at' => now(), 'error_message' => $message]);
            $this->audit('定时备份失败', $job->id, $message);
            $this->error('备份失败：'.$message);

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function audit(string $action, int $jobId, string $detail): void
    {
        AuditLog::create([
            'operator_id' => null,
            'operator_name' => '系统定时',
            'operator_role' => '系统',
            'action' => $action,
            'module' => '数据备份',
            'target_id' => (string) $jobId,
            'target_label' => '备份批次',
            'venue' => '双店',
            'detail' => mb_substr($detail, 0, 2000),
        ]);
    }
}
