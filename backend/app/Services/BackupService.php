<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * 工作台数据备份引擎（v3.1.48）。
 *
 * 备份包为 ZIP：data/<表>.jsonl（每行一条 JSON 记录，规避 SQL 转义与跨驱动差异）+ files/（storage/app 私有文件）
 * + 可选 .env + manifest.json（版本、表行数、逐文件 sha256）。恢复策略为全量覆盖：
 * 先做「恢复前快照」→ migrate 把表结构升到当前 → 清表灌数据。
 * 远端支持 WebDAV（群晖/威联通等 NAS、坚果云、Alist 桥接的网盘），原生 HTTP 实现，不引入 Flysystem 依赖。
 */
final class BackupService
{
    /** 跳过的临时性表：结构由 migrate 保证、内容不承载业务数据 */
    private const SKIP_TABLES = [
        'migrations', 'cache', 'cache_locks', 'sessions',
        'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens', 'password_reset_tokens',
    ];

    private const FILE_PREFIX = 'yimai-backup-';

    // ==================== 配置 ====================

    public static function defaultConfig(): array
    {
        return [
            'enabled' => false,
            'run_at' => '03:30',
            'keep_local' => 7,
            'keep_env' => true,
            'remote' => [
                'type' => 'none', 'url' => '', 'username' => '', 'password' => '', 'path' => 'yimai-backup',
                's3' => [
                    'endpoint' => '', 'bucket' => '', 'region' => 'us-east-1', 'accessKey' => '', 'secretKey' => '',
                    'prefix' => 'yimai-backup/', 'style' => 'path',
                ],
            ],
            'status' => ['last_run_at' => '', 'last_run_date' => '', 'last_result' => '', 'last_detail' => ''],
        ];
    }

    public static function config(): array
    {
        $saved = (array) (AppSetting::oldest('id')->first()?->backup ?? []);
        $config = array_merge(self::defaultConfig(), $saved);
        $config['remote'] = array_merge(self::defaultConfig()['remote'], (array) ($saved['remote'] ?? []));
        $config['remote']['s3'] = array_merge(self::defaultConfig()['remote']['s3'], (array) ($saved['remote']['s3'] ?? []));

        return $config;
    }

    /** 对外展示用：密钥一律不回显，留空保存 = 保持原值 */
    public static function exportConfig(): array
    {
        $config = self::config();
        $config['remote']['password'] = '';
        $config['remote']['s3']['secretKey'] = '';

        return $config;
    }

    public static function saveConfig(array $config): array
    {
        $setting = AppSetting::oldest('id')->firstOrCreate([]);
        $current = self::config();
        // 密钥留空 = 保持原值，避免前端回显明文
        if ((string) ($config['remote']['password'] ?? '') === '') {
            $config['remote']['password'] = (string) ($current['remote']['password'] ?? '');
        }
        if ((string) ($config['remote']['s3']['secretKey'] ?? '') === '') {
            $config['remote']['s3']['secretKey'] = (string) ($current['remote']['s3']['secretKey'] ?? '');
        }
        $setting->update(['backup' => $config]);

        return self::config();
    }

    public static function status(): array
    {
        $config = self::config();
        $local = self::listLocal();

        return [
            'last_run_at' => (string) ($config['status']['last_run_at'] ?? ''),
            'last_result' => (string) ($config['status']['last_result'] ?? ''),
            'last_detail' => (string) ($config['status']['last_detail'] ?? ''),
            'next_run_at' => $config['enabled'] ? today()->toDateString().' '.$config['run_at'] : '',
            'local_count' => count($local),
            'local_size' => array_sum(array_column($local, 'size')),
            'remote_configured' => self::remoteConfigured(),
        ];
    }

    // ==================== 备份 ====================

