<?php

namespace Database\Seeders;

use App\Http\Controllers\PayrollController;
use App\Models\PayrollProfile;
use App\Support\PayrollMoney;
use App\Support\PayrollRoles;
use App\Support\XlsxReader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 薪酬档案初始化（从交接包的人员主档导入）。
 *
 * ## 为什么是 seeder 而不是 artisan 命令
 *
 * 选 seeder 的理由：这份数据是**结构性初始化数据**（55 人的岗位/底薪/课时费基线），
 * 与 `YimaiSeeder` 同性质 —— 新环境装完就该有。seeder 可以：
 * 1. 被 `DatabaseSeeder::call()` 串起来，`php artisan db:seed` 一条命令搞定；
 * 2. 通过 `$this->command` 直接输出进度与警告；
 * 3. 天然幂等（按主档 `人员编号` → `external_id` 定位已有行后更新），重复跑不产生重复行，
 *    符合「迁移幂等可重跑」的要求。
 *
 * 一次性 artisan 命令更适合「必须人工触发、且可能被反复执行于不同数据源」的运维动作；
 * 而这里的数据源是**随包发布的固定主档文件**，属初始化而非运维。
 *
 * ## 🔴 高敏数据：文件不随仓库发布
 *
 * 人员主档含**员工姓名、岗位、底薪、课时费**，属高敏数据，**不得复制进仓库**。
 * 所以本 seeder **只接受外部路径**（默认取环境变量 `PAYROLL_MASTER_XLSX`），
 * 文件不在仓库里，也不在 `database/` 下。
 *
 * 生产环境提供方式：
 * 1. 把 `一麦工资人员系统主档_全中文_v2_20260719.xlsx` 上传到**仓库目录之外**（如
 *    `/www/wwwroot/yimai/shared/一麦工资人员系统主档_全中文_v2_20260719.xlsx`）——
 *    放仓库内会被 `rsync` 覆盖、也可能被误提交；
 * 2. 在 `backend/.env` 里写一行：
 *    `PAYROLL_MASTER_XLSX=/www/wwwroot/yimai/shared/一麦工资人员系统主档_全中文_v2_20260719.xlsx`
 * 3. 执行 `php artisan db:seed --class=PayrollProfileSeeder`（可重复执行，幂等）。
 *
 * 用环境变量而不是命令行参数，是因为 seeder 的参数只能通过 `db:seed` 的 `--class`
 * 选类，没有自定义选项的口子；`--path` 是「种子目录」参数，与此无关，别用它传文件路径。
 *
 * **文件缺失时不报错、不阻断**：只回一条 warning 并跳过 —— 否则 CI / 新装环境
 * 会因为「没有高敏文件」而整个 `db:seed` 失败。档案可以在人员管理页逐条维护，
 * 初始化只是省事。
 *
 * ## 🔴 上线检查清单（必须做，漏做不会自己暴露）
 *
 * 「缺失只 warning」这个降级是必要的（否则 CI / 新装环境会因没有高敏文件而整个
 * `db:seed` 失败），但它与「没有文档」组合起来会把坑留到上线当天：
 * 漏配 `PAYROLL_MASTER_XLSX` 时日志里只有一条容易忽略的 warning，而「薪酬计算」页
 * 是**空的**。所以部署后请逐条确认：
 *
 * 1. `.env` 里 `PAYROLL_MASTER_XLSX` 已指向仓库外的真实文件路径（参照 `.env.example`）；
 * 2. 执行 `php artisan db:seed --class=PayrollProfileSeeder`；
 * 3. **确认薪酬档案数 = 55**，两种等价方式：
 *    - 看输出里的 `现有 55 人`（**看总数，别看「新建」**：重复执行时新建为 0，属正常）；
 *    - 或 `php artisan tinker --execute="echo App\Models\PayrollProfile::count();"`
 *    得到 `55`。
 *    **这是唯一可靠的验收信号** —— 该失败是静默的，不做这一步就发现不了。
 * 4. 顺带确认末行打出「业绩表 11 个销售员列名全部可解析 ✅」——
 *    缺别名不会报错，但会让业绩导入时 9 个人直接归零。
 *
 * 排查：若第 3 步得到 0，依次看 ① `php artisan tinker` 里 `env('PAYROLL_MASTER_XLSX')`
 * 是否有值（`config:cache` 之后 `.env` 改动不生效，需 `php artisan config:clear`）；
 * ② 该路径在 **php 进程用户**下是否可读（宝塔常因 open_basedir / 属主不同而读不到）；
 * ③ 服务器是否装了 `php_zip`（缺了会抛「服务器 PHP 缺少 zip 扩展」）。
 *
 * ## 自动化测试在无主档文件时会 skip（属预期行为，不是故障）
 *
 * `PayrollProfileSeederTest` 与 `PayrollEndToEndTest` 依赖两个**仓库外**的高敏文件
 * （`PAYROLL_MASTER_XLSX` / `PAYROLL_PERFORMANCE_SAMPLE`）。文件不存在时它们调
 * `markTestSkipped` 并说明原因 —— 这是**有意设计**：
 * - 不把高敏文件（含员工底薪、客户姓名与金额）复制进仓库来「修好」它，
 *   那违反交接包的保密边界；
 * - 因此 CI 上这两组用例显示 `skipped` 是正常的，**不要当成坏掉**。
 *
 * 关键口径并未因此失去覆盖：`PayrollPerformanceImportTest` 用**自建 xlsx 夹具**
 * 独立验证了 299 剔除（104 行 / 31,096.00）、提点口径（344,767.50 / 364,167.20）、
 * 提成合计 21,068.10、11 个销售员列名解析、苏米→罗柳柳、门店 2% 等基准，**不依赖临时文件**。
 */
