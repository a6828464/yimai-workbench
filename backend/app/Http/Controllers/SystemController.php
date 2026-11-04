<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Throwable;

/**
 * 系统维护（版本检查 / 在线更新 / 日志 / 更新日志）。
 *
 * ⚠️ `use Throwable;` 这一行不能删：本文件里所有 `catch (Throwable)` 都靠它。
 * 少了它，`Throwable` 会被解析成 `App\Http\Controllers\Throwable`（不存在），catch 永远
 * 不匹配 —— 线上表现就是「代码里明明写了兜底，还是 500」：changelog 读 CHANGELOG 时
 * 撞到 open_basedir 越界、update 里 proc_open 被禁，都会绕过兜底直接冒泡。
 * （2026-11-04 实测：`/api/system/changelog` 匿名 500，栈顶就是 is_file 的 open_basedir 警告。）
 */

final class SystemController extends Controller
{
    /** GET /system/version（?force=1 跳过 manifest 缓存强刷） */
    public function version(Request $r)
    {
        return ok(systemVersionInfo((string) $r->query('force') === '1'));
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

    /**
     * GET /system/changelog：更新日志（**公开只读**，路由说明见 routes/api.php）。
     *
     * 返回三块，其中只有 `records` 对匿名调用方开放：
     *  - `records`：解析 CHANGELOG.md 前 N 个 `## ` 标题 → `[{version,title,date}]`，
     *    给小程序「关于」页显示版本号与一句话更新内容（未登录也要能看，故路由在公开组）；
     *  - `content`：日志原文 —— **仅登录后返回**（管理端「版本更新」页渲染 markdown）。
     *    匿名拿空串：正文含内部上线细节，公开接口不该无鉴权下发；
     *  - `latest`：远端最新发行说明 —— **仅登录后**才去拉：它会向 gitee / github
     *    各发一次 HTTP 请求（超时 12s），挂在免登录接口上等于给外部站点做了个匿名放大器。
     *
     * ?limit=3（默认 3，夹在 1..10）。文件缺失或解析不出标题时 `records` 是空数组，不是 500。
     */
    public function changelog(Request $r)
    {
        $limit = (int) $r->query('limit', 3);
        $limit = $limit < 1 ? 3 : min(10, $limit);

        $file = $this->changelogPath();
        $content = '';
        if ($file !== null) {
            try {
                $content = (string) file_get_contents($file);
            } catch (Throwable) {
                // open_basedir / 权限：读不到就是没有日志，不能变成 500
                $content = '';
            }
        }

        // 这条路由是**公开**的（小程序「关于」未登录也要显示版本号），所以按调用方分流：
        //  - 匿名：只给 `records`（每条只有版本号 / 一句话标题 / 日期）。正文 `content` 置空 ——
        //    CHANGELOG 里写着内部上线细节（数据修复条数、脚本路径、账号口径、事故复盘），
        //    没有任何鉴权的接口不该把整份日志原文下发；`latest` 也给空形状，顺带避免匿名用户
        //    驱使服务端去打 gitee / github（每次 12s 超时，等于给外部站点做了个放大器）。
        //  - 已登录：`content` 与 `latest` 照旧返回，管理端「版本更新」页（登录态）行为不变。
        $authed = $r->user() !== null;

        return ok([
            'content' => $authed
                ? (trim($content) !== '' ? $content : "# 更新日志\n\n暂无可用更新日志。")
                : '',
            // 文件缺失 / 一个 `## ` 都没有 → 空数组（前端按空态展示，不是错误）
            'records' => self::parseChangelogHeadings($content, $limit),
            'latest' => $authed ? latestReleaseNotes() : self::emptyReleaseNotes(),
        ]);
    }

    /**
     * CHANGELOG.md 的定位（部署形态不同，位置也不同）。
     *
     * 候选：后端站根（发行包会随包复制）→ 上一级（git 仓库根）→ public/。
     *
     * 多个候选同时存在时**取 mtime 最新的那个**，不是取第一个：仓库里
     * `backend/CHANGELOG.md` 是 2026-09-21 的旧随包副本，而真正在维护的日志在仓库根。
     * 按顺序取第一个会让「关于」页永远显示三个月前的版本，用户以为系统再没更新过。
     *
     * 单独抽成本方法（而不是写在 changelog() 里）是为了让「找不到文件」只有一个返回点：
     * 三处候选都不可达时返回 null，changelog() 据此产出空数组，而不是抛异常。
     */
    protected function changelogPath(): ?string
    {
        // ⚠️ 候选必须全部落在 open_basedir 允许的目录内。曾把 `dirname(base_path())`
        // 放进候选，线上宝塔只允许站点根与 /tmp —— is_file 直接抛 open_basedir 警告，
        // 再叠加「catch 里的 Throwable 没 import」，公开接口就变成了 500。
        // 线上日志只可能在站点根（发行包会随包复制）或 public/，父目录没有意义。
        $candidates = [
            base_path().'/CHANGELOG.md',
            base_path().'/public/CHANGELOG.md',
        ];

        $best = null;
        $bestAt = -1;
        foreach ($candidates as $path) {
            try {
                if (! is_file($path)) {
                    continue;
                }
                $at = (int) @filemtime($path);
                if ($at > $bestAt) {
                    $best = $path;
                    $bestAt = $at;
                }
            } catch (Throwable) {
                // open_basedir 越界：换下一个候选，不能抛成 500
            }
        }

        return $best;
    }

    /**
     * 解析 CHANGELOG.md 的二级标题 → `[{version,title,date}]`。
     *
     * 仓库里的写法是 `## 2026-11-04 ｜ fix: v3.10.1 人员身份归一…`（全角竖线，也可能是半角）；
     * 早期条目带时分（`## 2026-08-26 14:01 ｜ feat: …`）。所以：
     *  - date    取标题里第一个 `YYYY-MM-DD`（时间部分丢掉，前端只显示到天）；
     *  - title   取分隔符之后的部分；没有分隔符时用整行标题（并剥掉开头的日期/时分）；
     *  - version 取标题里第一个 `vX.Y.Z`（去掉前缀 v）；没有就留空串 —— 不拿日期冒充版本号。
     *
     * 空内容 / 一个 `## ` 都没有 → `[]`（这就是「文件缺失返回空数组而不是 500」的落点）。
     */
    public static function parseChangelogHeadings(string $content, int $limit = 3): array
    {
        $limit = $limit < 1 ? 3 : min(10, $limit);
        $out = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            if (count($out) >= $limit) {
                break;
            }
            if (preg_match('/^##\s+(.+)$/u', trim($line), $m) !== 1) {
                continue;
            }
            $heading = trim($m[1]);
            $date = preg_match('/(\d{4}-\d{2}-\d{2})/u', $heading, $d) === 1 ? $d[1] : '';
            // 分隔符后是正式标题：全角「｜」优先，其次半角「|」；
            // 没有分隔符时把开头的日期（含可选的时分）剥掉，别让标题只剩一个日期
            $title = preg_match('/[｜|]\s*(.+)$/u', $heading, $t) === 1
                ? trim($t[1])
                : trim((string) preg_replace('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?\s*/u', '', $heading, 1));
            $version = preg_match('/v(\d+\.\d+(?:\.\d+)?)/u', $heading, $v) === 1 ? $v[1] : '';
            $out[] = [
                'version' => $version,
                'title' => $title,
                'date' => $date,
            ];
        }

        return $out;
    }

