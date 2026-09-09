<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\RenewalEvaluation;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /** GET /customers/options：轻量选项接口，会籍顾问下拉（取代前端 size:5000 全量拉取后去重） */
    public function options(Request $r)
    {
        $u = $r->user();
        $q = scopeCustomersForUser(Customer::query(), $u);
        if ($u->role === 'R_MEDIA') {
            $q->where('layer', 'P5');
        }
        $q->where(fn ($w) => $w->where('layer', '!=', 'P5')->orWhere('external_id', 'like', 'ky:%'));
        $consultants = $q->whereNotNull('consultant')->where('consultant', '!=', '')
            ->distinct()->orderBy('consultant')->pluck('consultant');

        return ok(['consultants' => $consultants]);
    }

    /** GET /customers/list-counts：五清单徽标计数，一次清单扫描 + 角色范围求交集（取代前端全量拉取后逐行重算 5 遍） */
    public function listCounts(Request $r)
    {
        $u = $r->user();
        $lists = memberListIds();
        if ($u->role !== 'R_SUPER') {
            $scopedSet = array_flip(scopeCustomersForUser(Customer::query(), $u)->pluck('id')->all());
            foreach ($lists as $key => $ids) {
                $lists[$key] = count(array_filter($ids, fn ($id) => isset($scopedSet[$id])));
            }
        }

        return ok(['counts' => array_map(fn ($v) => is_array($v) ? count($v) : (int) $v, $lists)]);
    }

    /** GET /customers */
    public function index(Request $r)
    {
        $u = $r->user();
        $q = scopeCustomersForUser(Customer::query(), $u);
        if ($u->role === 'R_MEDIA') {
            $q->where('layer', 'P5');
        }
        if ($n = trim((string) $r->query('name', ''))) {
            $q->where('name', 'like', "%{$n}%");
        }
        if ($p = trim((string) $r->query('phone', ''))) {
            $q->where(function ($w) use ($p) {
                $w->where('phone', 'like', "%{$p}%")
                    ->orWhere('phone_tail', 'like', "%{$p}%");
            });
        }
        if ($v = $r->query('venue')) {
            $q->where('venue', $v);
        }
        if ($l = $r->query('list')) {
            $q->whereIn('id', filteredIds($l));
        }
        if ($ly = $r->query('layer')) {
            $q->where('layer', $ly);
        }
        if ($o = $r->query('owner')) {
            $q->where('owner', $o);
        }
        if ($s = $r->query('status')) {
            $q->where('status', $s);
        }
        if ($c = trim((string) $r->query('consultant', ''))) {
            if ($c === '待分配') {
                $q->where(fn ($w) => $w->whereNull('consultant')->orWhere('consultant', ''));
            } else {
                $q->where('consultant', $c);
            }
        }
        if ($src = $r->query('source')) {
            $q->where('source', 'like', "%{$src}%");
        }
        if ($r->query('type') === 'member') {
            $q->where(fn ($w) => $w->where('layer', '!=', 'P5')->orWhere('external_id', 'like', 'ky:%'));
        }
        if ($r->query('type') === 'lead') {
            $q->where('layer', 'P5')->where(fn ($w) => $w->whereNull('external_id')->orWhere('external_id', 'not like', 'ky:%'));
        }
        if ($r->query('haveCourse') === 'true') {
            $q->whereNotNull('main_card')->where('main_card', '!=', '—');
        }
        if ($r->query('haveCourse') === 'false') {
            $q->where(fn ($w) => $w->whereNull('main_card')->orWhere('main_card', '—'));
        }
        if (is_numeric($r->query('remainMax'))) {
            $q->where('remain_times', '<=', (int) $r->query('remainMax'));
        }
        if ($evaluation = $r->query('evaluationStatus')) {
            match ($evaluation) {
                '未评估' => $q->whereNull('eval_score'),
                '高机会' => $q->where('eval_level', 'high'),
                '重点培育' => $q->where('eval_level', 'medium'),
                '风险修复' => $q->where('eval_level', 'low'),
                '已过期' => $q->whereNotNull('eval_at')->where('eval_at', '<', now()->subDays(30)->toDateString()),
                default => null,
            };
        }
        $current = max(1, (int) $r->query('current', 1));
        $size = min(5000, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    }

    /** PATCH /customers/{id} */
    public function update(Request $r, int $id)
    {
        $c = Customer::findOrFail($id);
        abort_unless(canAccessCustomer($r->user(), $c), 403, '无权修改该会员');
        // 兼容字符串布尔（"false"/"true"/"0"/"1" 等），避免被 PHP (bool)"false"=true 污染
        foreach (['needsHelp', 'inRevive'] as $f) {
            $v = $r->input($f);
            if (is_string($v)) {
                $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b !== null) {
                    $r->merge([$f => $b]);
                }
            }
        }
        $r->validate([
            'lastTouch' => 'nullable|date',
            'birthday' => 'nullable|date_format:Y-m-d',
            'needsHelp' => 'nullable|boolean',
            'inRevive' => 'nullable|boolean',
        ]);
        $patch = collect(camelToSnake($r->all()))->only([
            'renewal_plan', 'decline', 'stop_reason', 'expected_return',
            'last_touch', 'needs_help', 'in_revive', 'birthday',
        ])->all();
        if (($patch['last_touch'] ?? '') === '') {
            $patch['last_touch'] = null;
        }
        if (($patch['birthday'] ?? '') === '') {
            $patch['birthday'] = null;
        }
        foreach (['needs_help', 'in_revive'] as $f) {
            if (array_key_exists($f, $patch) && ! is_bool($patch[$f])) {
                $patch[$f] = filter_var($patch[$f], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $c->update($patch);
        if (array_key_exists('in_revive', $patch)) {
            invalidateBusinessCaches('member_lists');
        }
        audit($r, $r->input('_action', '修改'), '会员管理', $id, "{$c->name}（{$c->venue}）", $c->venue, '工作流字段更新');

        return ok(camel($c));
    }

    /** GET /customers/{id}：客户360，主档 + 会员工作流留痕 + 按手机号关联的前端客资 */
    public function show(Request $r, int $id)
    {
        $c = Customer::findOrFail($id);
        abort_unless(canAccessCustomer($r->user(), $c), 403, '无权查看该会员');

        $logs = AuditLog::where('module', '会员管理')
            ->where('target_id', (string) $c->id)
            ->orderByDesc('id')->get()->map(fn ($x) => camel($x));

        $leads = $c->phone !== ''
            ? Lead::where('phone', $c->phone)->orderByDesc('id')->get()->map(fn ($x) => camel($x))
            : [];

        return ok([
            'customer' => camel($c),
            'logs' => $logs,
            'leads' => $leads,
        ]);
    }

    /** GET /customers/{id}/renewal-evaluation */
    public function renewalEvaluationShow(Request $r, int $id)
    {
        $c = Customer::findOrFail($id);
        abort_unless(canAccessCustomer($r->user(), $c), 403, '无权查看该会员评估');

        return ok(renewalEvaluationContext($c));
    }

    /** PUT /customers/{id}/renewal-evaluation */
    public function renewalEvaluationStore(Request $r, int $id)
    {
        $c = Customer::findOrFail($id);
        abort_unless(canAccessCustomer($r->user(), $c), 403, '无权评估该会员');
        $data = $r->validate([
            'answers' => 'required|array',
            'answers.goal' => 'required|string|in:written_plan,agreed_goal,visible_progress,none',
            'answers.feedback' => 'required|string|in:replied,no_reply,none',
            'answers.wechat' => 'required|string|in:proactive,two_way,shallow,no_response,refused',
            'answers.intent' => 'required|string|in:asked_plan,positive,uncertain,none',
            'answers.service' => 'required|string|in:resolved,handled,normal,unresolved',
            'answers.risks' => 'nullable|array|max:3',
            'answers.risks.*' => 'string|in:purchase_refused,long_no_response,complaint_unresolved',
            'remark' => 'nullable|string|max:500',
        ]);
        $result = DB::transaction(function () use ($r, $c, $data) {
            $context = renewalEvaluationContext($c);
            $answers = $data['answers'] + [
                'attendanceCount' => $context['attendanceCount'],
                'cardWindow' => $context['cardWindow'],
            ];
            $score = renewalEvaluationScore($answers);
            $level = renewalLevel($score);
            $evaluation = RenewalEvaluation::create([
                'customer_id' => $c->id,
                'answers' => $answers,
                'score' => $score,
                'level' => $level,
                'remark' => $data['remark'] ?? '',
                'evaluator_id' => $r->user()->id,
                'evaluator_name' => $r->user()->name,
                'evaluated_at' => now(),
            ]);
            $c->update([
                'eval_score' => $score,
                'eval_level' => $level,
                'eval_at' => now()->toDateString(),
                'eval_by' => $r->user()->name,
            ]);
            $taskSpec = renewalTaskSpec($c, $score);
            $deadline = now()->addDays($taskSpec['days'])->format('Y-m-d 18:00');
            $task = Task::query()
                ->where('customer_id', $c->id)
                ->where('source_type', 'renewal_evaluation')
                ->whereNotIn('status', ['已完成'])
                ->latest('id')->first();
            $taskValues = [
                'customer_id' => $c->id,
                'customer_name' => $c->name,
                'venue' => $c->venue,
                'title' => $taskSpec['title'],
                'owner' => $taskSpec['owner'],
                'priority' => $taskSpec['priority'],
                'deadline' => $deadline,
                'status' => '待接收',
                'standard' => $taskSpec['standard'],
                'source_type' => 'renewal_evaluation',
                'source_id' => $evaluation->id,
                'review_role' => $taskSpec['reviewRole'],
            ];
            if ($task) {
                $task->update($taskValues);
            } else {
                $task = Task::create($taskValues);
            }
            $c->update([
                'next_action' => $taskSpec['title'],
                'next_action_time' => $deadline,
            ]);
            audit($r, '评估', '会员管理', $c->id, "{$c->name}（{$c->venue}）", $c->venue, "续费经营评估 {$score} 分，联动任务 #{$task->id} [{$task->title}]");

            return ['evaluation' => camel($evaluation), 'task' => camel($task), 'context' => $context];
        });

        return ok($result);
    }

    /** GET /new-members/cultivation：新客培养（入会近 N 天会员的上课养成） */
    public function newMemberCultivation(Request $r)
    {
        $u = $r->user();
        $r->validate([
            'start' => 'nullable|date_format:Y-m-d',
            'end' => 'nullable|date_format:Y-m-d|after_or_equal:start',
            'venue' => 'nullable|string|in:绿地店,东部店',
            'name' => 'nullable|string|max:100',
            'cardType' => 'nullable|string|in:private,small,group',
        ]);

        $start = $r->query('start') ?: now()->subDays(89)->toDateString();
        $end = $r->query('end') ?: now()->toDateString();
        $venue = (string) $r->query('venue', '');

        // 养成目标节数（私教/小班/团课，超管可调）
        $rules = rules();
        $targets = [
            'private' => (int) ($rules['cultivationPrivate'] ?? 8),
            'small' => (int) ($rules['cultivationSmall'] ?? 12),
            'group' => (int) ($rules['cultivationGroup'] ?? 12),
        ];

        // 新入会会员：入会时间落在窗口内，按角色/门店隔离（复用 scopeCustomersForUser）
        $q = scopeCustomersForUser(Customer::query(), $u)
            ->whereNotNull('enrolled_at')
            ->whereBetween('enrolled_at', [$start, $end.' 23:59:59']);
        // 店长锁定本店、老师由 scope 限定本人；超管/新媒体可按需选门店
        if ($u->role !== 'R_MANAGER' && $u->role !== 'R_TEACHER' && $venue !== '') {
            $q->where('venue', $venue);
        }
        if ($n = trim((string) $r->query('name', ''))) {
            $q->where('name', 'like', "%{$n}%");
        }
        $newMembers = $q->orderByDesc('enrolled_at')->get();

        // 会员唯一键：从 external_id(ky:{venueId}:{memberId}) 解析 KeepYoga member_id
        $memberIdOf = fn (Customer $c): string => preg_match('/^ky:\d+:(.+)$/', (string) $c->external_id, $m) ? $m[1] : '';
        $memberIds = array_values(array_filter(array_map($memberIdOf, $newMembers->all())));
        $phones = array_values(array_filter($newMembers->pluck('phone')->all()));

        // 一次性预载这批会员的预约/上课事实，避免逐个会员查询
        $bookingMap = collect();
        if ($memberIds !== [] || $phones !== []) {
            $bookingMap = KyBooking::query()
                ->where(fn ($w) => $w->whereIn('member_id', $memberIds ?: ['-'])
                    ->orWhereIn('phone', $phones ?: ['-']))
                ->where('is_trial', false)
                ->where('start_at', '>=', $start.' 00:00:00')
                ->get()
                ->groupBy('member_id');
        }

        $kindOf = fn (KyBooking $b): string => match ((string) ($b->raw['course_type'] ?? '')) {
            '2' => 'private',
            '3' => 'small',
            default => 'group',
        };

        $emptyCat = fn (): array => ['signed' => 0, 'booked' => 0, 'no_show' => 0];
        $records = [];
        $summary = ['total' => 0, 'private' => 0, 'small' => 0, 'group' => 0, 'idle' => 0, 'cultivating' => 0, 'cultured' => 0];

        foreach ($newMembers as $c) {
            $mid = $memberIdOf($c);
            $enrolled = (string) ($c->enrolled_at?->toDateString() ?: $c->enrolled_at);
            $cat = ['private' => $emptyCat(), 'small' => $emptyCat(), 'group' => $emptyCat()];
            $themes = [];
            /** @var Collection $rows */
            $rows = $mid !== '' ? ($bookingMap->get($mid) ?? collect()) : collect();
            foreach ($rows as $b) {
                // 只算入会之后的课，衡量新客养成
                $bDate = (string) ($b->start_at?->toDateString() ?: '');
                if ($bDate !== '' && $bDate < $enrolled) {
                    continue;
                }
                $kind = $kindOf($b);
                if ($b->status === 'signed') {
                    $cat[$kind]['signed']++;
                    $label = trim((string) $b->course_name) ?: '课程';
                    $themes[$label] = ($themes[$label] ?? 0) + 1;
                } elseif ($b->status === 'booked') {
                    $cat[$kind]['booked']++;
                } elseif ($b->status === 'no_show') {
                    $cat[$kind]['no_show']++;
                }
            }

            $totalSigned = $cat['private']['signed'] + $cat['small']['signed'] + $cat['group']['signed'];
            // 健康度：0 上课=待激活；所有参与类别达目标=已养成；否则待养成
            $participated = array_filter(['private', 'small', 'group'], fn ($k) => $cat[$k]['signed'] > 0 || $cat[$k]['booked'] > 0);
            $cultured = $participated !== [] && array_reduce($participated, fn ($ok, $k) => $ok && $cat[$k]['signed'] >= $targets[$k], true);
            $health = $totalSigned === 0 ? 'idle' : ($cultured ? 'cultured' : 'cultivating');

            $topThemes = collect($themes)->sortDesc()->keys()->take(6)->all();
            $kinds = ['private', 'small', 'group'];
            usort($kinds, fn ($a, $b) => $cat[$b]['signed'] <=> $cat[$a]['signed']);
            $primaryKind = $kinds[0];
            $primaryCat = $cat[$primaryKind];

            $records[] = [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'phoneTail' => $c->phone_tail ?: substr((string) $c->phone, -4),
                'venue' => $c->venue,
                'enrolledAt' => $enrolled,
                'enrolledDays' => $enrolled !== '' ? (int) CarbonImmutable::parse($enrolled)->diffInDays(now(), false) : null,
                'consultant' => $c->consultant ?: ($c->owner ?: '未分配'),
                'mainCard' => $c->main_card,
                'categories' => [
                    'private' => $cat['private'] + ['target' => $targets['private']],
                    'small' => $cat['small'] + ['target' => $targets['small']],
                    'group' => $cat['group'] + ['target' => $targets['group']],
                ],
                'totalSigned' => $totalSigned,
                'primaryKind' => $primaryKind,
                'primaryProgress' => [
                    'signed' => $primaryCat['signed'],
                    'target' => $targets[$primaryKind],
                ],
                'themes' => $topThemes,
                'health' => $health,
            ];

            $summary['total']++;
            if ($cat['private']['signed'] > 0) {
                $summary['private']++;
            }
            if ($cat['small']['signed'] > 0) {
                $summary['small']++;
            }
            if ($cat['group']['signed'] > 0) {
                $summary['group']++;
            }
            $summary[$health === 'idle' ? 'idle' : ($health === 'cultured' ? 'cultured' : 'cultivating')]++;
        }

        if ($cardType = $r->query('cardType')) {
            $records = array_values(array_filter($records, fn ($x) => $x['categories'][$cardType]['signed'] > 0));
        }

        // 数据新鲜度：KeepYoga 增量同步非实时，提示「数据截至最近同步」
        $setting = AppSetting::oldest('id')->first();
        $snap = (array) ($setting?->snapshot ?? []);
        $fetchedAt = is_array($snap['fetchedAt'] ?? null) ? max(array_filter(array_values($snap['fetchedAt']))) : (string) ($snap['fetchedAt'] ?? '');

        return ok([
            'records' => $records,
            'summary' => $summary,
            'targets' => $targets,
            'range' => ['start' => $start, 'end' => $end],
            'syncTime' => $fetchedAt,
        ]);
    }

    /** GET /member-rules */
    public function memberRulesShow(Request $r)
    {
        return ok(rules());
    }

    /** PUT /member-rules */
    public function memberRulesUpdate(Request $r)
    {
        requireSuper($r);
        $data = $r->validate([
            'renewalThreshold' => 'required|integer|min:1|max:50',
            'renewalCountPercent' => 'nullable|integer|min:0|max:100',
            'renewalExpireDays' => 'nullable|integer|min:1|max:365',
            'renewalExpirePercent' => 'nullable|integer|min:0|max:100',
            'vipAmountThreshold' => 'required|integer|min:1000|max:1000000',
            'declineMode' => 'required|in:strict,recent',
            'predropMin' => 'required|integer|min:1|max:180',
            'predropMax' => 'required|integer|min:1|max:180|gte:predropMin',
            'reviveDays' => 'required|integer|min:7|max:365',
            'cultivationPrivate' => 'nullable|integer|min:1|max:100',
            'cultivationSmall' => 'nullable|integer|min:1|max:100',
            'cultivationGroup' => 'nullable|integer|min:1|max:100',
        ]);
        // 未传的新阈值回落到默认值，避免存一半缺键
        $data['renewalCountPercent'] = (int) ($data['renewalCountPercent'] ?? 20);
        $data['renewalExpireDays'] = (int) ($data['renewalExpireDays'] ?? 30);
        $data['renewalExpirePercent'] = (int) ($data['renewalExpirePercent'] ?? 0);
        setRules($data);
        audit($r, '修改', '会员管理', 0, '清单规则阈值', '双店', json_encode($data, JSON_UNESCAPED_UNICODE));

        return ok(rules());
    }
}
