<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\StaffDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 「他为什么能看到这个客户」的只读取证入口。
 *
 * ## 要回答的问题
 *
 * 2026-11 真机反馈：会员管理、留资管理里出现了**不属于自己归属**的客户。
 * 系统的可见范围是「本人归属 ∪ 授课关系」两段并集（见 `scopeCustomersForUser()` /
 * `scopeLeadsForUser()`），所以「不属于自己的客户」在代码里只有 4 种合法来源，
 * 本命令把每一行**归到具体那一条通道**上，而不是让人对着列表猜：
 *
 * | 通道 | 会员侧 | 留资侧 | 业务含义 |
 * | --- | --- | --- | --- |
 * | ① 本人归属 | consultant / owner | service_teacher | 真的是「我的客户」 |
 * | ② 授课关系 | 本人私教学员（`ky_bookings` private+signed） | 本人上过体验课（trial_teacher） | 我教过他，但归属是别人 |
 * | ③ 姓名池共用 | 同名/别名撞车 | 同左 | **缺陷**：两个账号共用同一个名字 |
 * | ④ 跨店 | venue ≠ 本人门店 | 同左 | **缺陷**：门店条件没生效 |
 *
 * ③ 是本命令最想抓的一类：`StaffDirectory::nameMap()` 对**歧义名字**（两个账号同叫
 * 「嘉欣」）是剔除的，但 `namesFor()` 直接返回自己那份名字，不做歧义过滤 ——
 * 于是 `staffOwnerFilter()` 的 `whereIn('consultant', ['嘉欣'])` 对两个账号**同时命中**，
 * 两个人互相拿到对方的客户。离职/换老师时最典型（旧账号没停用，又建了一个同名新账号）。
 *
 * ④ 只会出现在账号 `venue` 为空、或数据行的 venue 与账号不一致时。`scopeCustomersForUser()`
 * 里 `where('venue', $user->venue)` 在 `$user->venue = null` 时语义退化成 `venue is null`，
 * 命中的是「门店未填」的行 —— 那批行往往跨店。
 *
 * ## 只读
 *
 * 全程 `SELECT`，不改库、不写缓存、不发通知。可以随时在生产跑。
 *
 * 用法：
 *   php artisan yimai:visibility-audit                 # 全部帐号汇总 + 前 10 条异常
 *   php artisan yimai:visibility-audit --user=jiaxin   # 只看一个账号（登录名）
 *   php artisan yimai:visibility-audit --limit=50      # 每个账号最多列 50 条
 *   php artisan yimai:visibility-audit --json          # 给脚本用
 */
class VisibilityAudit extends Command
{
    protected $signature = 'yimai:visibility-audit
        {--user= : 只审计这个登录名（username）}
        {--limit=10 : 每个账号最多列出多少条「非本人归属」明细}
        {--json : 以 JSON 输出}';

    protected $description = '只读审计：各账号在会员/留资里看到「非本人归属」的行，并归因到具体通道';

    /** 通道标签（输出口径唯一，别在报告里另写一套中文） */
    private const CHANNEL = [
        'owner' => '本人归属',
        'teach' => '授课关系',
        'media' => '新媒体客资',
        'shared' => '姓名共用',
        'cross' => '跨店',
    ];

    public function handle(StaffDirectory $directory): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $only = trim((string) $this->option('user'));

        $users = User::query()
            ->when($only !== '', fn ($q) => $q->where('username', $only))
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->error($only !== '' ? "没有找到登录名 {$only}" : '没有任何账号');