class PayrollProfileSeeder extends Seeder
{
    private const SHEET_MASTER = '人员主档';

    private const SHEET_ALIAS = '姓名别名';

    private const SHEET_RULES = '特殊规则';

    public function run(): void
    {
        $path = (string) (env('PAYROLL_MASTER_XLSX') ?: '');
        if ($path === '') {
            $this->command?->warn(
                '未提供人员主档文件（环境变量 PAYROLL_MASTER_XLSX 为空），跳过薪酬档案初始化。'
                .'薪酬档案可在「薪酬计算」页面逐条维护。'
            );

            return;
        }
        if (! is_file($path)) {
            $this->command?->warn("人员主档文件不存在：{$path}，跳过薪酬档案初始化。");

            return;
        }

        $reader = XlsxReader::open($path);
        try {
            if (! $reader->hasSheet(self::SHEET_MASTER)) {
                throw new RuntimeException('人员主档缺少 sheet「'.self::SHEET_MASTER.'」');
            }

            $rows = $this->readMasterSheet($reader);
            $dualBase = $this->readDualBaseWhitelist($reader);
            $aliases = $this->readAliasSheet($reader);

            $externalToProfile = [];
            $created = 0;
            $updated = 0;
            $unbound = [];

            DB::transaction(function () use ($rows, $dualBase, &$externalToProfile, &$created, &$updated, &$unbound) {
                // 账号绑定计划：**按姓名整组**一次算好，而不是在下面循环里逐行决定。
                //
                // 规则唯一实现在 `PayrollController::distributeUserAccounts()`；
                // 必须整组算的原因：主档里**同一姓名可能有多行**（跨店老师两店各一行），
                // 而 `payroll_profiles.user_id` 有 unique 约束。逐行「同名唯一就写 user_id」
                // 会让第二行把同一个 user_id 再写一次 ⇒ SQLSTATE 23000 / 1062 ⇒
                // 整个 `db:seed` 事务回滚。这与「系统已知信息预填」500 是**同一个缺陷**，
                // 因此共用同一处实现，不在 seeder 里保留第三份复制。
                //
                // 又必须在**事务内**算：`distributeUserAccounts()` 用 `lockForUpdate()`
                // 判「该账号是否已被本表别行占用」，事务外这层锁不成立。
                $plan = $this->planBindings($rows);

                foreach ($rows as $row) {
                    $existing = PayrollProfile::where('external_id', $row['external_id'])->first();
                    $profile = $existing ?? new PayrollProfile;
                    if ($existing === null) {
                        $created++;
                    } else {
                        $updated++;
                    }

                    $profile->fill([
                        'external_id' => $row['external_id'],
                        'name' => $row['name'],
                        'venue' => $row['venue'],
                        'role' => $row['role'],
                        'base_salary' => $row['base_salary'],
                        'performance' => $row['performance'],
                        'fee_private60' => $row['fee_private60'],
                        'fee_private45' => $row['fee_private45'],
                        'fee_small' => $row['fee_small'],
                        'fee_group' => $row['fee_group'],
                        'fee_enterprise' => $row['fee_enterprise'],
                        'dual_base_salary' => in_array($row['name'], $dualBase, true),
                        'status' => $row['status'],
                        'alert' => $row['alert'],
                        'account_status' => $row['account_status'],
                        'note' => $row['note'],
                    ]);
                    // 账号绑定：取循环外算好的计划（同一处实现的规则）。
                    // 已有绑定的行计划值 = 现值 ⇒ 不改绑、不解绑（重复跑幂等）。
                    $decision = $plan[$row['external_id']] ?? ['userId' => null, 'reason' => null];
                    $profile->user_id = $decision['userId'];
                    $profile->note = PayrollController::composeNote((string) $row['note'], $decision['reason']);
                    PayrollController::saveProfileGuardingUserId($profile, (string) $row['note']);
                    if ($decision['userId'] === null && $decision['reason'] !== null) {
                        $unbound[] = "{$row['name']}（{$row['venue']}）：{$decision['reason']}";
                    }
                    $externalToProfile[$row['external_id']] = $profile->id;
                }
            });

            // 未绑定不是静默事件：主档里同一姓名有多行时，只有一行能拿到账号，
            // 其余行必须把原因打出来（页面/日志都要能看到）
            foreach ($unbound as $line) {
                $this->command?->warn('账号未绑定：'.$line);
            }
            if ($unbound !== []) {
                $this->command?->info('账号绑定：'.count($rows).' 行中 '.count($unbound).' 行未绑定（原因见上）');
            }

            // 别名：主档「姓名别名」sheet（103 条）。**必须在档案建好之后导入**，
            // 否则业绩导入时 9 个销售员列名全部解析不出来、直接归零。
            $aliasCount = 0;
            $skipped = [];
            DB::transaction(function () use ($aliases, $externalToProfile, &$aliasCount, &$skipped) {
                // 一个名字只能对一个人：先把「已被别的人员占用」的名字找出来整体跳过，
                // 而不是覆盖 —— 覆盖会让上一次的归属静默改人
                $ownerOf = [];
                foreach (PayrollProfile::all(['id', 'name', 'aliases']) as $p) {
                    foreach ((array) ($p->aliases ?? []) as $a) {
                        $ownerOf[trim((string) $a)] = (int) $p->id;
                    }
                }
                $byProfile = [];
                foreach ($aliases as $alias => $externalId) {
                    $profileId = $externalToProfile[$externalId] ?? null;
                    if ($profileId === null) {
                        $skipped[] = $alias;
                        continue;
                    }
                    if (isset($ownerOf[$alias]) && $ownerOf[$alias] !== (int) $profileId) {
                        $skipped[] = $alias;
                        continue;
                    }
                    $byProfile[$profileId][] = $alias;
                }
                foreach ($byProfile as $profileId => $list) {
                    $profile = PayrollProfile::find($profileId);
                    if ($profile === null) {
                        continue;
                    }
                    // 本名不用登记（解析时本名恒算在内）
                    $list = array_values(array_filter($list, fn ($a) => $a !== $profile->name));
                    $profile->aliases = array_values(array_unique($list));
                    $profile->save();
                    $aliasCount += count($profile->aliases);
                }
            });

            // 打出**档案总数**，而不只是本次增量：重复执行时 `$created` 是 0，
            // 只看「新建 0」会误判成失败。总数才是上线检查清单第 3 步的可靠信号。
            $total = PayrollProfile::count();
            $this->command?->info("薪酬档案：新建 {$created}、更新 {$updated}，现有 {$total} 人，别名 {$aliasCount} 条");
            if ($total !== count($rows)) {
                $this->command?->warn(
                    "⚠️ 档案总数 {$total} 与主档行数 ".count($rows).' 不一致：'
                    .'可能库里还有主档之外的历史人员（属正常），也可能是同名/编号冲突被覆盖，请人工核对'
                );
            }
            if ($skipped !== []) {
                $this->command?->warn('以下别名被跳过（已属于他人或对不上档案）：'.implode('、', array_slice($skipped, 0, 20)));
            }
            $this->reportUnmatchedSalesColumns();
        } finally {
            $reader->close();
        }
    }

