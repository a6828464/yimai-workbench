<?php

namespace Database\Seeders;

use App\Http\Controllers\PayrollController;
use App\Models\PayrollProfile;
use App\Support\PayrollMasterImporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 按**新版**薪酬人员主档（2026-09-24 版，55 人 / 26 列）建立基础档案。
 *
 * ## 与 `PayrollProfileSeeder` 的分工（两者并存，不是替换）
 *
 * | | `PayrollProfileSeeder` | 本 seeder |
 * |---|---|---|
 * | 数据源 | 2026-07 旧交接包（sheet `人员主档`/`姓名别名`/`特殊规则`） | 2026-09 新版主档（sheet `薪酬人员主档`） |
 * | 环境变量 | `PAYROLL_MASTER_XLSX` | `PAYROLL_MASTER_XLSX_V2` |
 * | 双底薪白名单 | 读 `特殊规则` sheet，缺失时回退硬编码 3 人 | 读 `重点提醒` 列文本，**不硬编码姓名** |
 * | 别名 | 独立 sheet | 单列 `所有显示名/昵称`，逗号分隔 |
 *
 * 旧 seeder **不能**吃新版文件（会抛「人员主档缺少 sheet」），所以新增本文件而不是
 * 改造旧的 —— 旧交接包的部署仍在用旧 seeder，两者谁在跑由环境变量决定。
 * 若两个环境变量指向同一批人，先跑的那个建行、后跑的按 `external_id` 更新，
 * 因此**同时配置不会产生重复行**（见幂等用例）。
 *
 * ## 幂等
 *
 * 按主档 `人员编号` → `external_id` 定位既有行后**只覆盖主档已知的字段**：
 * - 主档里**留空的金额**（`null`）不写入 ⇒ 不把用户补录过的金额清成 0；
 * - `user_id` 完全不参与（账号绑定由 t1 的统一规则决定，本 seeder 不碰）；
 * - `note`、`aliases` 之外的字段保持既有值。
 *
 * 因此「导入两次」与「导入一次」结果逐字相同。
 *
 * ## PII 不入库
 *
 * 主档含身份证号/手机/银行卡号/开户行，`payroll_profiles` 表**没有**这些列，
 * 仓库政策也要求不入库。解析侧（`PayrollMasterImporter::FIELDS` 白名单）
 * 从机制上就不取这些列。**缺失的敏感资料不出现在档案里**，只在导入报告里
 * 以「缺几项」的**计数**形式告知，供用户自行补齐。
 */
class PayrollProfileFromMasterSeeder extends Seeder
{
    /** 新版主档路径（**仓库外**高敏文件；与旧 seeder 的 `PAYROLL_MASTER_XLSX` 区分开） */
    public const ENV_PATH = 'PAYROLL_MASTER_XLSX_V2';

    public function run(): void
    {
        $path = (string) (env(self::ENV_PATH) ?: '');
        if ($path === '') {
            $this->command?->warn(
                '未提供新版人员主档（环境变量 '.self::ENV_PATH.' 为空），跳过薪酬档案初始化。'
                .'本 seeder 面向 2026-09 新版主档（sheet「'.PayrollMasterImporter::SHEET.'」）；'
                .'若你手上是 2026-07 旧交接包，请改用 PayrollProfileSeeder。'
            );

            return;
        }
        if (! is_file($path)) {
            $this->command?->warn("新版人员主档文件不存在：{$path}，跳过薪酬档案初始化。");

            return;
        }

        $result = $this->importFromFile($path);
        $this->report($result['stats'], $result['created'], $result['updated'], $result['skippedAliases']);
    }