            return self::FAILURE;
        }

        // ③ 姓名池共用：同一个名字被多个账号同时持有。这是缺陷的**成因清单**，
        // 单独输出一次，避免在每个账号的明细里重复猜。
        $sharedNames = $directory->sharedNames();

        $report = [
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'sharedNames' => $sharedNames,
            'accounts' => [],
        ];

        foreach ($users as $u) {
            $report['accounts'][] = $this->auditAccount($u, $limit, $sharedNames);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($report);

        return self::SUCCESS;
    }

    /**
     * 一个账号的可见范围体检。
     *
     * 可见范围**直接调生产用的两个 scope 函数**，不在这里重写条件 —— 重写就等于
     * 造第二套口径，审计结论会和线上行为分裂（这正是本仓库反复踩过的坑）。
     *
     * @param  array<int, array{name:string,accounts:array<int,string>}>  $sharedNames
     */
    private function auditAccount(User $u, int $limit, array $sharedNames): array
    {
        $roles = userRoles($u);
        $myNames = staffNames($u);

        $base = [
            'id' => (int) $u->id,
            'username' => (string) $u->username,
            'name' => (string) $u->name,
            'roles' => $roles,
            'venue' => (string) ($u->venue ?? ''),
            'names' => $myNames,
            'knownRole' => isKnownRoleUser($u),
        ];

        // 超管/店长看到本店/双店全部是**设计如此**，不属于「非本人归属」，
        // 但仍然统计数量 —— 现场反馈里最常见的误判就是「店长账号在员工手机上登录着」。
        $isBroad = userHasAnyRole($u, ['R_SUPER', 'R_MANAGER']);

        $customers = scopeCustomersForUser(Customer::query(), $u)
            ->get(['id', 'name', 'venue', 'phone', 'external_id', 'consultant', 'consultant_user_id', 'owner', 'owner_user_id']);

        $leads = scopeLeadsForUser(Lead::query(), $u)
            ->get(['id', 'name', 'venue', 'phone', 'service_teacher', 'service_teacher_user_id', 'trial_teacher', 'trial_teacher_user_id']);

        $customerRows = [];
        $customerStats = ['total' => $customers->count(), 'owner' => 0, 'teach' => 0, 'shared' => 0, 'cross' => 0];
        foreach ($customers as $c) {
            $channels = $this->customerChannels($u, $c, $myNames, $sharedNames);
            foreach ($channels as $ch) {
                $customerStats[$ch] = ($customerStats[$ch] ?? 0) + 1;
            }
            if ($isBroad || in_array('owner', $channels, true)) {
                continue;
            }
            $customerRows[] = [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'venue' => (string) $c->venue,
                'consultant' => (string) $c->consultant,
                'owner' => (string) $c->owner,
                'channels' => $channels,
                // 归属 id 的状态：判断「这行到底是谁的」唯一的确定性依据
                'idState' => $this->ownerIdState($u, (int) $c->consultant_user_id, (int) $c->owner_user_id),
                'sharedWith' => $this->sharedWith($channels, [$c->consultant, $c->owner], $myNames, $sharedNames, (string) $u->username),
            ];
        }

        $leadRows = [];
        $leadStats = ['total' => $leads->count(), 'owner' => 0, 'teach' => 0, 'shared' => 0, 'cross' => 0];
        foreach ($leads as $l) {
            $channels = $this->leadChannels($u, $l, $myNames, $sharedNames);
            foreach ($channels as $ch) {
                $leadStats[$ch] = ($leadStats[$ch] ?? 0) + 1;
            }
            if ($isBroad || in_array('owner', $channels, true)) {
                continue;
            }
            $leadRows[] = [
                'id' => (int) $l->id,
                'name' => (string) $l->name,
                'venue' => (string) $l->venue,
                'serviceTeacher' => (string) $l->service_teacher,
                'trialTeacher' => (string) $l->trial_teacher,
                'channels' => $channels,
                'idState' => $this->ownerIdState($u, (int) $l->service_teacher_user_id, (int) $l->trial_teacher_user_id),
                'sharedWith' => $this->sharedWith($channels, [$l->service_teacher, $l->trial_teacher], $myNames, $sharedNames, (string) $u->username),
            ];
        }

        return $base + [
            'broadScope' => $isBroad,
            'customers' => $customerStats,
            'customerRows' => array_slice($customerRows, 0, $limit),
            'customerRowsTruncated' => max(0, count($customerRows) - $limit),
            'leads' => $leadStats,
            'leadRows' => array_slice($leadRows, 0, $limit),
            'leadRowsTruncated' => max(0, count($leadRows) - $limit),
        ];
    }

    /**
     * 归属 id 的三种状态 —— 决定「这行到底是谁的」能不能从数据里判出来。
     *
     *  - `mine`  ：id 明确指向本账号（最可靠，无需看名字）
     *  - `other` ：id 指向**别的**账号，本人只是靠名字命中（同名共用的典型形态）
     *  - `empty` ：id 为空，只有姓名字符串（同步历史行）→ 名字被两个账号持有时无法判归属
     */
    private function ownerIdState(User $u, int $idA, int $idB): string
    {
        if ($idA === (int) $u->id || $idB === (int) $u->id) {
            return 'mine';
        }
        if ($idA > 0 || $idB > 0) {
            return 'other';
        }

        return 'empty';
    }

    /**
     * 与本账号共用这批数据的其它账号（只在 channels 含 shared 时有意义）。
     *
     * @param  array<int, string>  $channels
     * @param  array<int, string|null>  $rawNames
     * @param  array<int, string>  $myNames
     * @param  array<int, array{name:string,accounts:array<int,string>}>  $sharedNames
     * @return array<int, string>
     */
    private function sharedWith(array $channels, array $rawNames, array $myNames, array $sharedNames, string $self): array
    {
        if (! in_array('shared', $channels, true)) {
            return [];
        }

        $out = [];
        foreach ($rawNames as $raw) {
            $n = trim((string) $raw);
            if ($n === '' || ! in_array($n, $myNames, true)) {
                continue;
            }
            foreach ($sharedNames[$n]['accounts'] ?? [] as $key) {
                if ($key !== $self) {
                    $out[$key] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * 一条会员命中的通道集合（可与 `scopeCustomersForUser()` 的分支逐一对照）。
     *
     * 顺序无关，全部列出：一行可能既是「授课关系」又「跨店」，那说明两个缺陷叠加。
     *
     * @param  array<int, string>  $myNames
     * @param  array<int, array{name:string,accounts:array<int,string>}>  $sharedNames
     * @return array<int, string>
     */
    private function customerChannels(User $u, Customer $c, array $myNames, array $sharedNames): array
    {
        $out = [];

        // 归属判定分三层，**id 优先于名字**：id 是确定归属，名字只对「历史行」兜底。
        // 同名共用时，两个账号的 `whereIn(归属名, 名字)` 会命中同一批行，靠名字根本
        // 分不出「这是我还是他」—— 所以「名字命中 + id 指向别人 + 该名字被多账号持有」
        // 必须归到 shared（缺陷），不能算本人归属（否则报告会把越权行洗成正常行）。
        $idHit = ((int) $c->consultant_user_id === (int) $u->id) || ((int) $c->owner_user_id === (int) $u->id);
        $hitNames = [];
        foreach ([$c->consultant, $c->owner] as $raw) {
            $n = trim((string) $raw);
            if ($n !== '' && in_array($n, $myNames, true)) {
                $hitNames[] = $n;
            }
        }
        $nameHit = $hitNames !== [];
        $sharedHit = false;
        foreach ($hitNames as $n) {
            if (count($sharedNames[$n]['accounts'] ?? []) > 1) {
                $sharedHit = true;

                break;
            }
        }
        $mine = $idHit || ($nameHit && ! $sharedHit);

        if ($mine) {
            $out[] = 'owner';
        }

        // 授课关系：与 privateStudentKeys() 同口径（private + signed + 本人授课 + 本店）
        if (! $mine) {
            $keys = privateStudentKeys($u);
            $ext = (string) ($c->external_id ?? '');
            $short = preg_replace('/^ky:\d+:/', '', $ext) ?? '';
            $hit = ($ext !== '' && in_array($ext, $keys['external_ids'], true))
                || ($short !== '' && in_array($short, $keys['external_ids'], true))
                || ((string) $c->phone !== '' && in_array((string) $c->phone, $keys['phones'], true));
            if ($hit) {
                $out[] = 'teach';
            }
        }

        // 新媒体：`/customers` 对 R_MEDIA 走 scopeLeadOnlyCustomers()（P5 且非 ky:），
        // 那是**设计口径**（部门共享的前端客资），不属于缺陷，但员工会读成「别人的客户」，
        // 所以单独归类，避免在报告里被当成未归因。
        if (! $mine && userHasRole($u, 'R_MEDIA')) {
            $out[] = 'media';
        }

        // 姓名共用：只在**不是本人归属**的行上判 —— 本人归属的行被标 shared 是噪音，
        // 会让「有几个人的客户混进来了」这个数字虚高（第一版就踩了这个坑）。
        if (! $mine && $sharedHit) {
            $out[] = 'shared';
        }

        if ((string) ($u->venue ?? '') !== '' && (string) $c->venue !== (string) $u->venue) {
            $out[] = 'cross';
        }

        return array_values(array_unique($out));
    }

    /**
     * 一条客资命中的通道集合（与 `scopeLeadsForUser()` 的分支对照）。
     *
     * @param  array<int, string>  $myNames
     * @param  array<int, array{name:string,accounts:array<int,string>}>  $sharedNames
     * @return array<int, string>
     */
    private function leadChannels(User $u, Lead $l, array $myNames, array $sharedNames): array
    {
        $out = [];

        // 与会员侧同一条口径：id 优先，名字只兜底历史行；名字被多账号持有时归 shared。
        $idHit = ((int) $l->service_teacher_user_id === (int) $u->id)
            || ((int) $l->trial_teacher_user_id === (int) $u->id);
        $hitNames = [];
        foreach ([$l->service_teacher, $l->trial_teacher] as $raw) {
            $n = trim((string) $raw);
            if ($n !== '' && in_array($n, $myNames, true)) {
                $hitNames[] = $n;
            }
        }
        $nameHit = $hitNames !== [];
        $sharedHit = false;
        foreach ($hitNames as $n) {
            if (count($sharedNames[$n]['accounts'] ?? []) > 1) {
                $sharedHit = true;

                break;
            }
        }
        $mine = $idHit || ($nameHit && ! $sharedHit);

        if ($mine) {
            $out[] = 'owner';
        }

        if (! $mine) {
            // 授课老师看得到「本人上过体验课」的客资：trial_teacher 命中即算授课关系
            if (in_array(trim((string) $l->trial_teacher), $myNames, true) || (int) $l->trial_teacher_user_id === (int) $u->id) {
                $out[] = 'teach';
            } else {
                // 私教学员手机号通道（要求该客资已有真人服务老师）
                $keys = privateStudentKeys($u);
                if ((string) $l->phone !== '' && in_array((string) $l->phone, $keys['phones'], true)) {
                    $out[] = 'teach';
                }
            }
        }

        // 新媒体：`/leads` 对 R_MEDIA 走「整个部门录入的客资」（`scopeLeadCreatedByMediaDepartment`），
        // 部门共享是设计口径，不是越权。
        if (! $mine && userHasRole($u, 'R_MEDIA')) {
            $out[] = 'media';
        }

        if (! $mine && $sharedHit) {
            $out[] = 'shared';
        }

        if ((string) ($u->venue ?? '') !== '' && (string) $l->venue !== (string) $u->venue) {
            $out[] = 'cross';
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $report */
    private function renderHuman(array $report): void
    {
        $this->line('生成时间：'.$report['generatedAt']);
        $this->newLine();

        // ── ① 姓名共用清单（越权成因） ──
        $this->info('① 姓名池共用（同一名字被多个账号持有 = 互相看到对方客户）');
        if ($report['sharedNames'] === []) {
            $this->line('  无。说明「非本人归属」的行不是同名/别名撞车造成的。');
        } else {
            $rows = [];
            foreach ($report['sharedNames'] as $item) {
                $rows[] = [$item['name'], implode(' / ', $item['accounts'])];
            }
            $this->table(['名字', '持有账号'], $rows);
        }
        $this->newLine();

        // ── ② 账号逐个体检 ──
        $this->info('② 账号可见范围体检');
        $summary = [];
        foreach ($report['accounts'] as $a) {
            $summary[] = [
                $a['username'],
                $a['name'],
                implode(',', $a['roles']),
                $a['venue'] !== '' ? $a['venue'] : '(空)',
                $a['broadScope'] ? '本店/双店' : ($a['knownRole'] ? '按人' : '未知角色(空集)'),
                $a['customers']['total'],
                $a['customers']['owner'],
                $a['customers']['teach'],
                $a['customers']['shared'],
                $a['customers']['cross'],
                $a['leads']['total'],
                $a['leads']['owner'],
                $a['leads']['teach'],
            ];
        }
        $this->table([
            '登录名', '姓名', '角色', '门店', '范围',
            '会员可见', '会员本人', '会员授课', '会员同名', '会员跨店',
            '留资可见', '留资本人', '留资授课',
        ], $summary);
        $this->newLine();

        // ── ③ 非本人归属明细 ──
        $this->info('③ 「非本人归属」明细（先看这里判断是设计口径还是缺陷）');
        foreach ($report['accounts'] as $a) {
            if ($a['broadScope']) {
                $this->line("  {$a['username']}：店长/超管范围，本店或双店全量，跳过。");

                continue;
            }
            if ($a['customerRows'] === [] && $a['leadRows'] === []) {
                $this->line("  {$a['username']}：无（可见范围内每一行都挂着本人归属）。");

                continue;
            }

            $this->line("  {$a['username']}（{$a['name']}）");
            foreach ($a['customerRows'] as $row) {
                $this->line(sprintf(
                    '    会员 #%d %s [%s] 顾问=%s 负责人=%s → %s%s',
                    $row['id'],
                    $row['name'],
                    $row['venue'],
                    $row['consultant'] !== '' ? $row['consultant'] : '—',
                    $row['owner'] !== '' ? $row['owner'] : '—',
                    $this->channelsLabel($row['channels']),
                    $this->stateLabel($row)
                ));
            }
            if ($a['customerRowsTruncated'] > 0) {
                $this->line("    …会员另有 {$a['customerRowsTruncated']} 条未列出（--limit 调大）");
            }
            foreach ($a['leadRows'] as $row) {
                $this->line(sprintf(
                    '    留资 #%d %s [%s] 服务老师=%s 体验课老师=%s → %s%s',
                    $row['id'],
                    $row['name'],
                    $row['venue'],
                    $row['serviceTeacher'] !== '' ? $row['serviceTeacher'] : '—',
                    $row['trialTeacher'] !== '' ? $row['trialTeacher'] : '—',
                    $this->channelsLabel($row['channels']),
                    $this->stateLabel($row)
                ));
            }
            if ($a['leadRowsTruncated'] > 0) {
                $this->line("    …留资另有 {$a['leadRowsTruncated']} 条未列出（--limit 调大）");
            }
        }

        $this->newLine();
        $this->line('读法：');
        $this->line('  · 授课关系（teach）→ 设计口径：老师看得见他教过的学员。要收窄就改 scopeCustomersForUser 的私教/体验课分支。');
        $this->line('  · 新媒体客资（media）→ 设计口径：P5 前端客资对新媒体部门整体可见，不是越权；统计表的「同名/跨店」列不含它。');
        $this->line('  · 姓名共用（shared）→ 缺陷：同名/别名撞车。处理：人员管理停用或合并旧账号，别让两个账号共用一个名字。');
        $this->line('  · 跨店（cross）→ 缺陷：账号 venue 为空或行 venue 与账号不一致，先补齐账号门店。');
    }

    /** @param array<int, string> $channels */
    private function channelsLabel(array $channels): string
    {
        if ($channels === []) {
            return '未归因（需人工看）';
        }

        return implode(' + ', array_map(fn ($c) => self::CHANNEL[$c] ?? $c, $channels));
    }

    /**
     * 明细行的补充说明：为什么这行会被看到、该怎么处理。
     *
     * 「姓名共用 + 归属 id 为空」与「姓名共用 + 归属 id 指向别人」是两种不同的脏数据，
     * 处理动作也不同（前者补 id，后者停用旧账号），所以必须分开说。
     *
     * @param  array<string, mixed>  $row
     */
    private function stateLabel(array $row): string
    {
        $channels = (array) ($row['channels'] ?? []);
        $notes = [];

        if (in_array('shared', $channels, true)) {
            $with = (array) ($row['sharedWith'] ?? []);
            $who = $with === [] ? '' : '（与 '.implode(' / ', $with).' 共用名字）';
            if (($row['idState'] ?? '') === 'empty') {
                $notes[] = "归属 id 为空，只存了姓名{$who} → 两个账号都会看到，需补 id";
            } else {
                $notes[] = "归属 id 指向别的账号，本人靠同名看到{$who} → 停用或合并旧账号";
            }
        }
        if (in_array('cross', $channels, true)) {
            $notes[] = '跨店 → 补齐账号门店';
        }

        return $notes === [] ? '' : '　['.implode('；', $notes).']';
    }
}