    /** 生成备份包并按需上传远端，返回 [file, size, label, remote_uploaded, manifest] */
    public static function create(string $label, bool $uploadRemote = true): array
    {
        @set_time_limit(0);
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('服务器 PHP 缺少 zip 扩展，请在宝塔「PHP 设置 → 安装扩展」中启用 php_zip');
        }
        $dir = self::ensureBackupDir();
        $work = $dir.'/work-'.Str::random(8);
        if (! mkdir($work.'/data', 0755, true) || ! mkdir($work.'/files', 0755, true)) {
            throw new RuntimeException('无法创建备份临时目录');
        }
        try {
            $tables = [];
            foreach (self::businessTables() as $table) {
                $tables[$table] = self::dumpTable($table, $work.'/data');
            }

            [$fileCount, $fileBytes] = self::copyStorageFiles($work.'/files');

            $checksums = [];
            foreach (glob($work.'/data/*.jsonl') ?: [] as $f) {
                $checksums['data/'.basename($f)] = hash_file('sha256', $f);
            }
            $envIncluded = false;
            if (self::config()['keep_env'] && is_file(base_path('.env'))) {
                copy(base_path('.env'), $work.'/.env');
                $checksums['.env'] = hash_file('sha256', $work.'/.env');
                $envIncluded = true;
            }

            $manifest = [
                'format' => 1,
                'created_at' => now()->format('Y-m-d H:i:s'),
                'label' => $label,
                'app' => [
                    'commit' => (string) (self::versionInfo()['commit'] ?? ''),
                    'message' => (string) (self::versionInfo()['message'] ?? ''),
                    'changelog_version' => (string) (self::versionInfo()['version'] ?? ''),
                    'php' => PHP_VERSION,
                ],
                'driver' => DB::connection()->getDriverName(),
                'include_env' => $envIncluded,
                'tables' => $tables,
                'files_count' => $fileCount,
                'files_bytes' => $fileBytes,
                'checksums' => $checksums,
            ];
            file_put_contents($work.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));

