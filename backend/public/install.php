<?php
/**
 * 一麦工作台 · 宝塔首次安装向导
 *
 * 使用：解压上传后，浏览器访问 http://你的API域名/install.php
 * 流程：环境检测 → 填数据库（+随心瑜凭据，可跳过）→ 自动建库/导入数据 → 完成
 *
 * 安全：安装完成后生成 storage/install.lock；生产环境安装后必须删除本文件。
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$LOCK = $ROOT . '/storage/install.lock';
$ENV  = $ROOT . '/.env';

function ym_ok(bool $b): string { return $b ? '<span style="color:#1d9a5b">✔ 通过</span>' : '<span style="color:#d43c33">✘ 不满足</span>'; }
function ym_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ym_post(string $k, string $d = ''): string { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }

// 已安装则拒绝
if (is_file($LOCK)) {
    http_response_code(403);
    exit('<meta charset="utf-8"><body style="font-family:sans-serif;padding:40px;line-height:2">系统已安装完成。如需重装请删除 <b>backend/storage/install.lock</b></body>');
}

$checks = [
    'PHP 版本 ≥ 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO MySQL 扩展' => extension_loaded('pdo_mysql'),
    'mbstring 扩展' => extension_loaded('mbstring'),
    'openssl 扩展' => extension_loaded('openssl'),
    'fileinfo 扩展' => extension_loaded('fileinfo'),
    'storage 目录可写' => is_writable($ROOT . '/storage'),
    'bootstrap/cache 可写' => is_writable($ROOT . '/bootstrap/cache'),
];
$envWritable = !file_exists($ENV) || is_writable($ENV);
$allPass = !in_array(false, $checks, true) && $envWritable;

$result = '';
$log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allPass && ym_post('action') === 'install') {
    $dbHost = ym_post('db_host', '127.0.0.1');
    $dbPort = ym_post('db_port', '3306');
    $dbName = ym_post('db_name', 'yimai');
    $dbUser = ym_post('db_user');
    $dbPass = $_POST['db_pass'] ?? '';
    $kyPhone = ym_post('ky_phone');
    $kyPass  = ym_post('ky_password');
    $adminUser = ym_post('admin_user', 'admin');
    $adminPass = (string) ($_POST['admin_password'] ?? '');
    if (! preg_match('/^[A-Za-z][A-Za-z0-9_.-]{2,31}$/', $adminUser)) {
        $log[] = '超管账号必须是 3-32 位字母开头的字母、数字或 _.- 组合';
        $result = 'FAIL';
    } elseif (strlen($adminPass) < 12) {
        $log[] = '超管密码至少需要 12 位';
        $result = 'FAIL';
    }

    if ($result !== 'FAIL') try {
        // 1. 连接并建库（无建库权限时允许使用面板预建的库）
        try {
            $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $log[] = "数据库 `{$dbName}` 连接成功";
        } catch (PDOException $e) {
            // 尝试直接连目标库（账号无建库权限但库已在面板创建）
            $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
            $log[] = "数据库 `{$dbName}` 已存在，直接使用";
        }

        // 2. 写 .env
        $key = 'base64:' . base64_encode(random_bytes(32));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $appUrl = "{$scheme}://{$host}";

        $env = "# 一麦工作台（由 install.php 于 " . date('Y-m-d H:i') . " 生成）\n";
        foreach ([
            'APP_NAME' => '"一麦工作台"', 'APP_ENV' => 'production', 'APP_KEY' => $key,
            'APP_DEBUG' => 'false', 'APP_URL' => $appUrl,
            'APP_LOCALE' => 'zh_CN', 'APP_FALLBACK_LOCALE' => 'en', 'APP_TIMEZONE' => 'Asia/Shanghai',
            'LOG_CHANNEL' => 'daily', 'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $dbHost, 'DB_PORT' => $dbPort,
            'DB_DATABASE' => $dbName, 'DB_USERNAME' => $dbUser, 'DB_PASSWORD' => $dbPass,
            'SESSION_DRIVER' => 'database', 'SESSION_LIFETIME' => '120',
            'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database',
            'FILESYSTEM_DISK' => 'local',
        ] as $k => $v) {
            $env .= "{$k}={$v}\n";
        }
        if ($kyPhone !== '') {
            $env .= "\nKY_PHONE={$kyPhone}\nKY_PASSWORD={$kyPass}\n";
        }
        file_put_contents($ENV, $env);
        chmod($ENV, 0640);
        $log[] = '.env 配置已写入';

        // 3. 引导框架执行迁移 + 种子
        require $ROOT . '/vendor/autoload.php';
        $app = require $ROOT . '/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Artisan::call('migrate', ['--force' => true]);
        $mOut = trim(Artisan::output());
        if (stripos($mOut, 'DONE') === false && stripos($mOut, 'FAIL') !== false) {
            throw new RuntimeException('迁移失败: ' . mb_substr($mOut, -300));
        }
            \App\Models\User::updateOrCreate(
                ['username' => $adminUser],
                [
                    'name' => $adminUser,
                    'password' => \Illuminate\Support\Facades\Hash::make($adminPass),
                    'role' => 'R_SUPER',
                    'venue' => null,
                    'venues' => ['绿地店', '东部店'],
                    'email' => $adminUser.'@local.invalid',
                    'email_verified_at' => now(),
                ]
            );
        $log[] = '数据库表结构与初始超管账号创建完成';

        // 4. 上锁
        file_put_contents($LOCK, 'installed at ' . date('c'));
        $result = 'OK';
    } catch (Throwable $e) {
        $result = 'FAIL';
        $log[] = '错误：' . $e->getMessage();
        if (isset($mOut)) $log[] = mb_substr($mOut, -400);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>一麦工作台 · 安装程序</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,'PingFang SC','Microsoft YaHei',sans-serif;background:#f4f6f5;color:#233;min-height:100vh;display:flex;justify-content:center;padding:40px 16px}
.wrap{width:100%;max-width:680px}
.card{background:#fff;border-radius:14px;padding:32px;box-shadow:0 2px 16px rgba(29,92,66,.08);margin-bottom:20px}
h1{font-size:22px;color:#1d5c43;display:flex;align-items:center;gap:10px;margin-bottom:6px}
.sub{font-size:13px;color:#7a8a84;margin-bottom:24px}
h2{font-size:15px;color:#1d5c43;margin:18px 0 10px}
table.checks{width:100%;border-collapse:collapse;font-size:14px}
table.checks td{padding:8px 4px;border-bottom:1px dashed #e4eae7}
label{display:block;font-size:13px;color:#556;margin:12px 0 4px}
input[type=text],input[type=password]{width:100%;padding:10px 12px;border:1px solid #d5ded9;border-radius:8px;font-size:14px;background:#fbfdfc}
input:focus{outline:none;border-color:#2f7d5d}
.row{display:flex;gap:12px}.row>div{flex:1}
button{width:100%;margin-top:24px;padding:13px;border:none;border-radius:9px;background:linear-gradient(135deg,#2f7d5d,#1d5c43);color:#fff;font-size:15px;cursor:pointer;font-weight:600}
button:hover{opacity:.92}
button:disabled{background:#aab5b0;cursor:not-allowed}
.log{background:#12211b;color:#9fe3bd;border-radius:9px;padding:14px;font-family:Menlo,Consolas,monospace;font-size:12px;line-height:1.8;white-space:pre-wrap;word-break:break-all}
.tip{font-size:12px;color:#8a9691;line-height:1.8}
.ok-box{text-align:center;padding:30px 0}
.ok-box h2{font-size:19px;color:#1d9a5b;margin-bottom:14px}
.acc{font-size:13.5px;line-height:2.1;text-align:left;display:inline-block}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>🌿 一麦工作台 · 安装向导</h1>
    <div class="sub">双店内部经营与服务执行平台 · 首次安装</div>

<?php if ($result === 'OK'): ?>
    <div class="ok-box">
      <h2>✔ 安装完成</h2>
      <div class="acc">
        <b>前端访问</b>：前端站点域名（dist 已部署）<br>
        <b>接口地址</b>：<?= ym_h($appUrl) ?><br>
        <b>超管账号</b>：<?= ym_h($adminUser) ?><br>
        <span style="color:#d43c33">安全提示：安装后请立即删除本文件（public/install.php），并妥善保存刚设置的密码。</span>
      </div>
    </div>
    <pre class="log"><?= ym_h(implode("\n", $log)) ?></pre>

<?php elseif ($result === 'FAIL'): ?>
    <div style="color:#d43c33;font-weight:600;margin-bottom:10px">安装失败，请根据下方日志修正后重试：</div>
    <pre class="log"><?= ym_h(implode("\n", $log)) ?></pre>
    <button onclick="location.reload()">重新填写</button>

<?php else: ?>
     <h2>① 环境检测</h2>
    <table class="checks">
      <?php foreach ($checks as $name => $pass): ?>
      <tr><td><?= ym_h($name) ?></td><td style="text-align:right"><?= ym_ok($pass) ?></td></tr>
      <?php endforeach; ?>
      <tr><td>.env 可写入</td><td style="text-align:right"><?= ym_ok($envWritable) ?></td></tr>
    </table>

<?php if (!$allPass): ?>
    <p class="tip" style="margin-top:14px">宝塔面板 → 软件商店 → 对应 PHP → 设置 → 安装缺失扩展；目录权限用「网站 → 权限」修复为 www 可写。</p>
<?php else: ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="install">
      <h2>② 数据库配置</h2>
      <div class="row">
        <div><label>数据库地址</label><input type="text" name="db_host" value="<?= ym_h(ym_post('db_host','127.0.0.1')) ?>"></div>
        <div style="max-width:110px"><label>端口</label><input type="text" name="db_port" value="<?= ym_h(ym_post('db_port','3306')) ?>"></div>
      </div>
      <label>数据库名（不存在会自动创建）</label>
      <input type="text" name="db_name" value="<?= ym_h(ym_post('db_name','yimai')) ?>">
      <div class="row">
        <div><label>数据库用户名</label><input type="text" name="db_user" value="<?= ym_h(ym_post('db_user')) ?>" placeholder="宝塔创建的数据库名常与用户名相同"></div>
        <div><label>数据库密码</label><input type="password" name="db_pass" value=""></div>
      </div>

      <h2>③ 初始超管账号</h2>
      <div class="row">
        <div><label>登录账号</label><input type="text" name="admin_user" value="<?= ym_h(ym_post('admin_user','admin')) ?>" autocomplete="username"></div>
        <div><label>登录密码（至少12位）</label><input type="password" name="admin_password" value="" autocomplete="new-password"></div>
      </div>

      <h2>④ 随心瑜 KeepYoga（可选，用于会员同步）</h2>
      <div class="row">
        <div><label>登录手机号</label><input type="text" name="ky_phone" value="<?= ym_h(ym_post('ky_phone')) ?>" placeholder="留空跳过"></div>
        <div><label>登录密码</label><input type="password" name="ky_password" value=""></div>
      </div>

      <button type="submit" <?= $allPass ? '' : 'disabled' ?>>开始安装（建库 · 执行迁移）</button>
      <p class="tip" style="margin-top:10px">只执行未运行的数据库迁移，不会清空已有数据。安装完成后自动锁定本向导。</p>
    </form>
<?php endif; endif; ?>
  </div>
</div>
</body>
</html>