    /**
     * 为整批主档行算出「人员编号 => 绑定结果」。
     *
     * 规则**不在本文件里重写**：`PayrollController::accountsByNames()` 取同名账号，
     * `PayrollController::distributeUserAccounts()` 做分配（含「同名多个账号不猜」
     * 「一个账号只能绑一行」「同店那行优先」「已被别行占用则不抢」四条）。
     *
     * @param  array<int, array{external_id: string, name: string, venue: string}>  $rows
     * @return array<string, array{userId: ?int, reason: ?string}> 人员编号 => 绑定结果
     */
    private function planBindings(array $rows): array
    {
        // 已有档案的 id 与现绑定值要带进计划：规则 1「已绑定的行原样保留」靠它，
        // 也是「重复执行 seeder 幂等」的根。
        $existing = [];
        foreach (PayrollProfile::whereNotNull('external_id')
            ->get(['id', 'external_id', 'user_id']) as $p) {
            $existing[(string) $p->external_id] = $p;
        }

        $byName = [];
        $rowByExternalId = [];
        foreach ($rows as $row) {
            $externalId = (string) $row['external_id'];
            $rowByExternalId[$externalId] = $row;
            $byName[$row['name']][] = $externalId;
        }
        if ($byName === []) {
            return [];
        }
        $accounts = PayrollController::accountsByNames(array_keys($byName));

        $plan = [];
        foreach ($byName as $name => $externalIds) {
            $group = [];
            foreach ($externalIds as $externalId) {
                $row = $rowByExternalId[$externalId] ?? null;
                $p = $existing[$externalId] ?? null;
                $group[$externalId] = [
                    'venue' => (string) ($row['venue'] ?? ''),
                    'currentUserId' => ($p === null || $p->user_id === null) ? null : (int) $p->user_id,
                    'profileId' => $p === null ? null : (int) $p->id,
                ];
            }
            foreach (PayrollController::distributeUserAccounts((string) $name, $group, $accounts[$name] ?? []) as $externalId => $decision) {
                $plan[(string) $externalId] = $decision;
            }
        }

        return $plan;
    }

