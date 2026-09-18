<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 立即生成一份本地备份快照（不做定时判断）。
 *
 * 与 `backup:run` 的区别：那个是调度器用的，会判断「自动备份是否启用 / 是否已到点 /
 * 今天是否已跑过」，所以在升级前调用它通常是直接跳过 —— 起不到回滚点的作用。
 * 这个命令只做一件事：不管配置如何，立刻产出一份本地快照。
 *
 * 用在两个地方：在线升级覆盖代码之前（update.sh），以及运维手工造回滚点。
 */
class BackupSnapshot extends Command
{
    protected $signature = 'backup:snapshot {--label=升级前 : 快照标签（会写进备份包的 manifest）}';

    protected $description = '立即生成一份本地备份快照，用于升级/恢复等高风险操作前的回滚点';

    public function handle(): int
    {
        // 与定时备份/恢复共用同一把锁：避免在备份或恢复进行中再叠一个全量导出
        $lock = Cache::lock('backup:run', 7200);
        if (! $lock->get()) {
            $this->error('跳过：另一个备份/恢复任务正在执行');

            return self::FAILURE;
        }

        try {
            $label = (string) ($this->option('label') ?: '快照');
            // 只落本地：升级/恢复都是本机操作，本地快照即可回滚，不必把整库数据再传一份到远端
            $result = BackupService::create($label, uploadRemote: false);
            $this->line(sprintf(
                '已生成快照 %s（表 %d 张 / %d 行）',
                $result['file'],
                $result['tables'],
                $result['rows']
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('快照生成失败：'.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