    /** 免登录调用时 `latest` 的空形状（键与 latestReleaseNotes() 完全一致，前端不用判类型） */
    private static function emptyReleaseNotes(): array
    {
        return ['version' => '', 'name' => '', 'content' => '', 'url' => '', 'publishedAt' => ''];
    }

    /**
     * 受控更新脚本的定位。
     *
     * ## 两个历史坑，都在这里收口
     *
     * 1. **路径越界**：旧代码写 `base_path('../update.sh')` = 站点**上一级**目录，
     *    而宝塔站点开了 open_basedir（只允许站点根与 /tmp）。`is_file()` 直接抛
     *    ErrorException → 500，用户看到的只是"点击更新失败"，连"脚本不存在"的
     *    友好提示都到不了。所以候选路径**先站点内**（`base_path('update.sh')`），
     *    再兼容旧的上一级位置；每个候选都用 try/catch 吞掉 open_basedir 异常。
     * 2. **两种部署形态**：发行包是 `站点根/backend`，宝塔站点是站点根即 Laravel 根。
     *    找到脚本后把真实的 `APP_ROOT`（= base_path()）显式传给脚本，
     *    不再让脚本自己猜（见 update.sh 顶部的探测逻辑）。
     */
    private function resolveUpdateScript(): ?string
    {
        $candidates = [
            base_path('update.sh'),        // 站点根即 Laravel 根（线上宝塔形态）
            base_path('../update.sh'),     // 发行包形态：站点根/backend 下，脚本在上一层
        ];

        foreach ($candidates as $path) {
            try {
                if (is_file($path)) {
                    return $path;
                }
            } catch (Throwable) {
                // open_basedir 越界：这个候选不可访问，继续下一个
            }
        }

        return null;
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
        $script = $this->resolveUpdateScript();
        if ($script === null) {
            return response()->json([
                'code' => 1,
                'message' => '服务器未配置受控更新脚本：请把 update.sh 放到站点根（与 artisan 同级），再重试',
            ], 503);
        }
        // APP_ROOT 必须显式给：线上站点根就是 Laravel 根，而脚本默认还会去找 /backend。
        $command = 'APP_ROOT='.escapeshellarg(base_path())
            .' SITE_ROOT='.escapeshellarg(dirname($script))
            .' bash '.escapeshellarg($script);
        try {
            $result = runShell($command, 900);
        } catch (Throwable $e) {
            // 宝塔 PHP 默认禁用 proc_open 时，Symfony Process 构造即抛 LogicException，
            // 统一转成可读提示，避免前端只看到笼统的 "Server Error"
            return response()->json([
                'code' => 1,
                'message' => '更新脚本无法启动',
                'output' => [mb_substr($e->getMessage(), 0, 500)],
            ], 500);
        }
        if (! $result['ok']) {
            $lines = array_slice($result['output'], -15);

            return response()->json(['code' => 1, 'message' => '更新脚本执行失败', 'output' => $lines], 500);
        }
        audit($r, '更新', '版本更新', 0, '系统版本', '双店', implode('；', array_slice($result['output'], -30)));

        return ok(['updated' => true, 'output' => $result['output']]);
    }
}