    /** 读「人员主档」sheet（表头第 1 行，15 列） */
    private function readMasterSheet(XlsxReader $reader): array
    {
        $header = null;
        $out = [];
        foreach ($reader->rows(self::SHEET_MASTER) as $rowNumber => $cells) {
            if ($rowNumber === 1) {
                foreach ($cells as $col => $name) {
                    $header[$col] = trim((string) $name);
                }
                continue;
            }
            if ($header === null || $cells === []) {
                continue;
            }
            $get = function (string $colName) use ($header, $cells) {
                foreach ($header as $col => $name) {
                    if ($name === $colName) {
                        return $cells[$col] ?? null;
                    }
                }

                return null;
            };
            $externalId = trim((string) $get('人员编号'));
            $name = trim((string) $get('真实姓名'));
            if ($externalId === '' || $name === '') {
                continue;
            }
            $role = PayrollRoles::normalizeRole((string) $get('岗位'));
            if (! PayrollRoles::isValidRole($role)) {
                // 主档出现枚举外的岗位时**不静默塞进档案**：记为全职老师会让口径悄悄变掉，
                // 不如明确报出来让人补枚举
                $this->command?->warn("人员 {$name} 的岗位「{$get('岗位')}」不在枚举内，已跳过该行");
                continue;
            }
            $status = trim((string) $get('人员状态'));
            if ($status === '') {
                $status = '有效';
            }
            $out[] = [
                'external_id' => mb_substr($externalId, 0, 32),
                'name' => mb_substr($name, 0, 60),
                'venue' => trim((string) $get('所属门店')),
                'role' => $role,
                'base_salary' => PayrollMoney::fmt(PayrollMoney::cents($get('底薪'))),
                'performance' => PayrollMoney::fmt(PayrollMoney::cents($get('绩效'))),
                'fee_private60' => PayrollMoney::fmt(PayrollMoney::cents($get('60分钟课时费'))),
                'fee_private45' => PayrollMoney::fmt(PayrollMoney::cents($get('45分钟课时费'))),
                'fee_small' => PayrollMoney::fmt(PayrollMoney::cents($get('小班'))),
                'fee_group' => PayrollMoney::fmt(PayrollMoney::cents($get('团课'))),
                'fee_enterprise' => PayrollMoney::fmt(PayrollMoney::cents($get('企业课'))),
                'alert' => mb_substr(trim((string) $get('重点提醒')), 0, 200),
                'account_status' => mb_substr(trim((string) $get('账户确认状态')), 0, 60),
                'status' => mb_substr($status, 0, 16),
                'note' => '',
            ];
        }

        return $out;
    }

