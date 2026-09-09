<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

final class SystemController extends Controller
{
    /** GET /system/version */
    public function version(Request $r)
    {
        return ok(systemVersionInfo());
    }

    /** GET /system/logs */
    public function logs(Request $r)
    {
        requireSuper($r);
        $dir = storage_path('logs');
        // Keep the shipped low-level diagnostics contract for older clients.
        if ((string) $r->query('list') === '1') {
            $files = glob($dir.'/*.log') ?: [];
            $files = array_map(fn ($file) => ['name' => basename($file), 'size' => filesize($file), 'modified' => date('Y-m-d H:i:s', filemtime($file))], $files);
            usort($files, fn ($a, $b) => strcmp((string) $b['modified'], (string) $a['modified']));

            return ok(['files' => $files]);
        }
        if ($r->filled('file')) {
            $name = (string) $r->query('file');
            abort_unless(preg_match('/^[A-Za-z0-9._-]+\.log$/', $name) === 1 && ! str_contains($name, '..'), 422, '非法日志文件名');
            $file = $dir.'/'.$name;
            if (! is_file($file)) {
                return ok(['file' => $name, 'tailBytes' => 0, 'lines' => [], 'message' => '日志文件不存在：'.$name]);
            }
            $size = filesize($file);
            $fp = fopen($file, 'rb');
            $read = min($size, 131072);
            if ($read < $size) {
                fseek($fp, -$read, SEEK_END);
            }
            $tail = (string) fread($fp, $read);
            fclose($fp);

            return ok(['file' => $name, 'tailBytes' => $read, 'lines' => array_values(array_filter(explode("\n", $tail), fn ($line) => trim($line) !== ''))]);
        }
        $channel = (string) $r->query('channel', 'runtime');
        abort_unless(in_array($channel, ['runtime', 'error'], true), 422, '日志类型无效');
        $date = (string) $r->query('date', '');
        abort_unless($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 422, '日志日期无效');
        $prefix = $channel === 'runtime' ? 'runtime' : 'error';
        $paths = glob($dir."/{$prefix}*.log") ?: [];
        if ($date !== '') {
            $paths = array_values(array_filter($paths, fn ($path) => str_contains(basename($path), $date) || date('Y-m-d', filemtime($path)) === $date));
        }
        usort($paths, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $records = [];
        foreach (array_slice($paths, 0, 5) as $path) {
            $size = filesize($path);
            $fp = fopen($path, 'rb');
            $read = min($size, 524288);
            if ($read < $size) {
                fseek($fp, -$read, SEEK_END);
            }
            $content = (string) fread($fp, $read);
            fclose($fp);
            foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
                if (! preg_match('/^\[([^]]+)]\s+[^.]+\.([A-Z]+):\s*(.*)$/', trim($line), $match)) {
                    continue;
                }
                $level = strtoupper($match[2]);
                if ($channel === 'error' && ! in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                    continue;
                }
                if ($r->filled('level') && $level !== strtoupper((string) $r->query('level'))) {
                    continue;
                }
                if ($r->filled('keyword') && ! str_contains(mb_strtolower($match[3]), mb_strtolower((string) $r->query('keyword')))) {
                    continue;
                }
                $records[] = ['time' => $match[1], 'level' => $level === 'WARNING' ? 'WARN' : $level, 'message' => $match[3], 'context' => ['file' => basename($path)]];
            }
        }

        return ok(['records' => array_slice(array_reverse($records), 0, 500), 'files' => array_map('basename', $paths)]);
    }

    /** GET /system/retention */
    public function retentionShow(Request $r)
    {
        requireSuper($r);

        return ok(retentionSettings());
    }

    /** PUT /system/retention */
    public function retentionUpdate(Request $r)
    {
        requireSuper($r);
        $data = $r->validate([
            'systemLogDays' => 'present|nullable|integer|min:1|max:3650',
            'auditLogDays' => 'present|nullable|integer|min:1|max:3650',
            'modelGenerationDays' => 'present|nullable|integer|min:1|max:3650',
        ]);
        $setting = setting();
        $before = retentionSettings();
        $setting->update(['retention' => $data]);
        audit($r, '修改', '系统日志', 0, '日志保留策略', '双店', json_encode(['before' => $before, 'after' => $data], JSON_UNESCAPED_UNICODE));
        pruneSystemRecords();

        return ok(retentionSettings());
    }

    /** GET /system/changelog */
    public function changelog(Request $r)
    {
        $candidates = [
            base_path().'/CHANGELOG.md',                 // 后端站根（部署时随包复制）
            dirname(base_path()).'/CHANGELOG.md',        // 上一级（git 仓库根）
            base_path().'/public/CHANGELOG.md',
        ];
        $file = null;
        foreach ($candidates as $f) {
            if (is_file($f)) {
                $file = $f;
                break;
            }
        }
        $content = $file === null ? '' : file_get_contents($file);

        return ok([
            'content' => is_string($content) && trim($content) !== '' ? $content : "# 更新日志\n\n暂无可用更新日志。",
            'latest' => latestReleaseNotes(),
        ]);
    }

    /** POST /system/update */
    public function update(Request $r)
    {
        requireSuper($r);
        $remote = systemVersionInfo();
        if ($remote['remote']['error'] !== '') {
            return response()->json(['code' => 1, 'message' => '远端不可达：'.$remote['remote']['error']], 503);
        }
        if ($remote['upToDate']) {
            return ok(['updated' => false, 'message' => '当前已是最新版本']);
        }
        $script = base_path('../update.sh');
        if (! is_file($script)) {
            return response()->json(['code' => 1, 'message' => '服务器未配置受控更新脚本（站点 app/update.sh 不存在），请先安装 update.sh'], 503);
        }
        $result = runShell('bash '.escapeshellarg($script), 900);
        if (! $result['ok']) {
            $lines = array_slice($result['output'], -15);

            return response()->json(['code' => 1, 'message' => '更新脚本执行失败', 'output' => $lines], 500);
        }
        audit($r, '更新', '版本更新', 0, '系统版本', '双店', implode('；', array_slice($result['output'], -30)));

        return ok(['updated' => true, 'output' => $result['output']]);
    }
}
