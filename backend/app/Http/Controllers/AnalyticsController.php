<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\KyCard;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\VisitMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class AnalyticsController extends Controller
{
    /**
     * 看板聚合缓存键：按角色范围 + 查询参数隔离，版本号随业务写入失效，TTL 60 秒兜底。
     */
    private static function cacheKey(string $endpoint, User $u, string $venue, string $start, string $end): string
    {
        // 缓存键必须完整体现可见范围，否则会串数据：
        //  - 账号 id：非超管的数据现在是**按人**收窄的（服务老师只看自己名下的会员），
        //    同店两个老师角色与门店都相同，只靠「角色 + 门店」做键会把一个人的数字发给另一个人
        //    （曾只漏了 venues 就串过一条数据，这里一并把 id 带上）；
        //  - 角色：同一门店下「店长」与「店长 + 授课老师」的范围不同；
        //  - 授权门店列表：两个新媒体账号角色相同，但授权门店不同时范围不同。
        $scope = userHasRole($u, 'R_SUPER')
            ? 'all'
            : 'u'.$u->id
                .'|'.implode(',', userRoles($u))
                .'|'.(string) $u->venue
                .'|'.implode(',', (array) $u->venues);

        return 'analytics:v'.businessCacheVersion('analytics').":{$endpoint}:".md5("{$scope}|{$venue}|{$start}|{$end}");
    }

    /**
     * 四档到店频次分桶标签（S11 用），顺序即前端渲染顺序。
     *
     * 窗口 = **30 天**（用户 2026-09 拍板口径），对应 `customers.attend_m3`
     * （M3 = 含今天在内的近 30 天滚动窗口，写入方见 `KyMemberSyncService` 的
     * attendance 计算；注意不是「近 90 天」——`m1/m2/m3` 是三个**连续且等长**的
     * 30 天窗口，命名里的数字是「第几个窗口」而非月数）。
     */
    private const ATTENDANCE_BUCKETS = [
        ['key' => '0', 'label' => '0 次', 'min' => 0, 'max' => 0],
        ['key' => '1-3', 'label' => '1-3 次', 'min' => 1, 'max' => 3],
        ['key' => '4-7', 'label' => '4-7 次', 'min' => 4, 'max' => 7],
        ['key' => '8+', 'label' => '8 次及以上', 'min' => 8, 'max' => null],
    ];

    /** 课型键 → 对客标签（与 KyBooking::KIND_LABELS 同源，勿另立一套文案） */
    private const REVENUE_KINDS = ['private', 'small', 'group'];

    /** GET /analytics/summary */
    public function summary(Request $r)
    {
        $u = $r->user();

        return ok(Cache::remember(
            self::cacheKey('summary', $u, '', '', ''),
            60,
            function () use ($u) {
                return $this->computeSummary($u);
            }
        ));
    }

    private function computeSummary($u): array
    {
        // 会员统计按角色收窄（与 /customers 同一口径）。
        // 此前只卡门店，于是服务老师/授课老师看到的是**全店**会员数却标成"我的"，
        // 而新媒体账号 venue 为空、`where venue is null` 恒为 0 —— 同一处代码在两种角色下
        // 分别表现为"偏大"和"恒为 0"。
        $customers = scopeCustomersForUser(Customer::query(), $u)->get();
        $totalMembers = $customers->filter(fn ($c) => $c->layer !== 'P5' || str_starts_with((string) $c->external_id, 'ky:'))->count();
        // 待分配以随心瑜顾问字段为准，本地负责人只是后续执行归属。
        $unassigned = $customers->filter(fn ($c) => trim((string) $c->consultant) === '')->count();

        $leadQ = applyVenueScope(Lead::query(), $u, '');
        $leads = $leadQ->get();
        $newLeads = $leads->where('status', '新留资')->count();
        $leadsWithTeacher = $leads->filter(fn ($l) => $l->service_teacher !== '')->count();
        $assignRate = $leads->count() > 0 ? round($leadsWithTeacher / $leads->count() * 100) : 0;

        $custWithAction = $customers->filter(fn ($c) => $c->owner !== '未分配' && $c->next_action !== null && $c->next_action !== '')->count();
        $closureRate = $totalMembers > 0 ? round($custWithAction / $totalMembers * 100) : 0;

        $renewalIds = filteredIds('待续课');
        $renewing = $customers->whereIn('id', $renewalIds);
        $renewalTouched = $renewing->filter(fn ($c) => $c->renewal_plan !== null && $c->renewal_plan !== '')->count();
        $renewalRate = $renewing->count() > 0 ? round($renewalTouched / $renewing->count() * 100) : 0;

        $taskQ = applyVenueScope(Task::query(), $u, '');
        $tasks = $taskQ->get();
        $taskRate = $tasks->count() > 0 ? round($tasks->where('status', '已完成')->count() / $tasks->count() * 100) : 0;

        return [
            'totalCustomers' => $customers->count(),
            'totalMembers' => $totalMembers,
            'unassigned' => $unassigned,
            'assignRate' => $assignRate,           // 留资分配率
            'closureRate' => $closureRate,          // 客户闭环率
            'renewalTasks' => $renewing->count(),   // 待续课数
            'renewalRate' => $renewalRate,          // 续费已处理率
            'taskRate' => $taskRate,                // 任务完成率
            'doneTasks' => $tasks->where('status', '已完成')->count(),
            'totalTasks' => $tasks->count(),
            // S10 收入结构：按课型聚合课时耗卡金额 ÷ 售卡金额（口径见 computeRevenueMix）
            'revenueMix' => $this->computeRevenueMix($u),
        ];
    }

    /**
     * S10 收入结构：按课型（私教 / 小班 / 团课）聚合**课时耗卡金额**，给出结构占比。
     *
     * ## 口径（用户 2026-09 拍板，勿自行改成别的分母）
     *
     *  1. **课型口径唯一**：走 `KyBooking::courseKind()` —— 它内部只调用 `courseKindFrom()`
     *     （列值优先，缺列时回退 `raw.course_type`）。本方法**不再写第二套 match**：
     *     全站课型判定一旦多出一份，`raw` 缺失时的兜底方向会漂移（见 `courseKindFrom` 注释
     *     里 t35 误判的复盘）。
     *  2. **耗卡金额单行推导**（逐行取第一项可用值，与上游字段语义一致）：
     *       ① `raw.m_card_unit_cash_value × raw.single_charge`（单次现金价值 × 本次扣次）
     *       ② 回退 `raw.charge`（本次支付金额）
     *       ③ 都取不到 ⇒ 计 0，并计入 `degraded.bookingsWithoutAmount`
     *     取不到金额的行**不猜**：宁可少算也不按平均价编一个数，但必须显式告知，
     *     否则「收入结构」会静默偏小。
     *  3. **只算已签到（`status = signed`）的行**：未签到/已取消/爽约的预约没有消耗课时，
     *     与 trends 端点的 `classCount` 同源。
     *  4. **分母 = 售卡实收金额**：`ky_cards` 的 `deal_price` 合计，排除体验/赠卡
     *     （`is_taste`）与退卡（`status_format = '退卡'`），与 trends 的售卡金额同源过滤。
     *
     * ⚠️ 分子（一段时间内的课时消耗）与分母（全部售卡实收）**不是同一个会计区间**：
     * 本端点没有 start/end 参数，两者都是「全量至今」。所以 `ratio` 只作**结构参照**，
     * 不能当财务上的「耗卡率」。真的要按期对比请用 trends（分子分母同窗口）。
     *
     * @return array<string, mixed>
     */
    private function computeRevenueMix($u): array
    {
        $cardQ = applyVenueScope(
            KyCard::query()
                ->where('is_taste', false)
                ->where('status_format', '!=', '退卡'),
            $u, ''
        );
        $cardSales = (float) $cardQ->sum('deal_price');

        $kindAmount = array_fill_keys(self::REVENUE_KINDS, 0.0);
        $kindClasses = array_fill_keys(self::REVENUE_KINDS, 0);
        $withoutAmount = 0;

        $bookingQ = applyVenueScope(
            KyBooking::query()->where('status', 'signed'),
            $u, ''
        );
        foreach ($bookingQ->get() as $booking) {
            $kind = $booking->courseKind();
            if (! isset($kindAmount[$kind])) {
                // courseKindFrom 只返回这三个值；真出现了说明有人改了判定函数，
                // 落到 group 会静默改写结构，故显式计数而不是静默归类。
                $kind = 'group';
                $kindClasses[$kind]++;
                continue;
            }
            $kindClasses[$kind]++;
            $amount = self::bookingConsumeAmount($booking);
            if ($amount <= 0) {
                $withoutAmount++;
            }
            $kindAmount[$kind] += $amount;
        }

        $consumptionTotal = array_sum($kindAmount);
        $mix = [];
        foreach (self::REVENUE_KINDS as $kind) {
            $mix[$kind] = [
                'label' => KyBooking::KIND_LABELS[$kind],
                'amount' => round($kindAmount[$kind], 2),
                // 占比口径：该类耗卡金额 ÷ 售卡金额（有售卡收入时的结构参照）
                'ratio' => $cardSales > 0 ? round($kindAmount[$kind] / $cardSales * 100, 2) : 0,
                // 耗卡内部结构（三类相加 = 100），与 ratio 是两个不同分母，勿混用
                'share' => $consumptionTotal > 0 ? round($kindAmount[$kind] / $consumptionTotal * 100, 2) : 0,
                'classCount' => $kindClasses[$kind],
            ];
        }

        return [
            'private' => $mix['private'],
            'small' => $mix['small'],
            'group' => $mix['group'],
            'cardSales' => round($cardSales, 2),
            'consumptionTotal' => round($consumptionTotal, 2),
            'degraded' => [
                'bookingsWithoutAmount' => $withoutAmount,
                'note' => '这些签到行在 raw 里既无单次现金价值也无可解析的支付金额，耗卡金额按 0 计（不估算）',
            ],
        ];
    }

    /**
     * 单行预约的耗卡金额（元）：单次现金价值 × 本次扣次，回退本次支付金额。
     *
     * `raw` 的形态不保证（cast 后是数组，部分 `select` 场景可能是 JSON 串），
     * 故这里统一归一化后再取，不在调用方各自解析。
     */
    private static function bookingConsumeAmount(KyBooking $booking): float
    {
        $raw = $booking->raw;
        if (! is_array($raw)) {
            $raw = (array) json_decode((string) $raw, true);
        }
        $num = function ($v): float {
            return is_numeric($v) ? (float) $v : 0.0;
        };

        $unit = $num($raw['m_card_unit_cash_value'] ?? null);
        if ($unit > 0) {
            // single_charge 的形态有 "1" / "1次" / 1.0 三种历史写法，取其中的数字
            $times = 1.0;
            if (isset($raw['single_charge'])) {
                $text = (string) $raw['single_charge'];
                if (preg_match('/\d+(\.\d+)?/', $text, $m)) {
                    $times = (float) $m[0];
                }
            }

            return $unit * max(0.0, $times);
        }

        return max(0.0, $num($raw['charge'] ?? null));
    }

    /**
     * 「会员」谓词（查询构造器形式）。
     *
     * 与 `computeSummary()` 的 `totalMembers`、`computeTrends()` 的 `memberTotal`
     * **同一口径**：正式会员，或随心瑜同步进来的会员（`external_id = ky:*`）。
     * 抽成一处是为了让「会员」这个词在本控制器里只有一个定义 —— 同一批人数在
     * 两个端点上差一个人，运营就会开始怀疑整块看板。
     */
    private static function applyMemberScope($query)
    {
        return $query->where(function ($q) {
            $q->where('layer', '!=', 'P5')->orWhere('external_id', 'like', 'ky:%');
        });
    }

    /**
     * 会员可见范围 + venue 收窄，与 `computeTrends()` 里的 `$custQ` 完全同源。
     *
     * 会员类指标必须走 `scopeCustomersForUser()` 而不是 `applyVenueScope()`：
     * 后者只按门店收窄，而服务老师/授课老师「只看本人名下会员」的**按人**收窄
     * 只在 `scopeCustomersForUser()` 里。只卡门店会让老师看到全店会员，
     * 正是 summary 注释里记过的那次事故。
     */
    private function scopedCustomers($u, string $venue)
    {
        $q = scopeCustomersForUser(Customer::query(), $u);
        if (userHasRole($u, 'R_SUPER') && $venue !== '') {
            $q->where('venue', $venue);
        }

        return $q;
    }

    /** GET /analytics/attendance-renewal-curve */
    public function attendanceRenewalCurve(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');

        return ok(Cache::remember(
            self::cacheKey('attendance-renewal-curve', $u, $venue, '', ''),
            60,
            fn () => $this->computeAttendanceRenewalCurve($u, $venue)
        ));
    }

    /**
     * S11 到店频次 × 续费率曲线：按「近 30 天到店次数」分 4 档，各档给出**按人**算的续费率。
     *
     * ## 窗口 = 30 天，字段 = `attend_m3`（易取错，务必读完）
     *
     * `KyMemberSyncService::attendanceWindows()` 定义的是三个**连续且等长**的 30 天窗口：
     *
     *     M1 = 60~89 天前      M2 = 30~59 天前      M3 = 近 30 天（含今天）
     *
     * 所以「30 天窗口」对应的是 **`attend_m3`**（`attendanceWindows()[2]`），
     * 而字段名里的数字是「第几个窗口」不是月数。⚠️ 前端「活跃度」卡片的脚注
     * （`admin-web/src/views/yimai/analytics/index.vue` 的 `M1=最近完整月，M3=最早完整月`）
     * 把 M1/M3 的新旧**写反了**（`M1` 实为最旧的 60~89 天前窗口）。**不要照抄那句**：
     * 取 `attend_m1` 会得到「60~89 天前」的曲线，与需求差 3 倍窗口。
     *
     * ## 续费率按人算（用户 2026-09 拍板）
     *
     *   - 分母 = 该档**会员人数**（不是卡张数、不是人次）
     *   - 分子 = 该档已续费**人数**
     *     判定与 `/analytics/summary` 的 `renewalTouched` 同源：`renewal_plan` 非空
     *     （即工作台里已登记续课预报）。**不按卡张数计**：一个会员名下三张卡续了
     *     一张，按卡算会得到 1/3 与 1 两个都说得通的答案，按人只有「续没续」一个答案。
     *
     * 曲线本身不是结论而是**标定工具**：用于反推 `reviveDays` / `renewalExpireDays`
     * 这类阈值（见 `docs/战略审查与规划/07-发展方向规划.md` ②-2），故各档分母一并给出，
     * 避免「小样本档看起来续费率 100%」被当成结论。
     *
     * @return array<string, mixed>
     */
    private function computeAttendanceRenewalCurve($u, string $venue): array
    {
        $customers = self::applyMemberScope($this->scopedCustomers($u, $venue))->get();

        $rows = [];
        foreach (self::ATTENDANCE_BUCKETS as $spec) {
            $rows[$spec['key']] = [
                'bucket' => $spec['key'],
                'label' => $spec['label'],
                'memberCount' => 0,
                'renewedCount' => 0,
                'renewalRate' => 0,
            ];
        }

        foreach ($customers as $c) {
            // attend_m3 在库里有 default 0，不存在 null；仍统一转 int 防止驱动返回字符串
            $times = (int) $c->attend_m3;
            $key = null;
            foreach (self::ATTENDANCE_BUCKETS as $spec) {
                if ($times >= $spec['min'] && ($spec['max'] === null || $times <= $spec['max'])) {
                    $key = $spec['key'];
                    break;
                }
            }
            if ($key === null) {
                // 桶定义被改坏时才会走到这里（8+ 的 max 是 null，理论覆盖全部 >= 8 的值）。
                // 不归入任何档、计入 unbucketed：静默塞进某一档会让曲线看着正常但数字是错的。
                $unbucketed = ($unbucketed ?? 0) + 1;

                continue;
            }
            $rows[$key]['memberCount']++;
            if ($c->renewal_plan !== null && $c->renewal_plan !== '') {
                $rows[$key]['renewedCount']++;
            }
        }

        $totalMembers = 0;
        $totalRenewed = 0;
        foreach ($rows as &$row) {
            $totalMembers += $row['memberCount'];
            $totalRenewed += $row['renewedCount'];
            $row['renewalRate'] = $row['memberCount'] > 0
                ? round($row['renewedCount'] / $row['memberCount'] * 100, 1)
                : 0;
        }
        unset($row);

        return [
            'buckets' => array_values($rows),
            'totalMembers' => $totalMembers,
            'totalRenewed' => $totalRenewed,
            'overallRenewalRate' => $totalMembers > 0 ? round($totalRenewed / $totalMembers * 100, 1) : 0,
            'unit' => 'person',
            'window' => [
                'attendField' => 'attend_m3',
                'windowDays' => 30,
                'basis' => 'KyMemberSyncService::attendanceWindows()[2] = 含今天的近 30 天滚动窗口',
                'trap' => 'attend_m1 是 60~89 天前（最旧窗口），不是「近 30 天」；前端「活跃度」卡片脚注把 M1/M3 写反了，勿照抄',
            ],
            'renewalCriterion' => 'renewedCount = 该档 renewal_plan 非空的会员人数（与 /analytics/summary 的 renewalRate 同源口径）；按人计，不按卡张数',
            'unbucketed' => $unbucketed ?? 0,
        ];
    }

    /** GET /analytics/asset-buckets */
    public function assetBuckets(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');

        return ok(Cache::remember(
            self::cacheKey('asset-buckets', $u, $venue, '', ''),
            60,
            fn () => $this->computeAssetBuckets($u, $venue)
        ));
    }

    /**
     * S12 未耗课余额分桶：`cards_list` 逐卡按「剩余期限 × 卡种」交叉分桶。
     *
     * ## ⚠️ 口径声明（先读，防止后人强行与 153 万对上）
     *
     * 本端点输出的是**卡数**，不是金额，也**不可能**与上游 `remainingAssets` 相等：
     *
     *  1. `remainingAssets` = 上游接口 `getvenuedataoverview.remaining_assets_total`
     *     （`KyController.php:156`），单位**元**，来源是随心瑜的聚合值；
     *  2. `cards_list` 里逐卡的 `residue` 单位是**节或天**（`unit` 字段），
     *     全仓库没有任何「把卡换算成剩余资产金额」的本地逻辑（`grep remaining_assets`
     *     在 `helpers.php` 零命中）；
     *  3. `cards_list` 本身**刻意不含过期卡**——过期卡在 `card_stats.expiredCards`
     *     保留区（见 `KyMemberSyncService.php:33-34` 与 `:524-525` 的注释：
     *     「不要把过期卡塞进 cards_list」，否则会经由 C8 分支污染待续费判定）；
     *  4. `residue === null`（上游没返回 `residue_amount`）的卡是「余额未知」而不是 0，
     *     helpers 里也显式把它们排除在判定之外。
     *
     * ⇒ 所以验收标准是**分类守恒**：`Σ 各桶卡数 === cards_list 总卡数`，
     * 一张不漏、一张不重。**绝不允许为了「对上 153 万」去调分母**。
     *
     * ## 分桶维度
     *
     *  - 剩余期限（按逐卡 `deadline` 距今自然日）：`≤30` / `31-90` / `>90` / `未知`
     *    （`deadline` 缺失或不可解析 ⇒ 未知桶；已过期卡的天数为负，归入 `≤30`）
     *  - 卡种：`type` 1=次卡 / 2=期限卡 / 3=储值卡（上游字典 `cardtype`）/ 其它
     *  - 余额状态（单列，承接 `residue === null` 那批）：已知有余额 / 已耗尽 / 未知
     *
     * ⚠️ 逐卡字段名是 `deadline` 不是 `expire_date`：`expire_date` 是 `customers`
     * 表上「全部卡里最早到期日」的**聚合列**，逐卡明细里只有 `deadline`
     * （见 `KyMemberSyncService::cardSummary()` 的 `cards_list` 映射）。
     *
     * ## 人员范围：**不收窄到「会员」**，凡持有有效卡者一律计入（有意为之）
     *
     * 本端点是**预收负债视角**（未履约余额），而不是会员结构视角，故不走
     * `applyMemberScope()`：一个 `layer = P5` 但名下仍有有效卡的客户，他手上的卡
     * 依然是「已收钱、未交付课时」的负债，把他排除会让负债**少报**。
     * 这与 `helpers.php:1925-1940` 把 `cards_list` 非空视为「有资产」的判定一致。
     * 对比：S11 的续费率是会员结构指标，走 `applyMemberScope()`（只算会员）。
     * 两者的分母**本就应当不同**，`scope` 键把这个差异显式暴露出来，避免被当成 bug。
     *
     * @return array<string, mixed>
     */
    private function computeAssetBuckets($u, string $venue): array
    {
        $deadlineBuckets = [
            'within30' => '30 天以内（含已过期）',
            '31to90' => '31-90 天',
            'over90' => '90 天以上',
            'unknown' => '到期日未知',
        ];
        $typeBuckets = ['count' => '次卡', 'time' => '期限卡', 'stored' => '储值卡', 'other' => '其它卡种'];
        $residueBuckets = ['positive' => '仍有余额', 'zero' => '余额已耗尽', 'unknown' => '余额未知'];

        $cells = [];
        foreach (array_keys($deadlineBuckets) as $d) {
            foreach (array_keys($typeBuckets) as $t) {
                $cells["{$d}|{$t}"] = ['deadlineBucket' => $d, 'cardType' => $t, 'cardCount' => 0];
            }
        }
        $byDeadline = array_fill_keys(array_keys($deadlineBuckets), 0);
        $byType = array_fill_keys(array_keys($typeBuckets), 0);
        $byResidue = array_fill_keys(array_keys($residueBuckets), 0);

        $totalCards = 0;
        $expiredCount = 0;

        foreach ($this->scopedCustomers($u, $venue)->get() as $c) {
            $cards = is_array($c->cards_list) ? $c->cards_list : [];
            foreach ($cards as $card) {
                if (! is_array($card)) {
                    continue;
                }
                $totalCards++;

                // ── 剩余期限 ──
                $deadline = $card['deadline'] ?? null;
                $days = null;
                if (is_string($deadline) && trim($deadline) !== '') {
                    try {
                        $days = (int) now()->startOfDay()->diffInDays(CarbonImmutable::parse($deadline)->startOfDay(), false);
                    } catch (\Throwable $e) {
                        $days = null; // 不可解析 ⇒ 归入「到期日未知」，不猜
                    }
                }
                if ($days === null) {
                    $deadlineKey = 'unknown';
                } elseif ($days <= 30) {
                    $deadlineKey = 'within30';
                    if ($days < 0) {
                        $expiredCount++;
                    }
                } elseif ($days <= 90) {
                    $deadlineKey = '31to90';
                } else {
                    $deadlineKey = 'over90';
                }

                // ── 卡种（上游 cardtype：1=次数卡 2=期限卡 3=储值卡 4=套餐卡）──
                $typeKey = match ((string) ($card['type'] ?? '')) {
                    '1' => 'count',
                    '2' => 'time',
                    '3' => 'stored',
                    default => 'other',
                };

                // ── 余额状态：null 是「未知」不是 0（与 helpers 的 unknownResidue 同判定）──
                $residue = $card['residue'] ?? null;
                $residueKey = $residue === null ? 'unknown' : ((float) $residue > 0 ? 'positive' : 'zero');

                $cells["{$deadlineKey}|{$typeKey}"]['cardCount']++;
                $byDeadline[$deadlineKey]++;
                $byType[$typeKey]++;
                $byResidue[$residueKey]++;
            }
        }

        $rows = [];
        $cellSum = 0;
        foreach ($cells as $cell) {
            $cellSum += $cell['cardCount'];
            $rows[] = [
                'deadlineBucket' => $cell['deadlineBucket'],
                'deadlineLabel' => $deadlineBuckets[$cell['deadlineBucket']],
                'cardType' => $cell['cardType'],
                'cardTypeLabel' => $typeBuckets[$cell['cardType']],
                'cardCount' => $cell['cardCount'],
                'ratio' => $totalCards > 0 ? round($cell['cardCount'] / $totalCards * 100, 2) : 0,
            ];
        }

        $summarize = fn (array $counts, array $labels) => array_values(array_map(
            fn ($key, $count) => ['key' => $key, 'label' => $labels[$key], 'cardCount' => $count,
                'ratio' => $totalCards > 0 ? round($count / $totalCards * 100, 2) : 0],
            array_keys($counts),
            array_values($counts)
        ));

        return [
            'buckets' => $rows,
            'byDeadline' => $summarize($byDeadline, $deadlineBuckets),
            'byType' => $summarize($byType, $typeBuckets),
            'byResidue' => $summarize($byResidue, $residueBuckets),
            'totalCards' => $totalCards,
            'unit' => 'card_count',
            'integrity' => [
                // 守恒断言的可读版本：前端与测试都能直接核对，避免「分桶漏卡」静默发生。
                // cellSum 为规范键名（下游 t3 已按此对接）；bucketsSum 是同值别名，
                // 保留是为了不让任何按旧名读取的消费方拿到 null。
                'cellSum' => $cellSum,
                'bucketsSum' => $cellSum,
                'totalCards' => $totalCards,
                'balanced' => $cellSum === $totalCards,
            ],
            'source' => 'customers.cards_list（有效卡明细；不含 card_stats.expiredCards 过期卡保留区）',
            // 人员范围显式暴露：预收负债视角，含 P5 但有卡的客户（与 S11 的会员分母不同口径）
            'scope' => '凡 customers.cards_list 非空者一律计入（含 layer=P5 但仍持有效卡的客户）'
                .'——本端点是预收负债视角而非会员结构视角，排除他们会让负债少报；'
                .'与 S11 的「只看会员」分母不同，属有意差异。',
            'expiredCardsIncluded' => $expiredCount,
            'note' => '本地卡片口径，单位=张数，不出金额：cards_list 的 residue 单位是节/天，本地不存在「剩余资产金额」换算逻辑；'
                .'与上游 remaining_assets_total（单位元，见 /ky/overview）不同源、不同量纲，不可互相校验；'
                .'已过期但仍有余量的卡不在 cards_list 内（见 KyMemberSyncService 的过期卡保留区），因此本分桶不等于全部未履约负债。',
        ];
    }

    /** GET /analytics/trial-conversion */
    public function trialConversion(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');

        return ok(Cache::remember(
            self::cacheKey('trial-conversion', $u, $venue, '', ''),
            60,
            fn () => $this->computeTrialConversion($u, $venue)
        ));
    }

    /**
     * S14 体验卡 → 会员卡转化率：按 `leads.service_teacher`（归属）分组的排行。
     *
     * ## 口径（用户 2026-09 拍板 → 队长 2026-09 裁定主指标为**按人**）
     *
     *  主口径 `conversionRate`（**按人**，与 `07-发展方向规划.md` §1.3 ②-5 原文的
     *  「人数」一致，也与同屏的 S11 续费率口径一致 —— 同一块看板上不出现
     *  「一个按人、一个按卡」的矛盾）：
     *
     *   - 分母 = 该老师名下**有过体验卡的人数**（按归一化手机号去重）
     *   - 分子 = 其中**至少有 1 张 `attended` 体验卡、且同手机号在 `customers` 里
     *     存在且 `layer != 'P5'`** 的人数
     *
     *  同时保留**卡口径**作参考，但**显式命名**为 `conversionRateByCard`
     *  （绝不复用 `conversionRate` 这个名字 —— 同名两义正是本仓库历史上
     *  「同一数字两页不同」的成因）。两者各自的分子分母同源，**不可交叉搭配**。
     *
     *  为什么决策单位是「人」：这个指标回答的是「这个会籍顾问把多少人转化成了
     *  会员」。按卡算会被「一人多节体验课」放大，同一个人来三次就变成三个分子，
     *  转化率虚高；按人算只有「转化没转化」一个答案。
     *
     * ## 体验卡读取走模型访问器
     *
     * `Lead::trialCards()` 会按数组位置补上缺失的 `session`（历史卡片没写这个键），
     * 并且**只在缺失时补、不重排**。注意它注册在**蛇形属性名** `trial_cards` 上
     * （Laravel 的 `Attribute` 访问器按列名解析），所以这里读 `$lead->trial_cards`
     * 才会命中该访问器；读 `$lead->trialCards` 拿到的是空数组（实测），
     * 那会让分母静默变成 0。不要改成直接 `json_decode($lead->getRawOriginal(...))`——
     * 绕过访问器就丢了 session 补全与合法性过滤（见该访问器的长注释）。
     *
     * ## 会员存在性判定的范围
     *
     * 「是否已成为会员」是一次**存在性**检查，按**门店级**收窄（`applyVenueScope`），
     * 不叠加 `scopeCustomersForUser` 的按人收窄：否则服务老师角色下，自己名下留资
     * 转化成的会员若归属写的是别人，会被判成「没转化」——那是归属问题，不是转化问题。
     * 输出本身只含聚合计数，不含客户标识。
     *
     * @return array<string, mixed>
     */
    private function computeTrialConversion($u, string $venue): array
    {
        // 会员手机号集合：只用于「同手机号是否已是正式会员」的存在性判定。
        // 归一化统一走 normalizePhone()，避免 `138-0000-0001` 这类分隔符导致同一人被判成两个。
        $memberPhones = [];
        $memberQ = applyVenueScope(
            Customer::query()->where('layer', '!=', 'P5'),
            $u, $venue
        );
        foreach ($memberQ->pluck('phone') as $phone) {
            $key = normalizePhone($phone);
            if ($key !== '') {
                $memberPhones[$key] = true;
            }
        }

        // 全局去重集合：同一个人可能同时挂在多个老师名下（留资被转派过），
        // 各老师行相加会重复计人，故整体分母/分子单独按人去重算，不用各行相加。
        $allTrialPhones = [];
        $allConvertedPhones = [];

        $teachers = [];
        foreach (applyVenueScope(Lead::query(), $u, $venue)->get() as $lead) {
            $teacher = trim((string) $lead->service_teacher);
            if ($teacher === '') {
                // 未归属的留资不能丢：丢了会让「分母合计」小于总量，
                // 而运营看到的恰恰是「谁名下还没归属」。单列一档而不是静默排除。
                $teacher = '未分配';
            }
            $teachers[$teacher] ??= [
                'teacher' => $teacher,
                'leads' => 0,
                'trialCards' => 0,
                'attendedCards' => 0,
                'convertedCards' => 0,
                // trialPhones：名下有体验卡的人（人口径的分母）
                // convertedPhones：其中已转化的人（人口径的分子）
                'trialPhones' => [],
                'convertedPhones' => [],
            ];
            $teachers[$teacher]['leads']++;

            $phoneKey = normalizePhone($lead->phone);
            $isMember = $phoneKey !== '' && isset($memberPhones[$phoneKey]);

            foreach ($lead->trial_cards as $card) {
                $teachers[$teacher]['trialCards']++;
                if ($phoneKey !== '') {
                    $teachers[$teacher]['trialPhones'][$phoneKey] = true;
                    $allTrialPhones[$phoneKey] = true;
                }
                if (empty($card['attended'])) {
                    continue;
                }
                $teachers[$teacher]['attendedCards']++;
                if ($isMember) {
                    $teachers[$teacher]['convertedCards']++;
                    if ($phoneKey !== '') {
                        $teachers[$teacher]['convertedPhones'][$phoneKey] = true;
                        $allConvertedPhones[$phoneKey] = true;
                    }
                }
            }
        }

        $rows = [];
        $totalCards = 0;
        $totalConvertedCards = 0;
        foreach ($teachers as $t) {
            $cards = $t['trialCards'];
            $convertedCards = $t['convertedCards'];
            $people = count($t['trialPhones']);
            $convertedPeople = count($t['convertedPhones']);
            $totalCards += $cards;
            $totalConvertedCards += $convertedCards;

            $byPerson = $people > 0 ? round($convertedPeople / $people * 100, 1) : 0;

            $rows[] = [
                'teacher' => $t['teacher'],
                'leads' => $t['leads'],
                // 卡口径只作过程量展示，不参与主指标
                'trialCards' => $cards,
                'attendedCards' => $t['attendedCards'],
                'convertedCards' => $convertedCards,
                'conversionRateByCard' => $cards > 0 ? round($convertedCards / $cards * 100, 1) : 0,
                // 人口径（**主口径**，键名与字段名一致可避免同名两义）
                'trialPeople' => $people,
                'convertedPeople' => $convertedPeople,
                'conversionRate' => $byPerson,
                // 兼容别名：值等同 conversionRate，供已按旧结构对接的消费方读取
                'conversionRateByPerson' => $byPerson,
            ];
        }

        // 排行：按**主指标**（人口径）倒序；同率按样本量大的在前（小样本档排前面会误导运营）
        usort($rows, function ($a, $b) {
            return [$b['conversionRate'], $b['trialPeople'], $a['teacher']]
                <=> [$a['conversionRate'], $a['trialPeople'], $b['teacher']];
        });

        $totalPeople = count($allTrialPhones);
        $totalConvertedPeople = count($allConvertedPhones);

        return [
            'rows' => $rows,
            'totalTrialCards' => $totalCards,
            'totalConvertedCards' => $totalConvertedCards,
            // 主指标的整体值（按人去重，不用各行相加——转派过的留资会在多个老师行里出现）
            'totalTrialPeople' => $totalPeople,
            'totalConvertedPeople' => $totalConvertedPeople,
            'overallConversionRate' => $totalPeople > 0 ? round($totalConvertedPeople / $totalPeople * 100, 1) : 0,
            'overallConversionRateByCard' => $totalCards > 0 ? round($totalConvertedCards / $totalCards * 100, 1) : 0,
            'memberPhones' => count($memberPhones),
            'formula' => [
                'conversionRate' => '同老师名下「至少有 1 张 attended 体验卡且同手机号已是正式会员（layer≠P5）」的人数 ÷ 该老师名下有过体验卡的人数（按手机号去重）——按人算，与 S11 续费率同口径',
                'conversionRateByCard' => '同老师名下「体验卡 attended 且同手机号已是正式会员」的卡数 ÷ 该老师名下体验卡总数（仅作参考，勿与人口径混用）',
                'overall' => '整体值按手机号全局去重（同一人挂在多个老师名下时只算一次），不等于各行相加',
                'attribution' => '归属取 leads.service_teacher（空值归入「未分配」，不丢弃）',
            ],
            'unit' => 'person',
            'note' => '主指标按人算（conversionRate），分母=该老师名下有过体验卡的人数，分子=其中已转化为正式会员的人数；'
                .'conversionRateByPerson 为同值别名；卡口径见 conversionRateByCard，仅供核对，勿与主指标并列展示。',
        ];
    }

    /** GET /analytics/trends */
    public function trends(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');
        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();

        return ok(Cache::remember(
            self::cacheKey('trends', $u, $venue, $start, $end),
            60,
            fn () => $this->computeTrends($u, $venue, $start, $end)
        ));
    }

    private function computeTrends($u, string $venue, string $start, string $end): array
    {
        $leadQ = applyVenueScope(Lead::query(), $u, $venue);

        $leads = (clone $leadQ)->whereBetween('lead_date', [$start, $end])->get();

        $byDate = [];
        // 同一批留资队列内按日期分桶：线上（新媒体登记来源）单独记一套，
        // 供新媒体工作台展示「留资 → 到店 → 成交」线上口径漏斗，与全店口径互不混淆。
        $bucket = function (string $date, string $venue, $l) use (&$byDate): void {
            $byDate[$date][$venue]['leads'] = ($byDate[$date][$venue]['leads'] ?? 0) + 1;
            $online = isOnlineLead($l);
            if ($online) {
                $byDate[$date][$venue]['online_leads'] = ($byDate[$date][$venue]['online_leads'] ?? 0) + 1;
            }
            // 已约体验及以上 → 计入约客/预约
            if (in_array($l->status, ['已约体验', '已体验', '已成交'], true)) {
                $byDate[$date][$venue]['booked'] = ($byDate[$date][$venue]['booked'] ?? 0) + 1;
            }
            // 已体验/已成交 → 计入体验（到店）
            if (in_array($l->status, ['已体验', '已成交'], true)) {
                $byDate[$date][$venue]['experienced'] = ($byDate[$date][$venue]['experienced'] ?? 0) + 1;
                if ($online) {
                    $byDate[$date][$venue]['online_experienced'] = ($byDate[$date][$venue]['online_experienced'] ?? 0) + 1;
                }
            }
            if ($online && $l->status === '已成交') {
                $byDate[$date][$venue]['online_deals'] = ($byDate[$date][$venue]['online_deals'] ?? 0) + 1;
            }
        };
        foreach ($leads as $l) {
            $d = (string) $l->lead_date;
            $bucket($d, $l->venue ?: '双店', $l);
        }

        // 售卡按成交发生时间统计；历史数据没有事件时间时兼容回退留资日期（日期下推 SQL，不再全历史拉取后 PHP 过滤）。
        $sales = (clone $leadQ)->where('status', '已成交')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('deal_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('deal_at')->whereBetween('lead_date', [$start, $end]));
            })->get();
        foreach ($sales as $sale) {
            $date = $sale->deal_at?->toDateString() ?: (string) $sale->lead_date;
            $saleVenue = $sale->venue ?: '双店';
            $byDate[$date][$saleVenue]['deals'] = ($byDate[$date][$saleVenue]['deals'] ?? 0) + 1;
        }

        // 售卡张数/金额：以随心瑜会员卡表落库的售卡事实为准，按售卡时间与实收金额统计，
        // 排除体验/赠卡（is_taste）与退卡；不再依赖工作台留资手动登记的成交卡项。
        $cardQ = applyVenueScope(
            KyCard::query()
                ->where('is_taste', false)
                ->where('status_format', '!=', '退卡')
                ->whereNotNull('sold_at')
                ->whereBetween('sold_at', [$start, $end]),
            $u, $venue
        );
        foreach ($cardQ->get() as $card) {
            $date = (string) $card->sold_at;
            $cardVenue = $card->venue ?: '双店';
            $byDate[$date][$cardVenue]['card_sales'] = ($byDate[$date][$cardVenue]['card_sales'] ?? 0) + 1;
            $byDate[$date][$cardVenue]['amount'] = ($byDate[$date][$cardVenue]['amount'] ?? 0) + (float) $card->deal_price;
        }
        $redeems = (clone $leadQ)->where('redeem_amount', '>', 0)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('redeemed_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('redeemed_at')->whereBetween('lead_date', [$start, $end]));
            })->get();
        foreach ($redeems as $redeem) {
            $date = $redeem->redeemed_at?->toDateString() ?: (string) $redeem->lead_date;
            $redeemVenue = $redeem->venue ?: '双店';
            $byDate[$date][$redeemVenue]['redeem'] = ($byDate[$date][$redeemVenue]['redeem'] ?? 0) + (float) $redeem->redeem_amount;
        }

        // 随心瑜体验预约是实际排课事实；按日、门店、人员去重后补足 CRM 留资状态统计。
        $bookingQ = applyVenueScope(
            KyBooking::query()->whereBetween('start_at', [$start.' 00:00:00', $end.' 23:59:59']),
            $u, $venue
        );
        $kyByDate = [];
        $bookings = $bookingQ->get();
        foreach ($bookings as $booking) {
            $date = $booking->start_at?->toDateString();
            if (! $date) {
                continue;
            }
            $identity = $booking->phone ?: ($booking->member_id ?: $booking->source_key);
            // 课型判定统一走模型访问器（列值优先，缺失时的兜底见 courseKindFrom）
            $kindKey = $booking->courseKind();
            if (! in_array($booking->status, ['cancelled', 'no_show'], true)) {
                $kyByDate[$date][$booking->venue]['booked'][$identity] = true;
                $kyByDate[$date][$booking->venue]['booked_'.$kindKey][$identity] = true;
            }
            if ($booking->status === 'signed') {
                $session = implode('|', [
                    $booking->venue,
                    $booking->start_at?->format('Y-m-d H:i'),
                    $booking->course_name,
                    $booking->teacher_name,
                    $kindKey,
                ]);
                $kyByDate[$date][$booking->venue]['classes'][$session] = true;
                $kyByDate[$date][$booking->venue]['classes_'.$kindKey][$session] = true;
                if ($booking->is_trial) {
                    $kyByDate[$date][$booking->venue]['experienced'][$identity] = true;
                }
            }
        }
        foreach ($kyByDate as $date => $venues) {
            foreach ($venues as $bookingVenue => $counts) {
                $byDate[$date][$bookingVenue]['booked'] = max(
                    $byDate[$date][$bookingVenue]['booked'] ?? 0,
                    count($counts['booked'] ?? [])
                );
                $byDate[$date][$bookingVenue]['experienced'] = max(
                    $byDate[$date][$bookingVenue]['experienced'] ?? 0,
                    count($counts['experienced'] ?? [])
                );
                $byDate[$date][$bookingVenue]['classes'] = count($counts['classes'] ?? []);
            }
        }
        for ($date = CarbonImmutable::parse($start); $date->lte(CarbonImmutable::parse($end)); $date = $date->addDay()) {
            $byDate[$date->toDateString()] = $byDate[$date->toDateString()] ?? [];
        }

        // ---- 到店人数与成交率（区间整体口径，按人去重） ----
        // 口径本身（谁是到店、谁是成交）统一由 VisitMetrics 提供：
        // 到店是三来源并集（预约签到体验课 / 留资状态 / 体验课卡片已上课），
        // 成交只算「到店过的人」里的成交，分子分母同源。
        // 老师工作台的个人漏斗调用同一份实现 —— 此前两处各写一份，改一处忘一处，
        // 结果是同一页面上老板与老师看到两个成交率。
        $identityOf = fn ($phone, string $fallback = '') => VisitMetrics::identityOf($phone, $fallback);

        // 线上身份集合：不限区间（上月留资、本月到店也要能认出线上来源）。
        // 同时记录每个身份的**最早「线上」留资日** —— 新媒体业绩要判「留资 → 到店/成交」
        // 是否在时效内，必须有这份配对；取最早线上留资而非最早任意留资：新客看的是
        // 「他通过新媒体进来的那一刻」（同一人先线下留资、后线上留资时，用线下那条会
        // 把窗口提前、误杀本该计入的新媒体新客）。
        $onlineIdentities = [];
        $onlineLeadDates = [];
        foreach ((clone $leadQ)->get(['phone', 'name', 'source', 'order_platform', 'lead_date']) as $l) {
            $identity = $identityOf($l->phone, (string) $l->name);
            if ($identity === '' || ! isOnlineLead($l)) {
                continue;
            }
            $onlineIdentities[$identity] = true;
            $day = (string) $l->lead_date;
            if ($day !== '' && (! isset($onlineLeadDates[$identity]) || $day < $onlineLeadDates[$identity])) {
                $onlineLeadDates[$identity] = $day;
            }
        }

        $visit = VisitMetrics::visitPairs($leadQ, $bookings, $start, $end);
        $visitIdentities = $visit['identities'];
        // 各来源命中的身份（仅供接口给出构成核对，同一人可能命中多个来源，不是相加关系）
        $visitSources = $visit['sources'];
        $visitDates = $visit['visitDates'];
        $onlineVisitIdentities = [];
        foreach ($visitIdentities as $identity => $_) {
            if (isset($onlineIdentities[$identity])) {
                $onlineVisitIdentities[$identity] = true;
            }
        }

        // dealPairs 与 dealSet 同一份实现，只是多给每人成交日期（时效配对用）；
        // 这里只查一次库，避免同一批成交被扫两遍。
        $deal = VisitMetrics::dealPairs($leadQ, $visitIdentities, $start, $end);
        $dealIdentities = $deal['identities'];
        $dealDates = $deal['dealDates'];
        $onlineDealIdentities = [];
        foreach ($dealIdentities as $identity => $_) {
            if (isset($onlineIdentities[$identity])) {
                $onlineDealIdentities[$identity] = true;
            }
        }

        // 客户到店分布（最近30天有到店记录）：同样按角色收窄，超管才吃 venue 参数
        $custQ = scopeCustomersForUser(Customer::query(), $u);
        if (userHasRole($u, 'R_SUPER') && $venue !== '') {
            $custQ->where('venue', $venue);
        }
        $visitQ = (clone $custQ)->where('last_visit', '>=', now()->subDays(30)->toDateString());
        $visit30 = $visitQ->count();
        $activeCustomers = (clone $custQ)->where('attend_m3', '>', 0)->count();
        $memberTotal = (clone $custQ)->where(function ($q) {
            $q->where('layer', '!=', 'P5')->orWhere('external_id', 'like', 'ky:%');
        })->count();

        $visitCount = count($visitIdentities);
        $dealCount = count($dealIdentities);

        $sumKey = fn ($k) => collect($byDate)->flatMap(fn ($vs) => collect($vs)->pluck($k))->sum();
        $totalLeads = $sumKey('leads');
        $totalBooked = $sumKey('booked');
        $totalExperienced = $sumKey('experienced');
        $totalDeals = $sumKey('deals');
        $totalCardSales = $sumKey('card_sales');
        $totalClasses = $sumKey('classes');
        $totalAmount = $sumKey('amount');
        $totalRedeem = $sumKey('redeem');

        // 线上新客口径汇总：留资、到店、成交三项同源（都取新媒体登记来源），
        // 成交率的分母是「线上到店」而非「线上留资」。
        $onlineLeadCount = (int) $sumKey('online_leads');
        $onlineVisitCount = count($onlineVisitIdentities);
        $onlineDealCount = count($onlineDealIdentities);

        // 新媒体线上运营业绩（到店奖励 + 核销提成）—— 只算**2 个月时效内**的线上新客。
        // 口径见 computeMediaPerformance() 的注释（含用户确认的分子分母同源要求）。
        $media = $this->computeMediaPerformance(
            $leadQ, $start, $end,
            $onlineIdentities, $onlineLeadDates,
            $onlineVisitIdentities, $visitDates,
            $onlineDealIdentities, $dealDates
        );

        // 到店人数的来源构成（仅供核对口径，不参与计算）：
        // 同一个人的身份可能同时命中多个来源，这里是各来源的去重人数，不是相加关系
        $visitBreakdown = [
            'fromBooking' => count($visitSources['booking']),
            'fromLeadStatus' => count($visitSources['leadStatus']),
            'fromTrialCard' => count($visitSources['trialCard']),
            'total' => $visitCount,
            'onlineTotal' => $onlineVisitCount,
        ];

        // 预约/上课班次按私教 / 小班 / 团课拆分
        $privateBooked = 0;        $smallBooked = 0;
        $groupBooked = 0;
        $privateClasses = 0;
        $smallClasses = 0;
        $groupClasses = 0;
        foreach ($kyByDate as $venues) {
            foreach ($venues as $counts) {
                $privateBooked += count($counts['booked_private'] ?? []);
                $smallBooked += count($counts['booked_small'] ?? []);
                $groupBooked += count($counts['booked_group'] ?? []);
                $privateClasses += count($counts['classes_private'] ?? []);
                $smallClasses += count($counts['classes_small'] ?? []);
                $groupClasses += count($counts['classes_group'] ?? []);
            }
        }

        return [
            'daily' => array_values(collect($byDate)->sortKeys()->map(function ($venues, $date) {
                $out = ['date' => $date];
                foreach ($venues as $v => $c) {
                    $out[$v] = [
                        'leads' => $c['leads'] ?? 0,
                        'booked' => $c['booked'] ?? 0,
                        'experienced' => $c['experienced'] ?? 0,
                        'deals' => $c['deals'] ?? 0,
                        'cardSales' => $c['card_sales'] ?? 0,
                        'classes' => $c['classes'] ?? 0,
                        'amount' => round((float) ($c['amount'] ?? 0), 2),
                        'redeem' => round((float) ($c['redeem'] ?? 0), 2),
                        // 线上（新媒体登记来源）单独一套，供新媒体工作台做同口径漏斗
                        'onlineLeads' => $c['online_leads'] ?? 0,
                        'onlineVisits' => $c['online_experienced'] ?? 0,
                        'onlineDeals' => $c['online_deals'] ?? 0,
                    ];
                }

                return $out;
            })->all()),
            'summary' => [
                'memberTotal' => $memberTotal,
                'leadCount' => $totalLeads,
                'bookingCount' => $totalBooked,
                // 到店 = 区间内到店体验过的人数（同一人来多次只算一个人）
                'visitCount' => $visitCount,
                'visitBreakdown' => $visitBreakdown,
                'trialCount' => $visitCount,
                // 成交 = 上述到店人数里的成交人数，分子分母同源
                'dealCount' => $dealCount,
                'cardSalesCount' => $totalCardSales,
                'classCount' => $totalClasses,
                'privateBookingCount' => $privateBooked,
                'smallBookingCount' => $smallBooked,
                'groupBookingCount' => $groupBooked,
                'privateClassCount' => $privateClasses,
                'smallClassCount' => $smallClasses,
                'groupClassCount' => $groupClasses,
                'dealAmount' => round((float) $totalAmount, 2),
                'redeemAmount' => round((float) $totalRedeem, 2),
                // 留资登记成交（按成交日 deal_at 落在窗口、含全部来源）：改一笔留资成交即联动，区别于上方按售卡实收的 dealAmount
                'registeredDealCount' => $sales->count(),
                'registeredDealAmount' => round((float) $sales->sum('deal_amount'), 2),
                'dealRate' => $visitCount > 0 ? round($dealCount / $visitCount * 100, 1) : 0,
                'leadToVisitRate' => $totalLeads > 0 ? min(100, round($visitCount / $totalLeads * 100, 1)) : 0,
                // 线上新客口径（新媒体登记来源：美团/大众点评/抖音/小红书/视频号/线上/团购）：
                // 留资、到店、成交三项都只算线上，成交率 = 线上成交 ÷ 线上到店（不是除以留资人数）。
                'onlineLeadCount' => $onlineLeadCount,
                'onlineVisitCount' => $onlineVisitCount,
                'onlineDealCount' => $onlineDealCount,
                'onlineDealRate' => $onlineVisitCount > 0 ? round($onlineDealCount / $onlineVisitCount * 100, 1) : 0,
                'onlineLeadToVisitRate' => $onlineLeadCount > 0 ? min(100, round($onlineVisitCount / $onlineLeadCount * 100, 1)) : 0,
                // 新媒体线上运营业绩（到店奖励 + 核销提成，仅时效内线上新客）
                'mediaPerformance' => $media,
            ],
            'visit30' => $visit30,
            'activeCustomers' => $activeCustomers,
            // 出勤口径：三个连续且等长的 30 天滚动窗口（再前30天 / 前30天 / 近30天）
            'attendanceSummary' => [
                'm1' => (clone $custQ)->where('attend_m1', '>', 0)->count(),
                'm2' => (clone $custQ)->where('attend_m2', '>', 0)->count(),
                'm3' => $activeCustomers,
            ],
            'period' => ['start' => $start, 'end' => $end],
        ];
    }

    /**
     * 新媒体线上运营业绩：**时效内**线上新客的到店奖励 + 核销提成。
     *
     * ## 业务规则（用户确认，2026-09）
     *
     *  1. **2 个月时效**：留资月 + 下一个自然月内到店/成交才算新媒体新客。
     *     例：9.1 留资 → 10.31 前到店或成交有效（`mediaValidMonths` 默认 2）。
     *  2. **到店奖励** = 当月**时效内**到店的线上新客人数 × `mediaVisitReward`（默认 20 元/人）。
     *     含两类人：本月留资本月到店的 + 上月留资本月到店的（只要仍在时效内）。
     *  3. **核销提成** = 当月有效成交率 × 当月**时效内**线上核销金额，
     *     其中有效成交率 = 当月有效成交人数 ÷ 当月有效到店人数。
     *  4. **线下渠道完全不算**：三项都先经 `isOnlineLead()` 过滤。
     *
     * ## ⚠️ 分母为什么含「上月留资本月到店」的人（易记错，改动前务必读）
     *
     * 用户给的自验例子：10 月留资 20 / 到店 10 / 成交 6，另有 9 月的 2 个客资在 10 月到店
     * （其中 1 个成交）：
     *
     *     到店奖励 = (10 + 2) × 20 = 240 元
     *     核销提成 = (6 + 1) ÷ (10 + 2) × 核销金额 = 7 ÷ 12 × 核销金额
     *
     * 用户口述里曾写作 `7/10`，但他另一句话是「到店 10+2」—— **分子加了上月的 1 个成交、
     * 分母却没加上月的 2 个到店**，自相矛盾。经**二次确认，分母用 12**（分子分母同源：
     * 分子只能是「有效到店人里的成交」，分母就是同一批有效到店人）。
     * 若有人改回 `/10`，`test_media_performance_matches_users_worked_example` 会红。
     *
     * ## 边界策略（失败关闭）
     *
     *  - 找不到（线上）留资日 ⇒ 不计奖励/不算有效（无法核对时效，宁可少算不漏算）；
     *  - 到店/成交日期缺失 ⇒ 同上；这类计数在 `unpairedCount` 单列，供运营发现数据异常，
     *    而不是让金额悄悄变小。
     *
     * @return array<string, mixed>
     */
    private function computeMediaPerformance(
        $leadQ, string $start, string $end,
        array $onlineIdentities, array $onlineLeadDates,
        array $onlineVisitIdentities, array $visitDates,
        array $onlineDealIdentities, array $dealDates
    ): array {
        $params = mediaPerformanceParams();
        $validMonths = $params['validMonths'];
        $reward = $params['visitReward'];

        $within = fn (string $identity, array $eventDates) => VisitMetrics::isWithinValidity(
            $onlineLeadDates[$identity] ?? null,
            $eventDates[$identity] ?? null,
            $validMonths
        );

        // ---- 有效到店（当月到店 ∩ 线上 ∩ 时效内）----
        $validVisitIdentities = [];
        $validVisitsFromPrevMonth = 0; // 其中：上月留资、本月到店（用户例子里的 +2 这类）
        $unpairedVisitCount = 0;
        $startMonth = substr($start, 0, 7);
        foreach ($onlineVisitIdentities as $identity => $_) {
            if (! isset($onlineLeadDates[$identity]) || ! isset($visitDates[$identity])) {
                $unpairedVisitCount++; // 留资日或到店日缺失 ⇒ 无法核对时效
                continue;
            }
            if (! $within($identity, $visitDates)) {
                continue; // 越期，不算新媒体新客
            }
            $validVisitIdentities[$identity] = true;
            if (substr($onlineLeadDates[$identity], 0, 7) !== $startMonth) {
                $validVisitsFromPrevMonth++;
            }
        }
        $validVisitCount = count($validVisitIdentities);

        // ---- 有效成交（当月成交 ∩ 线上 ∩ 时效内 ∩ 有效到店人）----
        $validDealIdentities = [];
        $validDealsFromPrevMonth = 0;
        $unpairedDealCount = 0;
        foreach ($onlineDealIdentities as $identity => $_) {
            if (! isset($onlineLeadDates[$identity]) || ! isset($dealDates[$identity])) {
                $unpairedDealCount++;
                continue;
            }
            if (! $within($identity, $dealDates)) {
                continue;
            }
            $validDealIdentities[$identity] = true;
            if (substr($onlineLeadDates[$identity], 0, 7) !== $startMonth) {
                $validDealsFromPrevMonth++;
            }
        }
        $validDealCount = count($validDealIdentities);

        // ---- 时效内线上核销金额 ----
        // 「当月核销」按核销事件日归期（redeemed_at，缺失回退留资日，与看板其它统计同口径），
        // 再叠加时效：只有 2 个月窗口内的线上核销才算进提成基数。
        $redeemRows = (clone $leadQ)->where('redeem_amount', '>', 0)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('redeemed_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('redeemed_at')->whereBetween('lead_date', [$start, $end]));
            })->get(['phone', 'name', 'source', 'order_platform', 'lead_date', 'redeemed_at', 'redeem_amount']);

        $validRedeemAmount = 0.0;
        $excludedRedeemAmount = 0.0;
        foreach ($redeemRows as $row) {
            $identity = VisitMetrics::identityOf($row->phone, (string) $row->name);
            $eventDate = $row->redeemed_at?->toDateString() ?: (string) $row->lead_date;
            $amount = (float) $row->redeem_amount;
            // 线下来源与越期核销都不计；分别归入排除额，便于运营核对差额
            if (! isset($onlineIdentities[$identity]) || ! $within($identity, [$identity => $eventDate])) {
                $excludedRedeemAmount += $amount;
                continue;
            }
            $validRedeemAmount += $amount;
        }

        $visitRewardAmount = round($validVisitCount * $reward, 2);
        $dealRate = $validVisitCount > 0 ? $validDealCount / $validVisitCount : 0.0;
        $commissionAmount = round($dealRate * $validRedeemAmount, 2);

        return [
            'enabled' => true,
            'params' => [
                'visitReward' => $reward,
                'validMonths' => $validMonths,
                'rule' => "留资月 + 下一个自然月内到店/成交有效（{$validMonths} 个月时效）",
            ],
            // 三项显示值
            'visitRewardAmount' => $visitRewardAmount,
            'dealRate' => round($dealRate * 100, 2),
            'commissionAmount' => $commissionAmount,
            // 构成明细（让运营能自己把账对上）
            'breakdown' => [
                'validVisitCount' => $validVisitCount,
                'validVisitsFromPrevMonth' => $validVisitsFromPrevMonth,
                'validDealCount' => $validDealCount,
                'validDealsFromPrevMonth' => $validDealsFromPrevMonth,
                'validRedeemAmount' => round($validRedeemAmount, 2),
                'excludedRedeemAmount' => round($excludedRedeemAmount, 2),
                // 无法核对时效的条数（留资日/到店日缺失）—— 单列以免金额静默变小
                'unpairedVisitCount' => $unpairedVisitCount,
                'unpairedDealCount' => $unpairedDealCount,
            ],
            // 口径说明：页面上要能自证，避免运营按记忆里的 /10 核对不上
            'formula' => [
                'visitReward' => "有效到店人数（{$validVisitCount}）× {$reward} 元",
                'dealRate' => "有效成交人数（{$validDealCount}）÷ 有效到店人数（{$validVisitCount}）",
                'commission' => "成交率（{$validDealCount}/{$validVisitCount}）× 时效内核销金额",
            ],
        ];
    }

    /** GET /analytics/channels */
    public function channels(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');
        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();

        return ok(Cache::remember(
            self::cacheKey('channels', $u, $venue, $start, $end),
            60,
            fn () => $this->computeChannels($u, $venue, $start, $end)
        ));
    }

    private function computeChannels($u, string $venue, string $start, string $end): array
    {
        $leadQ = applyVenueScope(Lead::query(), $u, $venue);
        $leads = $leadQ->whereBetween('lead_date', [$start, $end])->get();

        // S13 渠道四列（核销率 / 成交率 / 客单价）复用的两路口径与 computePlatforms() **完全同源**：
        // 成交按 deal_at 归期、核销按 redeemed_at 归期，时间缺失时回退 lead_date。
        // 不在本方法里另写一套「按 lead_date 过滤」——那会让同一笔成交在
        // channels 与 platforms 两个端点上落到不同月份。
        $salesQ = applyVenueScope(Lead::query(), $u, $venue);
        $sales = $salesQ->where('status', '已成交')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('deal_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('deal_at')->whereBetween('lead_date', [$start, $end]));
            })->get();
        $redeemQ = applyVenueScope(Lead::query(), $u, $venue);
        $redeems = $redeemQ->where('redeem_amount', '>', 0)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('redeemed_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('redeemed_at')->whereBetween('lead_date', [$start, $end]));
            })->get();

        $channelOf = fn ($l) => trim((string) $l->source) !== '' ? $l->source : '其他';

        $channels = [];
        foreach ($leads as $l) {
            $src = $channelOf($l);
            $channels[$src] = $channels[$src] ?? ['leads' => 0, 'deals' => 0, 'redeemCount' => 0, 'dealAmount' => 0.0, 'redeemAmount' => 0.0];
            $channels[$src]['leads']++;
        }
        foreach ($sales as $sale) {
            $src = $channelOf($sale);
            $channels[$src] = $channels[$src] ?? ['leads' => 0, 'deals' => 0, 'redeemCount' => 0, 'dealAmount' => 0.0, 'redeemAmount' => 0.0];
            $channels[$src]['deals']++;
            $channels[$src]['dealAmount'] += (float) $sale->deal_amount;
        }
        foreach ($redeems as $redeem) {
            $src = $channelOf($redeem);
            $channels[$src] = $channels[$src] ?? ['leads' => 0, 'deals' => 0, 'redeemCount' => 0, 'dealAmount' => 0.0, 'redeemAmount' => 0.0];
            $channels[$src]['redeemCount']++;
            $channels[$src]['redeemAmount'] += (float) $redeem->redeem_amount;
        }

        // 排序保持既有口径（按留资数倒序），不得改成按四列里的新列排 —— 已有前端在消费顺序
        uasort($channels, fn ($a, $b) => $b['leads'] <=> $a['leads']);

        $rows = [];
        foreach ($channels as $name => $v) {
            $rows[] = [
                // ── 既有三键：原样保留（前端已在消费，只能加不能改）──
                'channel' => $name,
                'leads' => $v['leads'],
                // ── S13 新增四列 ──
                // 核销率：该渠道有核销记录的留资数 ÷ 该渠道留资数。
                // ⚠️ 分母用「留资数」是**代理口径**（每条线上留资对应一张团购券的登记）：
                //    本地没有「券售出数」这个独立事实列，故不假装它是严格售出量。
                'redeemCount' => $v['redeemCount'],
                'redeemRate' => $v['leads'] > 0 ? round($v['redeemCount'] / $v['leads'] * 100, 1) : 0,
                'redeemAmount' => round($v['redeemAmount'], 2),
                // 成交率：按成交事件归期统计的成交条数 ÷ 该渠道留资数（与 platforms 的 deal 同源）
                'deals' => $v['deals'],
                'dealRate' => $v['leads'] > 0 ? round($v['deals'] / $v['leads'] * 100, 1) : 0,
                // 客单价：成交金额 ÷ 成交条数（不是除以留资数）
                'dealAmount' => round($v['dealAmount'], 2),
                'avgDealAmount' => $v['deals'] > 0 ? round($v['dealAmount'] / $v['deals'], 2) : 0,
            ];
        }

        return [
            'rows' => $rows,
            'total' => $leads->count(),
            // 四列的合计与整体比率（与 rows 逐个相加一致，供前端做合计行）
            'summary' => [
                'leads' => $leads->count(),
                'deals' => $sales->count(),
                'redeemCount' => $redeems->count(),
                'dealAmount' => round((float) $sales->sum('deal_amount'), 2),
                'redeemAmount' => round((float) $redeems->sum('redeem_amount'), 2),
                'dealRate' => $leads->count() > 0 ? round($sales->count() / $leads->count() * 100, 1) : 0,
                'redeemRate' => $leads->count() > 0 ? round($redeems->count() / $leads->count() * 100, 1) : 0,
                'avgDealAmount' => $sales->count() > 0 ? round((float) $sales->sum('deal_amount') / $sales->count(), 2) : 0,
            ],
            'formula' => [
                'redeemRate' => '核销留资数 ÷ 该渠道留资数（本地无独立「券售出数」列，故以留资数为代理分母）',
                'dealRate' => '成交条数（按 deal_at 归期，缺失回退 lead_date）÷ 该渠道留资数',
                'avgDealAmount' => '成交金额合计 ÷ 成交条数',
            ],
            'period' => ['start' => $start, 'end' => $end],
        ];
    }

    /** GET /analytics/platforms */
    public function platforms(Request $r)
    {
        $u = $r->user();
        $venue = (string) $r->query('venue', '');
        abort_unless($venue === '' || in_array($venue, ['绿地店', '东部店'], true), 422, '门店参数无效');
        $start = $r->query('start') ?: now()->startOfMonth()->toDateString();
        $end = $r->query('end') ?: now()->toDateString();

        return ok(Cache::remember(
            self::cacheKey('platforms', $u, $venue, $start, $end),
            60,
            fn () => $this->computePlatforms($u, $venue, $start, $end)
        ));
    }

    private function computePlatforms($u, string $venue, string $start, string $end): array
    {
        $leadQ = applyVenueScope(Lead::query(), $u, $venue);
        $leadCohort = (clone $leadQ)->whereBetween('lead_date', [$start, $end])->get();
        $sales = (clone $leadQ)->where('status', '已成交')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('deal_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('deal_at')->whereBetween('lead_date', [$start, $end]));
            })->get();
        $redeems = (clone $leadQ)->where('redeem_amount', '>', 0)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('redeemed_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->orWhere(fn ($q2) => $q2->whereNull('redeemed_at')->whereBetween('lead_date', [$start, $end]));
            })->get();

        $platforms = [];
        foreach ($leadCohort as $l) {
            $platform = trim((string) $l->order_platform) !== '' ? $l->order_platform : (trim((string) $l->source) !== '' ? $l->source : '其他');
            $platforms[$platform] = $platforms[$platform] ?? ['redeem' => 0, 'deal' => 0, 'leads' => 0];
            $platforms[$platform]['leads'] += 1;
        }
        foreach ($sales as $sale) {
            $platform = trim((string) $sale->order_platform) !== '' ? $sale->order_platform : (trim((string) $sale->source) !== '' ? $sale->source : '其他');
            $platforms[$platform] = $platforms[$platform] ?? ['redeem' => 0, 'deal' => 0, 'leads' => 0];
            $platforms[$platform]['deal'] += (float) $sale->deal_amount;
        }
        foreach ($redeems as $redeem) {
            $platform = trim((string) $redeem->order_platform) !== '' ? $redeem->order_platform : (trim((string) $redeem->source) !== '' ? $redeem->source : '其他');
            $platforms[$platform] = $platforms[$platform] ?? ['redeem' => 0, 'deal' => 0, 'leads' => 0];
            $platforms[$platform]['redeem'] += (float) $redeem->redeem_amount;
        }

        $rows = [];
        foreach ($platforms as $name => $v) {
            $rows[] = [
                'platform' => $name,
                'redeem' => round((float) $v['redeem'], 2),
                'deal' => round((float) $v['deal'], 2),
                'leads' => $v['leads'],
            ];
        }
        usort($rows, fn ($a, $b) => $b['deal'] + $b['redeem'] <=> $a['deal'] + $a['redeem']);

        return [
            'rows' => $rows,
            'totalDeal' => round((float) $sales->sum('deal_amount'), 2),
            'totalRedeem' => round((float) $redeems->sum('redeem_amount'), 2),
            'dealCount' => $sales->count(),
        ];
    }
}
