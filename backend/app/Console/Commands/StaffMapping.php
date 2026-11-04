<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StaffDirectory;
use Illuminate\Console\Command;

/**
 * 人员身份归一的运维入口：报告 / 回填 / 认领。
 *
 * 为什么要有命令而不只有接口：
 *  - 部署后要能**一条命令**把所有历史行的归属 id 补齐（接口要点开页面、还要超管登录）；
 *  - 出问题时要能在服务器上直接看「还有哪些名字对不上账号」，不受前端缓存影响；
 *  - 认领历史姓名可以写进发布脚本，避免"升级完还是有人看不到数据"。
 *
 * 与接口共用 `StaffDirectory`（唯一 owner）：命令里不另写一份表清单，
 * 否则加一张表就会出现「页面修好了、命令行没修」的分裂。
 *
 * 用法：
 *   php artisan staff:mapping                          # 报告
 *   php artisan staff:mapping --repair                 # 回填归属 id
 *   php artisan staff:mapping --repair --clear-stale   # 先清悬挂 id 再回填
 *   php artisan staff:mapping --assign="苏小米=sumi"    # 把名字认领到账号（可重复传）
 */
class StaffMapping extends Command
{
    protected $signature = 'staff:mapping
        {--repair : 回填所有归属列里为空的 *_user_id}
        {--clear-stale : 先把指向已删除账号的悬挂 id 置空（配合 --repair）}
        {--assign=* : 认领历史姓名，格式 姓名=登录名，可重复}
        {--json : 以 JSON 输出（给脚本用）}';

    protected $description = '人员身份归一：查看未映射姓名 / 回填归属 id / 认领历史姓名';

    public function handle(StaffDirectory $directory): int
    {
        $assignments = (array) $this->option('assign');
        foreach ($assignments as $pair) {
            $this->claim($directory, (string) $pair);
        }

        $cleared = 0;
        if ($this->option('clear-stale')) {
            $cleared = $directory->clearStale();
            $this->line("已清空悬挂 id：{$cleared} 行");
        }

        $filled = 0;
        if ($this->option('repair') || $cleared > 0 || $assignments !== []) {
            $result = $directory->backfill();
            $filled = $result['filled'];
            $this->line("已回填归属 id：{$filled} 行");
            foreach ($result['byTable'] as $table => $rows) {
                $this->line("  - {$table}: {$rows}");
            }
            // 归属变了，五清单/统计的缓存必须失效，否则页面还要等 120 秒
            invalidateBusinessCaches();
        }

        $coverage = $directory->coverage();
        $unmapped = $directory->unmapped();

        if ($this->option('json')) {
            $this->line(json_encode([
                'backfilled' => $filled,
                'cleared' => $cleared,
                'coverage' => $coverage,
                'unmapped' => $unmapped,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('归属 id 覆盖率');
        $this->table(
            ['归属列', '有姓名行数', '已落 id', '缺 id'],
            array_map(fn ($r) => [$r['label'], $r['total'], $r['withId'], $r['missing']], $coverage)
        );

        if ($unmapped === []) {
            $this->info('所有归属姓名都能对上账号。');

            return self::SUCCESS;
        }

        $this->warn('这些姓名出现在业务数据里，但对不上任何账号（本人看不到这些数据）：');
        $rows = [];
        foreach (array_slice($unmapped, 0, 60) as $item) {
            $rows[] = [
                $item['name'],
                array_sum($item['counts']),
                implode('、', array_keys($item['counts'])),
                $item['suggestions'][0]['name'] ?? '—',
                $item['suggestions'][0]['reason'] ?? '—',
            ];
        }
        $this->table(['姓名', '行数', '出现在', '疑似账号', '依据'], $rows);

        if (count($unmapped) > 60) {
            $this->line('（仅显示前 60 条，完整清单请用 --json）');
        }
        $this->line('认领示例：php artisan staff:mapping --assign="苏小米=sumi"');

        return self::SUCCESS;
    }

    /** 处理一条 `姓名=登录名` 的认领 */
    private function claim(StaffDirectory $directory, string $pair): void
    {
        if (! str_contains($pair, '=')) {
            $this->error("--assign 格式应为 姓名=登录名，收到：{$pair}");

            return;
        }
        [$name, $username] = array_map('trim', explode('=', $pair, 2));
        if ($name === '' || $username === '') {
            $this->error("--assign 的姓名与登录名都不能为空：{$pair}");

            return;
        }
        $user = User::where('username', $username)->first();
        if ($user === null) {
            $this->error("账号不存在：{$username}");

            return;
        }

        try {
            $result = $directory->assign($name, (int) $user->id, 'manual', 'staff:mapping 命令行认领');
            $this->info("已认领「{$result['alias']}」→ {$user->name}（回填 {$result['backfilled']} 行）");
        } catch (\Throwable $e) {
            // abort(422) 抛的是 HttpException：命令行里要转成可读错误，不能让脚本中断
            $this->error("认领「{$name}」失败：".$e->getMessage());
        }
    }
}