            $name = self::FILE_PREFIX.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)).'.zip';
            $zipPath = $dir.'/'.$name;
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('无法创建备份压缩包');
            }
            foreach ([
                ['manifest.json', $work.'/manifest.json'],
                ['.env', $work.'/.env'],
            ] as [$local, $abs]) {
                if (is_file($abs)) {
                    $zip->addFile($abs, $local);
                }
            }
            foreach (glob($work.'/data/*.jsonl') ?: [] as $f) {
                $zip->addFile($f, 'data/'.basename($f));
            }
            foreach (self::filesIn($work.'/files') as $f) {
                $zip->addFile($f, 'files/'.substr($f, strlen($work.'/files/')));
            }
            $zip->close();

            $size = filesize($zipPath) ?: 0;
            $remoteUploaded = false;
            $remoteNote = '未配置远端存储';
            if ($uploadRemote && self::remoteConfigured()) {
                self::uploadRemote($zipPath, $name);
                $remoteUploaded = true;
                $remoteNote = self::remoteType() === 's3' ? '已上传至 S3 对象存储' : '已上传至 WebDAV';
                self::pruneRemote();
            } elseif ($uploadRemote) {
                $remoteNote = '远端未配置或未启用，仅保留本地';
            }

            self::pruneLocal();
            self::updateStatus([
                'last_run_at' => now()->format('Y-m-d H:i:s'),
                'last_run_date' => today()->toDateString(),
                'last_result' => '成功',
                'last_detail' => sprintf(
                    '%s备份 %s（%s），表 %d 张 %d 行，文件 %d 个；%s',
                    $label, $name, self::humanSize($size), count($tables), array_sum($tables), $fileCount, $remoteNote
                ),
            ]);

            return [
                'file' => $name, 'size' => $size, 'label' => $label,
                'remote_uploaded' => $remoteUploaded, 'remote_note' => $remoteNote,
                'tables' => count($tables), 'rows' => array_sum($tables),
                'files_count' => $fileCount,
            ];
        } finally {
            self::rrmdir($work);
        }
    }

    // ==================== 校验与恢复 ====================

    /** 校验备份包：manifest 存在且数据文件逐个 sha256 匹配，返回 manifest */
    public static function verifyZip(string $zipPath): array
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new RuntimeException('备份包无法打开（损坏或不是合法 ZIP），代码 '.$opened);
        }
        try {
            $manifestRaw = $zip->getFromName('manifest.json');
            if ($manifestRaw === false) {
                throw new RuntimeException('备份包缺少 manifest.json，不是本系统生成的备份');
            }
            $manifest = json_decode($manifestRaw, true);
            if (! is_array($manifest) || ! isset($manifest['checksums'])) {
                throw new RuntimeException('manifest.json 解析失败');
            }
            foreach ($manifest['checksums'] as $entry => $hash) {
                $stream = $zip->getStream((string) $entry);
                if ($stream === false) {
                    throw new RuntimeException("备份包缺少文件 {$entry}");
                }
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                fclose($stream);
                if (hash_final($ctx) !== $hash) {
                    throw new RuntimeException("文件 {$entry} 校验和不匹配，备份包可能已损坏或被篡改");
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /** 全量覆盖恢复：校验 → 恢复前快照 → migrate 升级表结构 → 清表灌数据 */
    public static function restore(string $zipPath, string $label = '恢复'): array
    {
        $manifest = self::verifyZip($zipPath);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $safety = self::create('恢复前快照', uploadRemote: false);

        Artisan::call('migrate', ['--force' => true]);

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('备份包无法打开');
        }
        try {
            $restored = [];
            $skipped = [];
            $columnsCache = [];
            foreach ($manifest['tables'] ?? [] as $table => $rows) {
                if (! Schema::hasTable((string) $table)) {
                    $skipped[$table] = '当前系统无此表';

                    continue;
                }
                $stream = $zip->getStream('data/'.$table.'.jsonl');
                if ($stream === false) {
                    $skipped[$table] = '备份包内缺少数据文件';

                    continue;
                }
                $columnsCache[$table] ??= array_flip(Schema::getColumnListing((string) $table));
                $allowed = $columnsCache[$table];
                DB::table($table)->truncate();
                $buffer = [];
                $count = 0;
                try {
                    while (($line = fgets($stream)) !== false) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }
                        $row = json_decode($line, true);
                        if (! is_array($row)) {
                            throw new RuntimeException("数据文件 data/{$table}.jsonl 第 ".($count + 1).' 行解析失败');
                        }
                        $buffer[] = array_intersect_key($row, $allowed);
                        if (count($buffer) >= 200) {
                            DB::table($table)->insert($buffer);
                            $count += count($buffer);
                            $buffer = [];
                        }
                    }
                } finally {
                    fclose($stream);
                }
                if ($buffer !== []) {
                    DB::table($table)->insert($buffer);
                    $count += count($buffer);
                }
                $restored[$table] = $count;
            }
        } finally {
            $zip->close();
            if ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
        invalidateBusinessCaches();

        $summary = sprintf(
            '已恢复 %d 张表共 %d 行（跳过 %d 张）；恢复前快照：%s',
            count($restored), array_sum($restored), count($skipped), $safety['file']
        );
        self::updateStatus([
            'last_run_at' => now()->format('Y-m-d H:i:s'),
            'last_run_date' => today()->toDateString(),
            'last_result' => '成功',
            'last_detail' => "{$label}完成：{$summary}",
        ]);

        return ['restored' => $restored, 'skipped' => $skipped, 'safety' => $safety, 'summary' => $summary];
    }

    // ==================== 本地文件管理 ====================

    public static function listLocal(): array
    {
        $files = glob(self::ensureBackupDir().'/'.self::FILE_PREFIX.'*.zip') ?: [];
        $list = [];
        foreach ($files as $f) {
            $list[] = [
                'name' => basename($f),
                'size' => filesize($f) ?: 0,
                'mtime' => date('Y-m-d H:i:s', (int) (filemtime($f) ?: time())),
            ];
        }
        usort($list, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $list;
    }

    public static function localPath(string $name): string
    {
        if (! preg_match('/^'.preg_quote(self::FILE_PREFIX, '/').'[A-Za-z0-9._-]+\.zip$/', $name)) {
            throw new RuntimeException('非法备份文件名');
        }
        $path = self::ensureBackupDir().'/'.$name;
        if (! is_file($path)) {
            throw new RuntimeException("本地不存在备份文件 {$name}");
        }

        return $path;
    }

    public static function deleteLocal(string $name): void
    {
        $path = self::localPath($name);
        if (! unlink($path)) {
            throw new RuntimeException("删除失败：{$name}");
        }
    }

    /** 本地仅保留最近 N 份 */
    public static function pruneLocal(): void
    {
        $keep = max(1, (int) self::config()['keep_local']);
        foreach (array_slice(self::listLocal(), $keep) as $old) {
            @unlink(self::ensureBackupDir().'/'.$old['name']);
        }
    }

    // ==================== 远端分发（WebDAV / S3 兼容对象存储） ====================

    /** 当前已配置的远端类型：webdav / s3 / null（未配置） */
    public static function remoteType(): ?string
    {
        if (self::davConfigured()) {
            return 'webdav';
        }
        if (self::s3Configured()) {
            return 's3';
        }

        return null;
    }

    public static function remoteConfigured(): bool
    {
        return self::remoteType() !== null;
    }

    public static function testRemote(): string
    {
        return match (self::remoteType()) {
            'webdav' => self::testWebDav(),
            's3' => self::testS3(),
            default => throw new RuntimeException('请先选择 WebDAV 或 S3 并填写配置'),
        };
    }

    public static function uploadRemote(string $zipPath, string $name): void
    {
        match (self::remoteType()) {
            'webdav' => (function () use ($zipPath, $name) {
                self::davEnsureDir(self::davBase());
                self::davPut($zipPath, self::davBase().'/'.$name);
            })(),
            's3' => self::s3Upload($zipPath, $name),
            default => throw new RuntimeException('远端存储未配置'),
        };
    }

    public static function listRemote(): array
    {
        return match (self::remoteType()) {
            'webdav' => self::listWebDav(),
            's3' => self::s3List(),
            default => [],
        };
    }

    public static function fetchRemote(string $name): string
    {
        return match (self::remoteType()) {
            'webdav' => self::fetchWebDav($name),
            's3' => self::s3Fetch($name),
            default => throw new RuntimeException('远端存储未配置'),
        };
    }

    public static function deleteRemote(string $name): void
    {
        match (self::remoteType()) {
            'webdav' => self::deleteWebDav($name),
            's3' => self::s3Delete($name),
            default => null,
        };
    }

    // ==================== WebDAV 远端 ====================

    public static function davConfigured(): bool
    {
        $remote = self::config()['remote'];

        return ($remote['type'] ?? '') === 'webdav'
            && trim((string) ($remote['url'] ?? '')) !== '';
    }

    public static function testWebDav(): string
    {
        if (! self::davConfigured()) {
            throw new RuntimeException('请先填写 WebDAV 地址');
        }
        $base = self::davBase();
        $res = self::davRequest('PROPFIND', $base, ['headers' => ['Depth' => '0']]);
        if ($res->status() === 404) {
            // 目录不存在则尝试创建，常见于首次使用
            self::davEnsureDir($base);
            $res = self::davRequest('PROPFIND', $base, ['headers' => ['Depth' => '0']]);
        }
        if (! in_array($res->status(), [200, 207], true)) {
            throw new RuntimeException('WebDAV 连接失败（HTTP '.$res->status().'），请检查地址/账号/密码/应用密码');
        }

        return '连接成功：'.$base;
    }

    public static function listWebDav(): array
    {
        $res = self::davRequest('PROPFIND', self::davBase(), ['headers' => ['Depth' => '1']]);
        if ($res->status() === 404) {
            return [];
        }
        if (! in_array($res->status(), [200, 207], true)) {
            throw new RuntimeException('WebDAV 列表获取失败（HTTP '.$res->status().'）');
        }
        $list = [];
        $xml = simplexml_load_string((string) $res->body());
        if ($xml !== false) {
            foreach ($xml->children('DAV:') as $node) {
                $href = rawurldecode((string) $node->children('DAV:')->href);
                $basename = basename(rtrim($href, '/'));
                if (! str_starts_with($basename, self::FILE_PREFIX)) {
                    continue;
                }
                $prop = $node->children('DAV:')->propstat->prop->children('DAV:');
                $list[] = [
                    'name' => $basename,
                    'size' => (int) $prop->getcontentlength,
                    'mtime' => date('Y-m-d H:i:s', strtotime((string) $prop->getlastmodified) ?: time()),
                ];
            }
        }
        usort($list, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $list;
    }

    /** 下载远端备份到本地临时文件（服务端中转，供恢复/校验/下载） */
    public static function fetchWebDav(string $name): string
    {
        if (! preg_match('/^'.preg_quote(self::FILE_PREFIX, '/').'[A-Za-z0-9._-]+\.zip$/', $name)) {
            throw new RuntimeException('非法备份文件名');
        }
        $tmp = self::ensureBackupDir().'/tmp-'.Str::random(8).'.zip';
        $res = self::davRequest('GET', self::davBase().'/'.$name, ['sink' => $tmp, 'timeout' => 1800]);
        if ($res->status() !== 200) {
            @unlink($tmp);
            throw new RuntimeException("远端下载失败（HTTP {$res->status()}）：{$name}");
        }

        return $tmp;
    }

    public static function deleteWebDav(string $name): void
    {
        $res = self::davRequest('DELETE', self::davBase().'/'.$name);
        if (! in_array($res->status(), [200, 202, 204, 404], true)) {
            throw new RuntimeException("远端删除失败（HTTP {$res->status()}）：{$name}");
        }
    }

    /**
     * 远端保留策略（GFS）：最近 7 天全留 + 其后 4 周每周最新 1 份 + 其后 6 个月每月最新 1 份，更旧的删除。
     */
    public static function pruneRemote(): void
    {
        $all = self::listRemote(); // 已按名称倒序 = 新→旧
        if ($all === []) {
            return;
        }
        $keep = [];
        $seenWeek = [];
        $seenMonth = [];
        foreach ($all as $f) {
            $mtime = \Carbon\CarbonImmutable::parse($f['mtime']);
            $ageDays = abs((int) $mtime->diffInDays(now(), false));
            if ($ageDays <= 7) {
                $keep[] = $f['name'];
            } elseif ($ageDays <= 35) {
                $weekKey = $mtime->format('o-W');
                if (! isset($seenWeek[$weekKey])) {
                    $seenWeek[$weekKey] = true;
                    $keep[] = $f['name'];
                }
            } elseif ($ageDays <= 210) {
                $monthKey = $mtime->format('Y-m');
                if (! isset($seenMonth[$monthKey])) {
                    $seenMonth[$monthKey] = true;
                    $keep[] = $f['name'];
                }
            }
        }
        foreach (array_diff(array_column($all, 'name'), $keep) as $name) {
            self::deleteRemote($name);
        }
    }

    private static function davBase(): string
    {
        $remote = self::config()['remote'];
        $url = rtrim(trim((string) $remote['url']), '/');
        $path = trim((string) $remote['path'], '/');

        return $path !== '' ? $url.'/'.$path : $url;
    }

    /** @return \Illuminate\Http\Client\Response */
    private static function davRequest(string $method, string $url, array $opts = [])
    {
        $remote = self::config()['remote'];
        $http = Http::withBasicAuth((string) $remote['username'], (string) $remote['password'])
            ->timeout($opts['timeout'] ?? 60)
            ->connectTimeout(15);
        if (isset($opts['sink'])) {
            $http = $http->withOptions(['sink' => $opts['sink']]);
        }
        try {
            return $http->send($method, $url, [
                'headers' => $opts['headers'] ?? [],
                'body' => $opts['body'] ?? null,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException(self::friendlyNetworkError($e), 0, $e);
        }
    }

    /** 把 cURL 底层网络错误翻译成可操作的提示（注意：60 含子串 6，必须先判 60） */
    private static function friendlyNetworkError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        return match (true) {
            str_contains($msg, 'cURL error 60') || str_contains($msg, 'SSL certificate problem') => 'HTTPS 证书校验失败（NAS 自签名证书常见），建议改用 http:// 地址或给 NAS 配置有效证书',
            str_contains($msg, 'cURL error 6:') => '无法解析主机名，请检查 WebDAV 地址拼写',
            str_contains($msg, 'cURL error 7') => '无法连接到服务器，请检查地址端口、NAS 的 WebDAV 服务是否开启及防火墙放行',
            str_contains($msg, 'cURL error 28') => '连接超时，请检查服务器到 NAS/网盘的网络连通性',
            default => '网络请求失败：'.mb_substr($msg, 0, 160),
        };
    }

    private static function davPut(string $localPath, string $url): void
    {
        $remote = self::config()['remote'];
        $fh = fopen($localPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('无法读取备份文件');
        }
        try {
            try {
                $res = Http::withBasicAuth((string) $remote['username'], (string) $remote['password'])
                    ->timeout(1800)
                    ->connectTimeout(15)
                    ->withBody($fh, 'application/zip')
                    ->put($url);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new RuntimeException('上传失败：'.self::friendlyNetworkError($e), 0, $e);
            }
            if (! in_array($res->status(), [200, 201, 204], true)) {
                throw new RuntimeException("WebDAV 上传失败（HTTP {$res->status()}），请检查网盘容量与账号权限");
            }
        } finally {
            fclose($fh);
        }
    }

    private static function davEnsureDir(string $base): void
    {
        // 逐级 MKCOL，已存在（405）视为成功
        $parts = array_values(array_filter(explode('/', preg_replace('#^https?://[^/]+#i', '', $base) ?? ''), 'strlen'));
        $host = preg_replace('#^(https?://[^/]+).*$#i', '$1', $base) ?? '';
        $current = $host;
        foreach ($parts as $part) {
            $current .= '/'.$part;
            $res = self::davRequest('MKCOL', $current);
            if (! in_array($res->status(), [201, 200, 204, 405, 301], true)) {
                throw new RuntimeException("WebDAV 创建目录失败（HTTP {$res->status()}）：{$part}");
            }
        }
    }

    // ==================== S3 兼容对象存储（原生 SigV4，零新增依赖） ====================

    public static function s3Configured(): bool
    {
        $s3 = self::config()['remote']['s3'] ?? [];

        return trim((string) ($s3['endpoint'] ?? '')) !== ''
            && trim((string) ($s3['bucket'] ?? '')) !== '';
    }

    private static function s3Prefix(): string
    {
        $prefix = trim((string) (self::config()['remote']['s3']['prefix'] ?? 'yimai-backup/'));

        return $prefix === '' ? '' : rtrim($prefix, '/').'/';
    }

    /** @return array{0: string, 1: string, 2: string} [签名用 host, 请求 origin, 路径前缀(含桶)] */
    private static function s3EndpointParts(): array
    {
        $s3 = self::config()['remote']['s3'];
        $p = parse_url(trim((string) $s3['endpoint']));
        $scheme = $p['scheme'] ?? 'https';
        $host = ($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '');
        $basePath = rtrim($p['path'] ?? '', '/');
        $bucket = trim((string) $s3['bucket']);
        if (($s3['style'] ?? 'path') === 'virtual') {
            $requestHost = $bucket.'.'.$host;
            $origin = $scheme.'://'.$requestHost;
            $base = '';
        } else {
            $requestHost = $host;
            $origin = $scheme.'://'.$host;
            $base = $basePath !== '' ? $basePath.'/'.$bucket : '/'.$bucket;
        }

        return [$requestHost, $origin, $base];
    }

    /**
     * S3 兼容请求（AWS Signature V4，path/virtual 寻址均可）。
     *
     * @param  array{payload_hash?: string, body?: mixed, sink?: string, timeout?: int}  $opts
     * @return \Illuminate\Http\Client\Response
     */
    private static function s3Request(string $method, string $key, array $query = [], array $opts = [])
    {
        $s3 = self::config()['remote']['s3'];
        $region = trim((string) ($s3['region'] ?? '')) !== '' ? (string) $s3['region'] : 'us-east-1';
        [$host, $origin, $base] = self::s3EndpointParts();
        $amzDate = now()->utc()->format('Ymd\THis\Z');
        $dateStamp = now()->utc()->format('Ymd');
        $payloadHash = (string) ($opts['payload_hash'] ?? hash('sha256', ''));

        $encodedKey = str_replace('%2F', '/', rawurlencode(trim($key, '/')));
        $uriPath = rtrim($base, '/').($encodedKey !== '' ? '/'.$encodedKey : '');
        ksort($query);
        $canonicalQuery = implode('&', array_map(
            fn ($k, $v) => rawurlencode((string) $k).'='.rawurlencode((string) $v),
            array_keys($query), array_values($query)
        ));
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "{$method}\n".($uriPath === '' ? '/' : $uriPath)."\n{$canonicalQuery}\n"
            ."host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n\n"
            ."{$signedHeaders}\n{$payloadHash}";
        $scope = "{$dateStamp}/{$region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.(string) $s3['secretKey'], true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $headers = [
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential='.trim((string) $s3['accessKey'])."/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];

        $http = Http::timeout($opts['timeout'] ?? 60)->connectTimeout(15);
        if (isset($opts['sink'])) {
            $http = $http->withOptions(['sink' => $opts['sink']]);
        }
        $url = $origin.$uriPath.($canonicalQuery !== '' ? '?'.$canonicalQuery : '');
        try {
            return $http->send($method, $url, [
                'headers' => $headers,
                'body' => $opts['body'] ?? null,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException(self::friendlyNetworkError($e), 0, $e);
        }
    }

    /** 从 S3 错误响应提取可读原因 */
    private static function s3ErrorMessage($res): string
    {
        $xml = @simplexml_load_string((string) $res->body());
        $code = $xml !== false ? (string) $xml->Code : '';
        $message = $xml !== false ? (string) $xml->Message : '';

        return trim($code.' '.$message);
    }

    public static function testS3(): string
    {
        if (! self::s3Configured()) {
            throw new RuntimeException('请先填写 S3 端点与桶名');
        }
        $bucket = trim((string) self::config()['remote']['s3']['bucket']);
        $res = self::s3Request('GET', '', ['list-type' => '2', 'prefix' => self::s3Prefix(), 'max-keys' => '1']);
        if ($res->status() === 200) {
            return '连接成功：桶 '.$bucket;
        }
        if ($res->status() === 403) {
            throw new RuntimeException('S3 鉴权失败（HTTP 403），请检查 AccessKey/SecretKey');
        }
        if ($res->status() === 404) {
            throw new RuntimeException('S3 桶不存在（HTTP 404），请检查桶名、端点与区域');
        }
        throw new RuntimeException('S3 连接失败（HTTP '.$res->status().'）：'.mb_substr(self::s3ErrorMessage($res), 0, 120));
    }

    private static function s3Upload(string $localPath, string $name): void
    {
        $key = self::s3Prefix().$name;
        $payloadHash = hash_file('sha256', $localPath);
        if ($payloadHash === false) {
            throw new RuntimeException('无法读取备份文件');
        }
        $fh = fopen($localPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('无法读取备份文件');
        }
        try {
            $res = self::s3Request('PUT', $key, [], ['payload_hash' => $payloadHash, 'body' => $fh, 'timeout' => 1800]);
            if (! in_array($res->status(), [200, 201, 204], true)) {
                throw new RuntimeException('S3 上传失败（HTTP '.$res->status().'）：'.mb_substr(self::s3ErrorMessage($res), 0, 120));
            }
        } finally {
            fclose($fh);
        }
    }

    private static function s3List(): array
    {
        $res = self::s3Request('GET', '', ['list-type' => '2', 'prefix' => self::s3Prefix(), 'max-keys' => '1000']);
        if ($res->status() !== 200) {
            throw new RuntimeException('S3 列表获取失败（HTTP '.$res->status().'）：'.mb_substr(self::s3ErrorMessage($res), 0, 120));
        }
        $list = [];
        $xml = @simplexml_load_string((string) $res->body());
        if ($xml !== false) {
            foreach ($xml->Contents ?? [] as $c) {
                $basename = basename((string) $c->Key);
                if (! str_starts_with($basename, self::FILE_PREFIX)) {
                    continue;
                }
                $list[] = [
                    'name' => $basename,
                    'size' => (int) $c->Size,
                    'mtime' => date('Y-m-d H:i:s', strtotime((string) $c->LastModified) ?: time()),
                ];
            }
        }
        usort($list, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $list;
    }

    private static function s3Fetch(string $name): string
    {
        $tmp = self::ensureBackupDir().'/tmp-'.Str::random(8).'.zip';
        $res = self::s3Request('GET', self::s3Prefix().$name, [], ['sink' => $tmp, 'timeout' => 1800]);
        if ($res->status() !== 200) {
            @unlink($tmp);
            throw new RuntimeException("S3 下载失败（HTTP {$res->status()}）：{$name}");
        }

        return $tmp;
    }

    private static function s3Delete(string $name): void
    {
        $res = self::s3Request('DELETE', self::s3Prefix().$name);
        if (! in_array($res->status(), [200, 202, 204, 404], true)) {
            throw new RuntimeException("S3 删除失败（HTTP {$res->status()}）：{$name}");
        }
    }

    // ==================== 内部工具 ====================

    /** 备份存储目录（storage/app/backups，含上传中转临时文件） */
    public static function backupDir(): string
    {
        return self::ensureBackupDir();
    }

    private static function businessTables(): array
    {
        $driver = DB::connection()->getDriverName();
        $tables = $driver === 'sqlite'
            ? array_map(
                fn ($r) => (string) (is_object($r) ? $r->name : $r['name']),
                DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            )
            : array_map('reset', DB::select('SHOW TABLES'));

        return array_values(array_diff($tables, self::SKIP_TABLES));
    }

    /** 单表导出为 JSONL（每行一条 JSON），返回行数 */
    private static function dumpTable(string $table, string $dir): int
    {
        $path = $dir.'/'.$table.'.jsonl';
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new RuntimeException("无法写入 {$table}.jsonl");
        }
        $count = 0;
        try {
            $query = DB::table($table);
            $writer = function ($rows) use ($fh, &$count) {
                foreach ($rows as $row) {
                    fwrite($fh, json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                    $count++;
                }
            };
            if (Schema::hasColumn($table, 'id')) {
                $query->chunkById(500, fn ($rows) => $writer($rows), 'id');
            } else {
                // 无自增主键的小表（如令牌/缓存类兜底）直接整表读取
                $writer($query->get()->all());
            }
        } finally {
            fclose($fh);
        }

        return $count;
    }

    /** 复制 storage/app 下全部私有文件（排除备份目录自身），返回 [文件数, 总字节] */
    private static function copyStorageFiles(string $dest): array
    {
        $src = storage_path('app');
        $count = 0;
        $bytes = 0;
        foreach (self::filesIn($src) as $f) {
            $rel = substr($f, strlen($src) + 1);
            if (str_starts_with($rel, 'backups/')) {
                continue;
            }
            $target = $dest.'/'.$rel;
            $targetDir = dirname($target);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            copy($f, $target);
            $count++;
            $bytes += filesize($f) ?: 0;
        }

        return [$count, $bytes];
    }

    private static function filesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        return array_map('strval', array_keys(iterator_to_array($rii)));
    }

    private static function ensureBackupDir(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private static function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (self::filesIn($dir) as $f) {
            @unlink($f);
        }
        foreach (array_reverse(glob($dir.'/*', GLOB_ONLYDIR) ?: []) as $d) {
            @rmdir($d);
        }
        @rmdir($dir);
    }

    private static function versionInfo(): array
    {
        $info = ['commit' => '', 'message' => '', 'version' => ''];
        $file = base_path('version.json');
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $info['commit'] = (string) ($data['commit'] ?? '');
                $info['message'] = (string) ($data['message'] ?? '');
            }
        }
        // make-release 会把 CHANGELOG.md 放进后端站点，取顶部版本号
        $changelog = base_path('CHANGELOG.md');
        if (is_file($changelog)) {
            $head = (string) file_get_contents($changelog, false, null, 0, 400);
            if (preg_match('/v\d+\.\d+\.\d+/', $head, $m)) {
                $info['version'] = $m[0];
            }
        }

        return $info;
    }

    private static function updateStatus(array $status): void
    {
        $setting = AppSetting::oldest('id')->firstOrCreate([]);
        $backup = array_merge(self::config(), ['status' => $status]);
        $setting->update(['backup' => $backup]);
    }

    private static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).'MB'
            : round($bytes / 1024, 1).'KB';
    }
}