    /**
     * 从主档文件导入（幂等），返回结果统计 —— 供 `run()` 与
     * `PayrollController::importMaster`（界面直接上传 xlsx）共用同一实现。
     *
     * 这是在 v3.3.6 把落库核心从 run() 抽出来的原因：服务器上不再需要
     * 「手工放置仓库外文件 + 配环境变量 + 跑 db:seed」三步，超管直接在
     * 「人员档案」页上传主档即可；而 env + seeder 的老路径继续可用（CI/本地）。
     *
     * @return array{rows: array, stats: array, created: int, updated: int, skippedAliases: array<int, string>}
     */
    public function importFromFile(string $path): array
    {
        $importer = new PayrollMasterImporter;
        $read = $importer->read($path);
        $rows = $read['rows'];
        $stats = $read['stats'];

        if ($rows === []) {
            $this->command?->warn('新版人员主档里没有可用行，未做任何改动。');

            return ['rows' => $rows, 'stats' => $stats, 'created' => 0, 'updated' => 0, 'skippedAliases' => []];
        }

        $created = 0;
        $updated = 0;
        $skippedAliases = [];

        DB::transaction(function () use ($rows, &$created, &$updated, &$skippedAliases) {
            // 账号绑定计划：**与预填、旧 seeder 共用同一处实现**
            // （`PayrollController::distributeUserAccounts()`）。这一段与 t1 的修复同源，
            // 不得在此重写第三份「同名唯一就绑」。
            $plan = $this->planBindings($rows);

            $ownerOf = [];
            foreach (PayrollProfile::all(['id', 'name', 'aliases']) as $p) {
                foreach ((array) ($p->aliases ?? []) as $a) {
                    $ownerOf[trim((string) $a)] = (int) $p->id;
                }
            }

            foreach ($rows as $i => $row) {
                $profile = PayrollProfile::where('external_id', $row['external_id'])->first()
                    ?? new PayrollProfile;
                $isNew = ! $profile->exists;
                $isNew ? $created++ : $updated++;

                $profile->external_id = $row['external_id'];
                $profile->name = $row['name'];
                $profile->venue = $row['venue'];
                $profile->role = $row['role'];
                $profile->status = $row['status'];
                $profile->alert = $row['alert'];
                $profile->account_status = $row['account_status'];
                $profile->dual_base_salary = (bool) $row['dual_base_salary'];

                // 金额：主档留空（`null`）时**不写入** —— 那是「未知」，不是「0」。
                //
                // `payroll_profiles` 的金额列是 `decimal not null default 0`，**存不下 null**，
                // 所以「未知」只能靠「不赋值」让列留在 0，并在 `note` 里写明哪几项主档没给 ——
                // 否则一个「主档没写底薪」的人和一个「底薪确实是 0」的兼职老师，
                // 在界面上长得一模一样，用户无从知道该不该补录。
                // 对**既有行**更是绝不能写 0：那会把用户已经补录的金额清掉。
                $unknownMoney = PayrollMasterImporter::unknownMoneyFields($row);
                foreach (PayrollMasterImporter::moneyFields() as $field) {
                    if ($row[$field] !== null) {
                        $profile->{$field} = $row[$field];
                    }
                }

                // 收款账户与联系方式（用户决策 2026-09-26 完整入库）：
                // 文本字段直接写入（空 = 主档确实没这项，存 `''`）。
                // 与金额列的「留空不覆盖」语义不同：主档是这些字段的**唯一权威来源**，
                // 用户改卡号应改主档再重导，而不是在系统里改出一份与主档分叉的值。
                foreach (PayrollMasterImporter::accountFields() as $field) {
                    $profile->{$field} = (string) ($row[$field] ?? '');
                }

                // 账号绑定：取计划（同一处实现的规则）。
                // 计划里「已有绑定」的行会原样返回现值 ⇒ 不改绑、不解绑。
                $decision = $plan[$i] ?? ['userId' => null, 'reason' => null];
                $profile->user_id = $decision['userId'];

                // 备注：机器段**先摘后接**，保证重复导入不会越接越长
                // （`note` 列只有 200 字符，接几次就把用户自己写的备注挤没了）。
                // 机器段有专属前缀，用户手写正文不含这两个前缀 ⇒ 只摘尾部的机器段。
                $segments = [];
                if ($decision['reason'] !== null) {
                    $segments[] = '账号绑定：'.$decision['reason'];
                }
                if ($unknownMoney !== []) {
                    $segments[] = '主档未提供：'.implode('、', $unknownMoney)
                        .'（已留 0，请确认是「未知」还是「确实为 0」）';
                }
                $profile->note = self::composeNote((string) $profile->note, $segments);
                PayrollController::saveProfileGuardingUserId($profile, (string) $profile->note);

                // 别名：一个名字只能对一个人。已被别的人员占用时**跳过并上报**，
                // 不覆盖（覆盖会让上一次的归属静默改人）
                $aliases = [];
                foreach ($row['aliases'] as $alias) {
                    $owner = $ownerOf[$alias] ?? null;
                    if ($owner !== null && $owner !== (int) $profile->id) {
                        $skippedAliases[] = "{$alias}（已属于档案 #{$owner}）";

                        continue;
                    }
                    $aliases[] = $alias;
                }
                $profile->aliases = array_values(array_unique($aliases));
                $profile->save();

                foreach ($aliases as $alias) {
                    $ownerOf[$alias] = (int) $profile->id;
                }
            }
        });

        return ['rows' => $rows, 'stats' => $stats, 'created' => $created, 'updated' => $updated, 'skippedAliases' => $skippedAliases];
    }

