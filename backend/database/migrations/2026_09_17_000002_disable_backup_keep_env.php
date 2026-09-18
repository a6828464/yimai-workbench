<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 存量备份配置里关掉「纳入 .env 凭据」
 *
 * 背景：备份包的定位是"能恢复数据"，而 `.env` 里放的是 APP_KEY（可解密所有加密字段、
 * 伪造 `storage/{path}` 的签名 URL）、数据库口令、随心瑜账号密码与发布用 Token。
 * 备份包会被上传到 WebDAV/S3 这类第三方存储，一旦外泄等于把整站凭据交出去；
 * 而恢复流程只回灌数据表，根本不需要 `.env`。
 *
 * 因此默认值改为关闭（BackupService::defaultConfig），并把已经存进库的 true 一并改掉。
 * 确实需要连 .env 一起备份的场景（换机重建），可以在「数据备份」页重新打开 ——
 * 那里现在有明确的凭据风险提示。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $row = DB::table('app_settings')->orderBy('id')->first();
        if (! $row || ! isset($row->backup)) {
            return; // 没配过备份，走新默认值即可
        }

        $config = json_decode((string) $row->backup, true);
        if (! is_array($config) || ($config['keep_env'] ?? false) !== true) {
            return;
        }

        $config['keep_env'] = false;
        DB::table('app_settings')->where('id', $row->id)->update(['backup' => json_encode($config)]);

        logger()->info('备份配置已关闭 .env 打包（安全默认值调整，可在数据备份页重新开启）');
    }

    public function down(): void
    {
        // 不还原：把凭据重新塞回备份包不是需要回滚的行为
    }
};