    /** 读「特殊规则」sheet 的 dual_base_salary 白名单（实测 3 人：蒙澍南/谭婷婷/张卫玉） */
    private function readDualBaseWhitelist(XlsxReader $reader): array
    {
        if (! $reader->hasSheet(self::SHEET_RULES)) {
            return PayrollRoles::DUAL_BASE_SALARY_WHITELIST;
        }
        $header = null;
        $names = [];
        foreach ($reader->rows(self::SHEET_RULES) as $rowNumber => $cells) {
            if ($rowNumber === 1) {
                foreach ($cells as $col => $name) {
                    $header[$col] = trim((string) $name);
                }
                continue;
            }
            if ($header === null) {
                continue;
            }
            $get = function (string $colName) use ($header, $cells) {
                foreach ($header as $col => $name) {
                    if ($name === $colName) {
                        return $cells[$col] ?? null;
                    }
                }

                return null;
            };
            if (trim((string) $get('状态')) !== '有效') {
                continue;
            }
            if (trim((string) $get('规则类型')) !== 'dual_base_salary') {
                continue;
            }
            $n = trim((string) $get('真实姓名'));
            if ($n !== '') {
                $names[] = $n;
            }
        }

        return $names !== [] ? $names : PayrollRoles::DUAL_BASE_SALARY_WHITELIST;
    }

    /** @return array<string, string> 别名 => 人员编号 */
    private function readAliasSheet(XlsxReader $reader): array
    {
        if (! $reader->hasSheet(self::SHEET_ALIAS)) {
            return [];
        }
        $header = null;
        $out = [];
        foreach ($reader->rows(self::SHEET_ALIAS) as $rowNumber => $cells) {
            if ($rowNumber === 1) {
                foreach ($cells as $col => $name) {
                    $header[$col] = trim((string) $name);
                }
                continue;
            }
            if ($header === null) {
                continue;
            }
            $get = function (string $colName) use ($header, $cells) {
                foreach ($header as $col => $name) {
                    if ($name === $colName) {
                        return $cells[$col] ?? null;
                    }
                }

                return null;
            };
            $alias = trim((string) $get('姓名别名'));
            $externalId = trim((string) $get('人员编号'));
            $status = trim((string) $get('状态'));
            if ($alias === '' || $externalId === '') {
                continue;
            }
            if ($status !== '' && $status !== '有效') {
                continue;
            }
            $out[mb_substr($alias, 0, 60)] = $externalId;
        }

        return $out;
    }

    /**
     * 自检：业绩表 11 个销售员列名是否都能解析。
     *
     * 不解析这 9 个别名，导入结果里就有 9 个人直接归零 —— 这是最容易静默失败的环节，
     * 所以在 seeder 结束时主动验一遍并把结果打出来。
     */
    private function reportUnmatchedSalesColumns(): void
    {
        $columns = ['苏米', '娟子', '张芷晴', '钱冰璐', 'Nico', 'CC', '黄敏', 'Lily', '小鹏', '婷婷', '阿玉'];
        $resolver = new \App\Services\PayrollNameResolver;
        $missing = [];
        foreach ($columns as $c) {
            if ($resolver->resolve($c) === null) {
                $missing[] = $c;
            }
        }
        if ($missing === []) {
            $this->command?->info('业绩表 11 个销售员列名全部可解析 ✅');

            return;
        }
        $this->command?->warn(
            '业绩表列名无法解析（这些人会归零）：'.implode('、', $missing)
            .' —— 请在薪酬档案里补别名'
        );
    }
}