    /** 由本 seeder 追加的机器段前缀（`composeNote()` 只摘这些，不动用户手写正文） */
    private const MACHINE_MARKERS = ['账号绑定：', '主档未提供：'];

    /**
     * 备注合成：**先摘掉尾部的机器段，再接上本次的机器段**。
     *
     * 为什么必须「先摘」：`db:seed` 会被反复执行，若每次都往后接，
     * 备注会变成「主档未提供：…；主档未提供：…；…」，两百字符的列很快只剩机器话，
     * 用户自己写的备注被挤没。摘掉后重接 ⇒ 结果与跑几次无关（幂等）。
     *
     * 只摘**尾部连续**的机器段：正文中间出现同名字样（用户自己写的）原样保留。
     * 机器段空间优先于正文（截断只截正文）——「主档没给什么」是用户决定补不补录的依据，
     * 把它截掉等于没说。
     *
     * @param  array<int, string>  $segments  本次要追加的机器段（可为空）
     */
    private static function composeNote(string $note, array $segments): string
    {
        $parts = array_values(array_filter(
            explode('；', trim($note)),
            fn ($p) => trim($p) !== ''
        ));
        // 从尾部往前摘掉机器段
        while ($parts !== []) {
            $last = trim((string) end($parts));
            $isMachine = false;
            foreach (self::MACHINE_MARKERS as $marker) {
                if (str_starts_with($last, $marker)) {
                    $isMachine = true;
                    break;
                }
            }
            if (! $isMachine) {
                break;
            }
            array_pop($parts);
        }
        $base = implode('；', array_map('trim', $parts));
        $tail = implode('；', $segments);

        if ($tail === '') {
            return mb_substr($base, 0, 200);
        }
        if ($base === '') {
            return mb_substr($tail, 0, 200);
        }
        $room = 200 - mb_strlen($tail) - 1;
        if ($room <= 0) {
            return mb_substr($tail, 0, 200);
        }

        return mb_substr($base, 0, $room).'；'.$tail;
    }

    /**
     * 为整批主档行算「姓名整组」的账号绑定计划。
     *
     * 与 `PayrollProfileSeeder::planBindings()` 同样的思路，但**规则本身不在这里重写**：
     * 取同名账号走 `PayrollController::accountsByNames()`，分配走
     * `distributeUserAccounts()`（含「同名多个账号不猜」「一个账号只能绑一行」
     * 「同店那行优先」「已被别行占用则不抢」四条）。
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{userId: ?int, reason: ?string}> 行下标 => 绑定结果
     */
    private function planBindings(array $rows): array
    {
        $existing = [];
        foreach (PayrollProfile::whereNotNull('external_id')->get(['id', 'external_id', 'user_id']) as $p) {
            $existing[(string) $p->external_id] = $p;
        }

        $byName = [];
        foreach ($rows as $i => $row) {
            $byName[$row['name']][] = $i;
        }
        if ($byName === []) {
            return [];
        }
        $accounts = PayrollController::accountsByNames(array_keys($byName));

        $plan = [];
        foreach ($byName as $name => $indexes) {
            $group = [];
            foreach ($indexes as $i) {
                $p = $existing[(string) $rows[$i]['external_id']] ?? null;
                $group[$i] = [
                    'venue' => (string) $rows[$i]['venue'],
                    'currentUserId' => ($p === null || $p->user_id === null) ? null : (int) $p->user_id,
                    'profileId' => $p === null ? null : (int) $p->id,
                ];
            }
            foreach (PayrollController::distributeUserAccounts((string) $name, $group, $accounts[$name] ?? []) as $i => $decision) {
                $plan[(int) $i] = $decision;
            }
        }

        return $plan;
    }

