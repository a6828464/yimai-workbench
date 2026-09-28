<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /**
     * 归属列双写：姓名字段随请求进来，这里补上对应的 user id。
     *
     * id 不随改名变化、也不怕同名，是归属判断的长期依据（姓名字段保留用于展示，
     * 同时兜住"姓名对不上任何账号"的历史行）。详见 helpers.php 的 staffUserId()。
     */
    private function withStaffIds(array $values): array
    {
        // 顶层 trial_teacher 是旧版"单节"模型留下的字段，现在老师实际填在**逐节卡片**
        // (trial_cards[].teacher) 里。这里把第一节的老师镜像过来，两个原因：
        //   1. 列表/其它读取方仍按顶层字段取值，不镜像就一直是空的；
        //   2. 老师的**归属与可见性**依赖它（scopeLeadsForUser 用 trial_teacher 判断
        //      "这条留资是不是我上的课"）—— 只填卡片的话，老师看不到自己上过的课。
        // 跟着卡片走：卡片里改了老师，这里同步改；卡片全空则保持原值，不清空。
        if (array_key_exists('trial_cards', $values)) {
            foreach ((array) $values['trial_cards'] as $card) {
                $teacher = trim((string) (is_array($card) ? ($card['teacher'] ?? '') : ''));
                if ($teacher !== '') {
                    $values['trial_teacher'] = $teacher;
                    break;
                }
            }
        }

        foreach (['service_teacher' => 'service_teacher_user_id',
            'trial_teacher' => 'trial_teacher_user_id',
            'created_by' => 'created_by_user_id'] as $nameCol => $idCol) {
            if (array_key_exists($nameCol, $values)) {
                $values[$idCol] = staffUserId((string) $values[$nameCol]);
            }
        }

        return $values;
    }

    /** 手机号落库前归一（纯数字）：与同步入库、全站比对口径保持一致 */
    private function withNormalizedPhone(array $values): array
    {
        if (array_key_exists('phone', $values)) {
            $values['phone'] = normalizePhone($values['phone']);
        }

        return $values;
    }

    /**
     * 可写字段白名单：写入走 `array_intersect_key(camelToSnake($r->all()), array_flip($this->leadFields))`，
     * 不在这里登记的字段会被**静默丢弃**。加字段必须同时来这儿补一行。
     *
     * `deal_at`（成交时间）此前只存在于「状态变已成交时自动写 now()」这一条路径上，
     * 用户手动填的成交时间因为不在白名单里被整列丢掉 —— 补录历史成交日期永远存不进去。
     */
    private array $leadFields = ['lead_date', 'name', 'phone', 'wechat', 'demand', 'source', 'order_platform', 'referrer', 'venue', 'service_teacher', 'status', 'grade', 'trial_time', 'trial_topic', 'trial_teacher', 'deal_card', 'deal_amount', 'deal_at', 'redeem_amount', 'voucher_code', 'coupon_name', 'coupon_total', 'coupon_remaining', 'trial_cards', 'remark'];

    /**
     * `source` / `order_platform` 的**受控枚举校验规则**（S15 / 渠道枚举收口）。
     *
     * 取值来自 helpers.php 的 `LEAD_SOURCES` / `ORDER_PLATFORMS` —— 那两份常量是
     * 渠道清单的**唯一来源**，这里只做「取用 + 拼规则」，不得在此另写一份清单
     * （历史上前端一份、统计一份、导出一份，加渠道漏改一处就静默出错）。
     *
     * `Rule::in()` + `leadEnumMessage()` 的组合是为了让 422 的错误文案**列出允许值**。
     * 用 `'in:'.implode(',', ...)` 的字符串写法不行：渠道名里可能含逗号，且 Laravel
     * 的字符串式 `in` 会把中文错误信息拼成无法阅读的长串；数组形式 + 显式 message
     * 则稳定可控。
     *
     * ⚠ **只用于写入路径**（store / update）。读取路径（index / show / 导出 / 统计）
     * 一律不得调用本方法 —— 生产库里有枚举外的历史值（`到店`/`测试`/`KeepYoga`/
     * `抖音周年庆直播`…），它们是真实历史而非脏数据，读侧拒绝等于把历史记录锁死。
     *
     * ## 更新路径必须允许「回流既有值」（否则枚举会变成单向闸门）
     *
     * PATCH 提交的是**整个表单**（前端编辑弹窗的既有行为），所以一条来源为
     * `到店` 的历史留资只要被打开、改个备注再保存，请求里就会带上 `source=到店`。
     * 如果 update 对 `source` 严格 `in:LEAD_SOURCES`，这条历史记录就**再也存不回去**
     * ——店长只是改了个备注，却被告知「来源非法」，而且**无法自救**（下拉框里没有
     * 这个选项，改任何值都是在篡改真实渠道）。
     *
     * 所以 update 的语义是：**改动才校验**。
     *   - 提交值与库里现值**相同** ⇒ 视为「原样带回」，放行（历史值因此永远可以
     *     被继续保存，枚举不会把老数据锁死）；
     *   - 提交值与现值**不同**（含首次从空值写入）⇒ 必须落在枚举内，否则 422。
     * 这正是「校验只作用于写入」的准确含义：**拦的是新增/改写，不是存量本身**。
     */
    private function channelRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'source' => [$presence, 'string', Rule::in(LEAD_SOURCES)],
            'orderPlatform' => ['sometimes', 'nullable', 'string', Rule::in(ORDER_PLATFORMS)],
        ];
    }

    /** 渠道枚举的 422 文案（允许值原样列出，见 leadEnumMessage()） */
    private function channelMessages(): array
    {
        return [
            'source.in' => leadEnumMessage('来源', LEAD_SOURCES),
            'orderPlatform.in' => leadEnumMessage('下单平台', ORDER_PLATFORMS),
        ];
    }

    /**
     * `referrer`（介绍人）校验：**只做长度约束，不做存不存在校验**。
     *
     * 介绍人是**手填的姓名**，不是账号引用：老会员可能早已不再来、也可能是
     * 非会员的熟人（朋友介绍）。去校验「介绍人必须是系统里的会员」会把最真实的
     * 那部分转介绍堵在门外，也会把一次录入变成一次查库往返。
     * 列宽 50（见迁移）就是这里 max:50 的依据，两处必须同步改。
     */
    private function referrerRules(): array
    {
        return ['referrer' => ['sometimes', 'nullable', 'string', 'max:50']];
    }

    /**
     * 成交时间该不该自动补 now()。只有两条主张，但第 2 条判定写错就会出事：
     *
     *  1. 请求里带了**非空**成交时间 ⇒ 一律以用户值为准，绝不被 now() 覆盖
     *     （用户补录 8 月的成交，不能被改写成今天）；
     *  2. 请求里没带 / 带了空值，且状态为「已成交」⇒ 仍自动写 now()，这是**既有行为不得回归**。
     *     前端提交的是整个表单，成交时间这个键**恒存在**（未填时为 null），所以判定只能看
     *     「有没有非空值」，不能看「键在不在」—— 看键会把既有兜底整个关掉。
     *
     * 「用户显式清空」（键在且为 null）**不需要单独处理**：末行只在本行原来为空时才补，
     * 原来有值一律不补，清空照样生效。这里曾经多写过一个「键在且为 null 且原来有值就 return false」
     * 的分支，实测是**死代码**（删掉它 15 个用例全绿），已移除。
     *
     * 与既有 `redeem` 兜底一致：只在「原来为空」时补，已有值不动。
     */
    private function shouldAutoFillDealAt(array $values, ?string $existingDealAt): bool
    {
        if (($values['status'] ?? null) !== '已成交') {
            return false;
        }
        if (trim((string) ($values['deal_at'] ?? '')) !== '') {
            return false; // 主张 1：用户值优先
        }

        return $existingDealAt === null; // 主张 2：只在原来为空时补
    }

    /**
     * 列表排序：**按留资时间（`lead_date`）倒序**，同日按 id 倒序。
     *
     * ## 为什么排序必须由服务端拥有
     *
     * 列表是**服务端分页**的（`forPage`，一页 20/50/100/200 条）。前端 `ElTableColumn`
     * 的 `sortable` 只在**当前页这几十条**里重排 —— 分页之后各页之间不可能有序，
     * 用户点列头看到的是「这一页碰巧的顺序」，点出升序更是只把当前页反过来，
     * 看起来像排好了。真正的排序只能在**分页之前**做，也就是这里。
     *
     * ## 为什么基准是 lead_date 而不是 id（历史实现）
     *
     * 历史实现写死 `orderByDesc('id')`，即**按录入先后**排。留资页的常态恰恰是
     * **事后补录**（体测报告、团购券核销、老客资回填），补录一条 8 月的客资会排在最前，
     * 而 9 月月底留的客户被挤到后面 —— 用户口径是「按留资时间排序」，两者在补录时背离。
     *
     * ## tie-break
     *
     * 同日多条必须再用 `id` 定序：MySQL 对 `ORDER BY` 相同值的返回顺序**不作保证**，
     * 缺了这一级，翻页时同一行可能重复出现或被整段跳过。id 单调且唯一，能保证全序。
     *
     * ## 排序参数的边界
     *
     * `sortBy` 走**白名单**（当前只放行 `leadDate`）：未知值一律退回默认口径，
     * 既不做列名拼接（那是注入面），也不报错 —— 前端传了个后端不认识的列名时，
     * 退回默认顺序比 400 更合适。
     */
    private function applySort($q, Request $r)
    {
        // 白名单：请求字段名 => 库表列名。新增可排序列就在这加一行 —— 列名**绝不来自请求**。
        $columns = ['leadDate' => 'lead_date'];
        $order = strtolower((string) $r->query('sortOrder', 'descending')) === 'ascending' ? 'asc' : 'desc';
        $column = $columns[(string) $r->query('sortBy', 'leadDate')] ?? $columns['leadDate'];

        return $q->orderBy($column, $order)->orderBy('id', $order);
    }

    /** GET /leads */
    public function index(Request $r)
    {
        $u = $r->user();
        // 按人隔离统一走 scopeLeadsForUser：服务老师＝本人名下＋待承接池；
        // 授课老师＝本人客资＋本人上过体验课的＋本人私教学员的；新媒体＝本人录入的。
        // 这里不再按角色分支调用 —— 漏掉任何一个角色都等于把全量客资交出去。
        $q = scopeLeadsForUser(Lead::query(), $u);
        if ($n = $r->query('name')) {
            $q->where('name', 'like', "%{$n}%");
        }
        if ($v = $r->query('venue')) {
            $q->where('venue', $v);
        }
        if ($s = $r->query('status')) {
            $q->where('status', $s);
        }
        // 联系方式：手机号 / 电话尾号 / 微信
        if ($c = trim((string) $r->query('phone', ''))) {
            $digits = normalizePhone($c);
            $q->where(function ($w) use ($c, $digits) {
                $w->where('phone', 'like', "%{$c}%")
                    ->orWhere('wechat', 'like', "%{$c}%");
                if ($digits !== '' && $digits !== $c) {
                    // 输入带分隔符时库里存的是纯数字，再按数字形态命中一次
                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }
        // 留资日期范围
        if ($df = $r->query('dateFrom')) {
            $q->where('lead_date', '>=', $df);
        }
        if ($dt = $r->query('dateTo')) {
            $q->where('lead_date', '<=', $dt);
        }
        // 服务端分页：避免全量拉取 + 内存切片导致超量数据被静默截断
        // size 上限放宽到 5000，兼容前端顾问匹配一次性拉全量留资的场景（超出再逐步下推后端）
        $current = max(1, (int) $r->query('current', 1));
        $size = min(5000, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $this->applySort($q, $r)->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok([
            'records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size,
            // 渠道枚举随列表一并下发（**纯新增键**，既有消费方不受影响）。
            // 这是本任务唯一允许的「让前端读到权威清单」的通道：routes/api.php
            // 不在本任务的改动范围内，所以不新建 /leads/options 之类的端点，
            // 而是把两个常量挂到前端**已经在调用**的列表响应上。
            // 前端改造见 t3：删掉自备的 SOURCE_OPTIONS/PLATFORM_OPTIONS，改读这里。
            'enums' => [
                'sources' => LEAD_SOURCES,
                'orderPlatforms' => ORDER_PLATFORMS,
            ],
        ]);
    }

    /** GET /leads/check：新增留资时校验手机号是否已命中会员 / 已有留资 */
    public function check(Request $r)
    {
        $raw = trim((string) $r->query('phone', ''));
        if ($raw === '') {
            return ok(['exists' => false, 'matches' => []]);
        }
        // 归一后再查：录入时带分隔符（138-0000-0001）不应该让查重静默漏报。
        // 原始形态一并查，是为了兼容历史里按带分隔符写库的行。
        $forms = array_values(array_unique(array_filter([normalizePhone($raw), $raw])));

        $matches = [];
        foreach (Customer::whereIn('phone', $forms)->get() as $c) {
            // kind 用「前端客资」谓词（P5 且非 ky: 来源），不能只看 layer：
            // 卡项全部过期的**正式会员**也落 P5，直接按 layer 判会把会员标成「留资」，
            // 于是录入时提示「这个人已是留资」，实际上他早就是会员了。
            $layer = isLeadOnlyCustomer($c) ? '留资' : '会员';
            $matches[] = ['kind' => $layer, 'name' => $c->name, 'venue' => $c->venue, 'detail' => trim((string) $c->main_card) !== '' && $c->main_card !== '—' ? $c->main_card : '尚未购卡'];
        }
        foreach (Lead::whereIn('phone', $forms)->orderByDesc('id')->get() as $l) {
            $matches[] = ['kind' => '已有留资', 'name' => $l->name, 'venue' => $l->venue, 'detail' => $l->status, 'id' => $l->id];
        }

        return ok(['exists' => count($matches) > 0, 'matches' => $matches]);
    }

    /** POST /leads */
    public function store(Request $r)
    {
        $d = $r->validate(array_merge([
            'name' => 'required|string', 'venue' => 'required|string',
            'leadDate' => 'nullable|date', 'dealAmount' => 'nullable|numeric|min:0|decimal:0,2', 'redeemAmount' => 'nullable|numeric|min:0|decimal:0,2',
            'dealAt' => 'nullable|date',
        ], $this->channelRules(required: true), $this->referrerRules()), $this->channelMessages());
        $values = array_intersect_key(camelToSnake($r->all()), array_flip($this->leadFields)) + ['created_by' => $r->user()->name];
        $values = $this->withStaffIds($values);
        $values = $this->withNormalizedPhone($values);
        // 清空的字段（null）按列定义落成该列能接受的形态：可空列写 null，非空文本列写 ''。
        // status 不可清空：写空会让这条留资在按状态筛选/统计里消失，留空一律按「新留资」入档。
        $values = normalizeEmptyValues('leads', $values, except: ['status']);
        $values['status'] = $values['status'] ?? '新留资';
        $values['lead_date'] = $values['lead_date'] ?? now()->toDateString();
        // 成交时间：用户填了就以用户值为准（补录历史成交日期），没填且状态为「已成交」才自动补 now()
        if ($this->shouldAutoFillDealAt($values, null)) {
            $values['deal_at'] = now();
        }
        if ((float) ($values['redeem_amount'] ?? 0) > 0) {
            $values['redeemed_at'] = now();
        }
        $lead = Lead::create($values);
        audit($r, '新增', '前端客资', $lead->id, "{$lead->name}（{$lead->source}）", $lead->venue, '录入客资');
        invalidateBusinessCaches('analytics');

        // 卡项限额软提示（S16）：**只附加，不阻断**。详见 leadDealCapWarnings()。
        // 金额取请求值而不是 $lead->deal_amount —— 后者是 float cast 后的结果，
        // 与本函数拿到的原始录入值可能有精度差；提示要对用户填的数字负责。
        $warnings = leadDealCapWarnings($values['deal_amount'] ?? null);
        if ($warnings !== []) {
            // 超限本身要留痕：只有被记录过的偏差才能被复盘（否则「提示过」这件事
            // 随响应消失，谁也不知道当时到底录了多少）。
            audit($r, '提示', '前端客资', $lead->id, "{$lead->name}（{$lead->source}）", $lead->venue,
                implode('；', array_column($warnings, 'message')));
        }

        return ok(['id' => $lead->id, 'warnings' => $warnings]);
    }

    /** PATCH /leads/{id} */
    public function update(Request $r, int $id)
    {
        $lead = Lead::findOrFail($id);
        $before = json_encode(camel($lead), JSON_UNESCAPED_UNICODE);
        // 渠道枚举：库里现值原样带回时放行（见 channelRules 的说明）。
        // 「显式传 null 想清空 source」走严格校验并按 422 处理：source 本就不可清空
        // （下方 normalizeEmptyValues 的 except 也含它），与既有语义一致，不需要为它
        // 开一条「能清成 null」的新路径。
        // 注：清空被拦是来自本数组的 'string' 规则（null 不是 string），**不是**因为
        // `$r->exists()` —— 实测它对 JSON null 返回 true（`has()` → `Arr::has()` →
        // `Arr::exists()`，无 null 特判），此处与 `array_key_exists` 行为等价。
        $enumRules = $this->channelRules(required: false);
        foreach (['source', 'orderPlatform'] as $field) {
            $column = strtolower(preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $field));
            if ($r->exists($field) && (string) $r->input($field) === (string) $lead->getAttribute($column)) {
                unset($enumRules[$field]);
            }
        }
        $r->validate(array_merge([
            'leadDate' => 'nullable|date', 'dealAmount' => 'nullable|numeric|min:0', 'redeemAmount' => 'nullable|numeric|min:0',
            'dealAt' => 'nullable|date',
        ], $enumRules, $this->referrerRules()), $this->channelMessages());
        $changes = array_intersect_key(camelToSnake($r->all()), array_flip($this->leadFields));
        $changes = $this->withStaffIds($changes);
        $changes = $this->withNormalizedPhone($changes);
        // 显式清空的字段落成该列能接受的形态；没传的字段不在 $changes 里，保持原值。
        // 注意别再写"$changes[$f] ?? '' 就置 null"那种兜底 —— 那会把用户没碰过的字段一起清掉
        // （老师只改备注也会把成交金额抹成 null）。lead_date 是非空日期列，由列定义兜住（清不掉）。
        $changes = normalizeEmptyValues('leads', $changes, except: ['venue', 'source', 'status', 'name']);
        // 成交时间：用户显式传了就以用户值为准（不得被 now() 覆盖）、显式清空就尊重清空，
        // 都没做且状态转「已成交」时才自动补 now()（既有行为）。
        if ($this->shouldAutoFillDealAt($changes, $lead->deal_at?->toDateTimeString())) {
            $changes['deal_at'] = now();
        }
        if ((float) ($changes['redeem_amount'] ?? 0) > 0 && ! $lead->redeemed_at) {
            $changes['redeemed_at'] = now();
        }
        $lead->update($changes);
        audit($r, '修改', '前端客资', $id, "{$lead->name}（{$lead->source}）", $lead->venue, '字段更新');
        invalidateBusinessCaches('analytics');

        // 卡项限额软提示（S16）：与 store 同口径、同样**不阻断**。
        // 只在本次确实改动了成交金额时才提示 —— 否则「改个备注也弹超限」，
        // 提示会被训练成噪音（与 rules() 里 bombExpiredDays 的处理同一个教训）。
        $warnings = array_key_exists('deal_amount', $changes)
            ? leadDealCapWarnings($changes['deal_amount'])
            : [];
        if ($warnings !== []) {
            audit($r, '提示', '前端客资', $id, "{$lead->name}（{$lead->source}）", $lead->venue,
                implode('；', array_column($warnings, 'message')));
        }

        return ok(['before' => json_decode($before), 'after' => camel($lead), 'warnings' => $warnings]);
    }

    /** DELETE /leads/{id}：删除留资，权限与「编辑」一致（店长本店 / 超管新媒体全部 / 老师本人或未分配），删除写留痕 */
    public function destroy(Request $r, int $id)
    {
        $u = $r->user();
        $lead = Lead::findOrFail($id);
        if (userHasRole($u, 'R_MANAGER') && $lead->venue !== $u->venue) {
            abort(403, '无权限：仅可删除本店留资');
        }
        if (userIsTeacherSide($u)) {
            if ($u->venue && $lead->venue !== $u->venue) {
                abort(403, '无权限：仅可删除本店留资');
            }
            if (userHasRole($u, 'R_SERVICE')) {
                if ($lead->service_teacher !== ''
                    && ! staffOwnsRow($u, $lead, 'service_teacher_user_id', 'service_teacher')
                    && ! staffOwnsRow($u, $lead, 'created_by_user_id', 'created_by')) {
                    abort(403, '无权限：仅可删除自己名下或未分配的留资');
                }
            } else {
                // 与范围过滤同口径：id 或姓名/别名任一命中都算本人的（改过名的历史留资要能删）
                $mine = staffOwnsRow($u, $lead, 'service_teacher_user_id', 'service_teacher')
                    || staffOwnsRow($u, $lead, 'trial_teacher_user_id', 'trial_teacher')
                    || staffOwnsRow($u, $lead, 'created_by_user_id', 'created_by');
                if (! $mine) {
                    abort(403, '无权限：授课老师仅可删除自己相关的留资');
                }
            }
        }
        if (! userHasAnyRole($u, ['R_SUPER', 'R_MANAGER', 'R_SERVICE', 'R_TEACHER', 'R_MEDIA'])) {
            abort(403, '无权限执行此操作');
        }
        audit($r, '删除', '前端客资', $id, "{$lead->name}（{$lead->source}）", $lead->venue, '删除留资记录');
        invalidateBusinessCaches('analytics');
        $lead->delete();

        return ok(['id' => $id]);
    }

    /** GET /leads/{id}/history */
    public function history(Request $r, int $id)
    {
        $rows = AuditLog::where('module', '前端客资')->where('target_id', (string) $id)->orderByDesc('id')->get()
            ->map(fn ($x) => camel($x));

        return ok($rows);
    }
}