    /**
     * 导入报告。
     *
     * 关键是把**「未知/缺失」讲清楚**：用户要照着这份输出决定去补哪些资料，
     * 以及为什么有些人的金额是空的。所有异常（无法识别的岗位/门店、状态同义写法、
     * 被跳过的别名）都必须打印 —— 静默是这个导入器最危险的失败方式。
     *
     * @param  array<string, mixed>  $stats
     * @param  array<int, string>  $skippedAliases
     */
    private function report(array $stats, int $created, int $updated, array $skippedAliases): void
    {
        $total = PayrollProfile::count();
        $this->command?->info(
            "薪酬档案（新版主档）：新建 {$created}、更新 {$updated}，现有 {$total} 人"
            ."，别名 {$stats['aliasRows']} 人有别名"
        );
        $this->command?->line(
            '门店分布：'.collect($stats['byVenue'])->map(fn ($n, $v) => "{$v} {$n}")->implode('、')
            ."；有效 {$stats['valid']} 人 / 共 {$stats['total']} 行"
        );

        if ($stats['dualBase'] !== []) {
            $this->command?->line(
                '双底薪例外（据「重点提醒」判定，非硬编码）：'.implode('、', $stats['dualBase'])
            );
        }

        foreach ($stats['statusNotes'] as $note) {
            $this->command?->warn('人员状态：'.$note);
        }

        if ($stats['problems'] !== []) {
            foreach ($stats['problems'] as $problem) {
                $this->command?->warn('主档异常：'.$problem);
            }
        } else {
            $this->command?->line('主档异常：无（所有行都建了档）');
        }

        if ($skippedAliases !== []) {
            $this->command?->warn(
                '以下别名被跳过（已属于他人）：'.implode('、', array_slice($skippedAliases, 0, 20))
            );
        }

        // 🔴 缺失资料：只报**计数**，不落库（PII 政策）。
        // 用户据此知道去哪补；档案里没有这些列，因此不可能泄露。
        $this->command?->line(
            '待用户补录（本系统不存这些资料，仅统计缺口）：'
            .'手机缺 '.($stats['blanks']['手机'] ?? 0).' 人、'
            .'身份证号缺 '.($stats['blanks']['身份证号'] ?? 0).' 人、'
            .'银行卡号缺 '.($stats['blanks']['银行卡号'] ?? 0).' 人、'
            .'企业微信账号缺 '.($stats['blanks']['企业微信账号'] ?? 0).' 人'
        );

        // 「业绩表 11 个销售员列名全部可解析」是旧 seeder 的上线检查项之一，
        // 本 seeder 同样要能过 —— 别名丢了不会报错，但会让 9 个人在业绩导入时归零。
        $this->reportUnmatchedSalesColumns();
    }

    /** 业绩表 11 个销售员列名（含 9 个别名）是否都能解析到档案 */
    private function reportUnmatchedSalesColumns(): void
    {
        $names = ['苏米', '娟子', '张芷晴', '芷晴', 'Nico', 'CC', 'Lily', '小鹏', '婷婷', '阿玉'];
        $resolver = app(\App\Services\PayrollNameResolver::class)->warm();
        $unmatched = [];
        foreach ($names as $name) {
            if ($resolver->resolve($name) === null) {
                $unmatched[] = $name;
            }
        }

        if ($unmatched === []) {
            $this->command?->info('业绩表销售员列名全部可解析 ✅');

            return;
        }
        $this->command?->warn(
            '⚠️ 以下业绩表列名解析不到档案（业绩导入时会归零，请检查别名）：'.implode('、', $unmatched)
        );
    }
}
