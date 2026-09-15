<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\PostClassReview;
use App\Models\User;
use App\Services\PostClassPlanEngine;
use Illuminate\Http\Request;

/**
 * 体验课课后分析 ＋ 训练方向（P0）。
 *
 * 设计要点（相对于已有 training_plans 的三个坑）：
 * 1. 关联一律走 id：lead_id / customer_id / booking_id / teacher_user_id；
 * 2. 不用 created_by 姓名做隔离，权限统一按角色 + 门店 + 本人计算；
 * 3. 不用「整表删除重建」，逐条 upsert。
 *
 * 红线硬拦截：命中红线的记录不产出对客训练方案，只给「建议进一步专业评估」沟通卡，
 * 且不进入成交跟进池（handoff.blocked = true）。
 */
final class PostClassReviewController extends Controller
{
    /** 可写角色：授课老师本人、店长、超管（服务老师只读，用于顾问衔接） */
    private function assertCanWrite(User $u): void
    {
        abort_unless(in_array($u->role, ['R_SUPER', 'R_MANAGER', 'R_TEACHER'], true), 403, '仅授课老师、店长可填写课后分析');
    }

    private function isVisible(User $u, PostClassReview $row): bool
    {
        if ($u->role === 'R_SUPER') {
            return true;
        }
        if ($row->venue !== $u->venue) {
            return false;
        }
        if ($u->role === 'R_MANAGER') {
            return true;
        }
        if ($u->role === 'R_TEACHER') {
            return (int) $row->teacher_user_id === (int) $u->id
                || (string) $row->teacher_name === (string) $u->name;
        }
        if ($u->role === 'R_SERVICE') {
            return $this->serviceOwns($u, $row);
        }

        return false;
    }

    /** 服务老师是否对这条记录有可见关系（自己名下的会员或客资） */
    private function serviceOwns(User $u, PostClassReview $row): bool
    {
        if ($row->customer_id) {
            $c = Customer::find($row->customer_id);
            if ($c && in_array($u->name, [(string) $c->consultant, (string) $c->owner], true)) {
                return true;
            }
        }
        if ($row->lead_id) {
            $l = Lead::find($row->lead_id);
            if ($l && (string) $l->service_teacher === (string) $u->name) {
                return true;
            }
        }
        // 兜底：关联 id 缺失（手工新建等）时按手机号判断归属
        $phone = (string) $row->student_phone;
        if ($phone !== '') {
            $owned = Customer::where('phone', $phone)->where('venue', $row->venue)
                ->where(fn ($w) => $w->where('consultant', $u->name)->orWhere('owner', $u->name))
                ->exists();
            if ($owned) {
                return true;
            }

            return Lead::where('phone', $phone)->where('venue', $row->venue)
                ->where('service_teacher', $u->name)->exists();
        }

        return false;
    }

    /** GET /post-class-reviews/catalog：规则库（观察项/类型/红线/话术口径），前端不再抄一份文案 */
    public function catalog(Request $r)
    {
        return ok(PostClassPlanEngine::catalog());
    }

    /** POST /post-class-reviews/preview：只生成不落库，供向导即时预览 */
    public function preview(Request $r)
    {
        $this->assertCanWrite($r->user());

        return ok(PostClassPlanEngine::generate([
            'student_type' => $r->input('studentType'),
            'observations' => $r->input('observations', []),
            'red_flags' => $r->input('redFlags', []),
            'goal_text' => (string) $r->input('goalText', ''),
        ]));
    }

    /**
     * GET /post-class-reviews/candidates：可填写的课。
     *
     * 授课老师取本人最近的真实排课；未产生课后分析的排在最前。
     * 体验课与私教课都能填（私教课是训练档案的后续记录）。
     */
    public function candidates(Request $r)
    {
        $u = $r->user();
        $days = min(60, max(1, (int) $r->query('days', 7)));
        $venue = (string) $r->query('venue', '');

        $q = KyBooking::query()
            ->whereBetween('start_at', [now()->subDays($days)->startOfDay(), now()->endOfDay()])
            ->whereNotIn('status', ['cancelled', 'no_show']);

        if ($u->role === 'R_SUPER') {
            if ($venue !== '') {
                $q->where('venue', $venue);
            }
        } else {
            $q->where('venue', $u->venue);
        }
        if ($u->role === 'R_TEACHER') {
            $q->where('teacher_name', $u->name);
        }

        $rows = $q->orderByDesc('start_at')->limit(200)->get();
        $doneIds = PostClassReview::whereIn('booking_id', $rows->pluck('id')->all() ?: [-1])
            ->pluck('booking_id')->all();

        $phoneOf = fn ($b) => preg_replace('/\D+/', '', (string) $b->phone) ?? '';
        $phones = $rows->map($phoneOf)->filter()->unique()->values()->all();
        $customerByPhone = $phones === [] ? collect() : Customer::whereIn('phone', $phones)->get()->keyBy('phone');
        $leadByPhone = $phones === [] ? collect() : Lead::whereIn('phone', $phones)->orderByDesc('id')->get()->keyBy('phone');

        $records = $rows->map(function ($b) use ($doneIds, $customerByPhone, $leadByPhone, $phoneOf) {
            $phone = $phoneOf($b);
            $c = $customerByPhone->get($phone);
            $l = $leadByPhone->get($phone);
            $kind = $b->courseKind();

            return [
                'bookingId' => $b->id,
                'classAt' => $b->start_at?->format('Y-m-d H:i'),
                'date' => $b->start_at?->toDateString(),
                'time' => $b->start_at?->format('H:i'),
                'venue' => (string) $b->venue,
                'courseName' => (string) $b->course_name,
                'teacherName' => (string) $b->teacher_name,
                'studentName' => (string) $b->member_name,
                'phoneTail' => $phone !== '' ? substr($phone, -4) : '',
                'phone' => $phone,
                'kind' => KyBooking::KIND_LABELS[$kind] ?? '团课',
                'isTrial' => (bool) $b->is_trial,
                'scene' => $b->is_trial ? 'trial' : ($kind === 'private' ? 'private' : 'other'),
                'status' => (string) $b->status,
                'customerId' => $c?->id,
                'leadId' => $l?->id,
                'leadStatus' => (string) ($l->status ?? ''),
                'hasReview' => in_array($b->id, $doneIds, true),
            ];
        })->filter(fn ($x) => in_array($x['scene'], ['trial', 'private'], true))->values()->all();

        return ok([
            'records' => $records,
            'pendingCount' => collect($records)->where('hasReview', false)->count(),
        ]);
    }

    /** GET /post-class-reviews */
    public function index(Request $r)
    {
        $u = $r->user();
        $q = PostClassReview::query();

        if ($u->role === 'R_SUPER') {
            if ($v = trim((string) $r->query('venue', ''))) {
                $q->where('venue', $v);
            }
        } else {
            $q->where('venue', $u->venue);
        }
        if ($u->role === 'R_TEACHER') {
            $q->where(fn ($w) => $w->where('teacher_user_id', $u->id)->orWhere('teacher_name', $u->name));
        }
        if ($u->role === 'R_SERVICE') {
            // 服务老师：自己名下会员/客资的课后分析（顾问衔接用）
            $customerIds = Customer::query()->where('venue', $u->venue)
                ->where(fn ($w) => $w->where('consultant', $u->name)->orWhere('owner', $u->name))
                ->pluck('id')->all();
            $leadIds = Lead::query()->where('venue', $u->venue)->where('service_teacher', $u->name)
                ->pluck('id')->all();
            $q->where(function ($w) use ($customerIds, $leadIds) {
                $w->whereIn('customer_id', $customerIds ?: [-1])
                    ->orWhereIn('lead_id', $leadIds ?: [-1]);
            });
        }

        if ($s = trim((string) $r->query('status', ''))) {
            $q->where('status', $s);
        }
        if ($t = trim((string) $r->query('studentType', ''))) {
            $q->where('student_type', $t);
        }
        if ($r->query('redFlag') === '1') {
            $q->where('red_flag', true);
        }
        if ($df = $r->query('dateFrom')) {
            $q->where('class_at', '>=', $df.' 00:00:00');
        }
        if ($dt = $r->query('dateTo')) {
            $q->where('class_at', '<=', $dt.' 23:59:59');
        }

        $current = max(1, (int) $r->query('current', 1));
        $size = min(200, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('class_at')->orderByDesc('id')->forPage($current, $size)->get();

        // 成交归因：这批记录命中的客资是否已成交
        $leadIds = $rows->pluck('lead_id')->filter()->unique()->values()->all();
        $leads = $leadIds === [] ? collect() : Lead::whereIn('id', $leadIds)->get()->keyBy('id');

        $records = $rows->map(function ($x) use ($leads) {
            $lead = $x->lead_id ? $leads->get($x->lead_id) : null;

            return [
                'id' => $x->id,
                'venue' => $x->venue,
                'scene' => $x->scene,
                'sceneLabel' => PostClassPlanEngine::SCENES[$x->scene] ?? $x->scene,
                'studentName' => $x->student_name,
                'studentType' => $x->student_type,
                'teacherName' => $x->teacher_name,
                'classAt' => $x->class_at?->format('Y-m-d H:i'),
                'courseName' => $x->course_name,
                'redFlag' => (bool) $x->red_flag,
                'status' => $x->status,
                'share' => $x->share,
                'createdAt' => $x->created_at?->format('Y-m-d H:i'),
                'leadId' => $x->lead_id,
                'leadStatus' => (string) ($lead->status ?? ''),
                'dealAmount' => $lead ? (float) $lead->deal_amount : 0,
                'dealAt' => $lead?->deal_at?->format('Y-m-d'),
            ];
        })->all();

        return ok([
            'records' => $records,
            'total' => $total,
            'current' => $current,
            'size' => $size,
            'summary' => [
                'total' => $total,
                'confirmed' => (clone $q)->where('status', '已确认')->count(),
                'redFlag' => (clone $q)->where('red_flag', true)->count(),
            ],
        ]);
    }

    /** GET /post-class-reviews/{id} */
    public function show(Request $r, int $id)
    {
        $row = PostClassReview::findOrFail($id);
        abort_unless($this->isVisible($r->user(), $row), 403, '无权查看该课后分析');

        return ok(['id' => $row->id] + ($row->payload ?? []) + [
            'status' => $row->status,
            'share' => $row->share,
            'redFlag' => (bool) $row->red_flag,
            'studentType' => $row->student_type,
            'teacherName' => $row->teacher_name,
            'classAt' => $row->class_at?->format('Y-m-d H:i'),
            'createdAt' => $row->created_at?->format('Y-m-d H:i'),
            'confirmedAt' => $row->confirmed_at?->format('Y-m-d H:i'),
        ]);
    }

    /** POST /post-class-reviews */
    public function store(Request $r)
    {
        $u = $r->user();
        $this->assertCanWrite($u);
        $payload = $this->validatedPayload($r);

        $row = PostClassReview::create($this->attributesFrom($payload, $u, null));
        $this->auditCreate($r, $row);

        return ok(['id' => $row->id]);
    }

    /** PUT /post-class-reviews/{id} */
    public function update(Request $r, int $id)
    {
        $u = $r->user();
        $row = PostClassReview::findOrFail($id);
        abort_unless($this->isVisible($u, $row), 403, '无权修改该课后分析');
        $this->assertCanWrite($u);

        $payload = $this->validatedPayload($r);
        $row->update($this->attributesFrom($payload, $u, $row));

        return ok(['id' => $row->id]);
    }

    /** POST /post-class-reviews/{id}/confirm：老师确认并生成对客分享码 */
    public function confirm(Request $r, int $id)
    {
        $u = $r->user();
        $row = PostClassReview::findOrFail($id);
        abort_unless($this->isVisible($u, $row), 403, '无权确认该课后分析');
        $this->assertCanWrite($u);
        abort_if($row->status === '已确认', 422, '该课后分析已确认');

        $share = $row->share ?? [];
        if (empty($share['code'])) {
            $share['code'] = bin2hex(random_bytes(8));
        }
        $share['enabled'] = true;
        $share['views'] = (int) ($share['views'] ?? 0);

        $row->update([
            'status' => '已确认',
            'confirmed_at' => now(),
            'share' => $share,
        ]);
        audit($r, '确认', '课后分析', $row->id, $row->student_name, $row->venue,
            '课后分析已确认'.($row->red_flag ? '（命中红线，未产出训练方案）' : '，已生成对客训练方向'));
        invalidateBusinessCaches('analytics');

        return ok(['id' => $row->id, 'shareCode' => $share['code']]);
    }

    /** POST /post-class-reviews/{id}/share：开/停对客分享 */
    public function share(Request $r, int $id)
    {
        $u = $r->user();
        $row = PostClassReview::findOrFail($id);
        abort_unless($this->isVisible($u, $row), 403, '无权操作该课后分析');
        $this->assertCanWrite($u);
        abort_if($row->status !== '已确认', 422, '请先确认课后分析再分享');

        $share = $row->share ?? [];
        if (empty($share['code'])) {
            $share['code'] = bin2hex(random_bytes(8));
        }
        $share['enabled'] = (bool) $r->input('enabled', true);
        $row->update(['share' => $share]);

        return ok(['shareCode' => $share['code'], 'enabled' => $share['enabled']]);
    }

    /** DELETE /post-class-reviews/{id} */
    public function destroy(Request $r, int $id)
    {
        $u = $r->user();
        $row = PostClassReview::findOrFail($id);
        abort_unless($this->isVisible($u, $row), 403, '无权删除该课后分析');
        $this->assertCanWrite($u);

        audit($r, '删除', '课后分析', $row->id, $row->student_name, $row->venue, '删除课后分析记录');
        $row->delete();

        return ok(['id' => $id]);
    }

    /** GET /post-class-reviews/pending-count：工作台待办徽标 */
    public function pendingCount(Request $r)
    {
        $u = $r->user();
        if (! in_array($u->role, ['R_SUPER', 'R_MANAGER', 'R_TEACHER'], true)) {
            return ok(['count' => 0]);
        }
        $days = min(30, max(1, (int) $r->query('days', 3)));

        $q = KyBooking::query()
            ->whereBetween('start_at', [now()->subDays($days)->startOfDay(), now()->endOfDay()])
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where(function ($w) {
                $w->where('is_trial', true)->orWhere('course_kind', 'private');
            });
        if ($u->role === 'R_SUPER') {
            // 双店
        } elseif ($u->role === 'R_TEACHER') {
            $q->where('teacher_name', $u->name)->where('venue', $u->venue);
        } else {
            $q->where('venue', $u->venue);
        }

        $rows = $q->get(['id']);
        $done = PostClassReview::whereIn('booking_id', $rows->pluck('id')->all() ?: [-1])->count();

        return ok(['count' => max(0, $rows->count() - $done)]);
    }

    /** 校验并归一化提交的 payload */
    private function validatedPayload(Request $r): array
    {
        $r->validate([
            'scene' => 'nullable|string|in:trial,private_first,private',
            'studentType' => 'nullable|string|max:20',
            'studentName' => 'required|string|max:60',
            'studentPhone' => 'nullable|string|max:20',
            'classAt' => 'nullable|date',
            'courseName' => 'nullable|string|max:120',
            'venue' => 'nullable|string|in:绿地店,东部店',
            'goalText' => 'nullable|string|max:255',
            'observations' => 'nullable|array',
            'observations.*.key' => 'required|string|max:40',
            'observations.*.level' => 'nullable|string|in:轻,中,重',
            'redFlags' => 'nullable|array',
            'redFlags.*' => 'string|max:40',
            'leadId' => 'nullable|integer',
            'customerId' => 'nullable|integer',
            'bookingId' => 'nullable|integer',
        ]);

        $observations = [];
        foreach ((array) $r->input('observations', []) as $o) {
            if (! is_array($o) || empty($o['key'])) {
                continue;
            }
            $observations[] = ['key' => (string) $o['key'], 'level' => (string) ($o['level'] ?? '中')];
        }

        $redFlags = array_values(array_unique(array_filter(
            array_map('strval', (array) $r->input('redFlags', []))
        )));

        $generated = PostClassPlanEngine::generate([
            'student_type' => (string) $r->input('studentType', ''),
            'observations' => $observations,
            'red_flags' => $redFlags,
            'goal_text' => (string) $r->input('goalText', ''),
        ]);

        // 老师微调过的阶段文案要落库覆盖生成结果，否则当场改的字会被下次重新生成冲掉
        $planOverride = [];
        foreach ((array) $r->input('phases', []) as $ph) {
            if (is_array($ph) && isset($ph['key']) && isset($ph['goal'])) {
                $planOverride[(string) $ph['key']] = (string) $ph['goal'];
            }
        }
        if ($planOverride !== [] && is_array($generated['plan'] ?? null)) {
            foreach ($generated['plan']['phases'] as $i => $ph) {
                if (isset($planOverride[$ph['key']])) {
                    $generated['plan']['phases'][$i]['goal'] = $planOverride[$ph['key']];
                }
            }
            foreach ($generated['objective']['plan'] as $i => $ph) {
                $key = $generated['plan']['phases'][$i]['key'] ?? '';
                if (isset($planOverride[$key])) {
                    $generated['objective']['plan'][$i]['goal'] = $planOverride[$key];
                }
            }
        }

        return [
            'scene' => (string) $r->input('scene', 'trial') ?: 'trial',
            'studentType' => (string) ($r->input('studentType') ?: ($generated['student_type'] ?? '')),
            'studentName' => (string) $r->input('studentName'),
            'studentPhone' => preg_replace('/\D+/', '', (string) $r->input('studentPhone', '')) ?? '',
            'classAt' => (string) $r->input('classAt', ''),
            'courseName' => (string) $r->input('courseName', ''),
            'venue' => (string) $r->input('venue', ''),
            'goalText' => (string) $r->input('goalText', ''),
            'observations' => $observations,
            'redFlags' => $redFlags,
            'feedback' => [
                'bodyFeel' => (string) $r->input('feedback.bodyFeel', ''),
                'like' => (string) $r->input('feedback.like', ''),
                'concern' => (string) $r->input('feedback.concern', ''),
            ],
            'issues' => array_slice(array_values(array_filter(array_map(
                fn ($i) => is_array($i) ? ['text' => (string) ($i['text'] ?? ''), 'basis' => (string) ($i['basis'] ?? '')] : null,
                (array) $r->input('issues', [])
            ))), 0, 3),
            // 生成结果入库；老师可在 phases / script / handoff 上覆写
            'generated' => $generated,
            'plan' => $generated['plan'] ?? ($r->input('plan') ?: null),
            'objective' => $generated['objective'] ?? null,
            'script' => array_merge($generated['script'] ?? [], (array) $r->input('script', [])),
            'handoff' => array_merge($generated['handoff'] ?? [], (array) $r->input('handoff', [])),
            'leadId' => $r->input('leadId') ?: null,
            'customerId' => $r->input('customerId') ?: null,
            'bookingId' => $r->input('bookingId') ?: null,
        ];
    }

    /**
     * payload → 数据表列
     *
     * 关联 id 没拿到时按手机号回填：手工新建、或排课行没有匹配到会员/客资时，
     * 若不回填，顾问衔接（服务老师可见性）与成交归因都会断。
     */
    private function attributesFrom(array $p, User $u, ?PostClassReview $existing): array
    {
        $venue = $p['venue'];
        if ($venue === '') {
            $venue = (string) ($u->venue ?: ($existing->venue ?? ''));
        }

        $customerId = $p['customerId'] ?: ($existing->customer_id ?? null);
        $leadId = $p['leadId'] ?: ($existing->lead_id ?? null);
        $phone = (string) $p['studentPhone'];
        if ($phone !== '' && (! $customerId || ! $leadId)) {
            if (! $customerId) {
                $customerId = Customer::where('phone', $phone)
                    ->when($venue !== '', fn ($q) => $q->where('venue', $venue))
                    ->orderByDesc('id')->value('id');
            }
            if (! $leadId) {
                $leadId = Lead::where('phone', $phone)
                    ->when($venue !== '', fn ($q) => $q->where('venue', $venue))
                    ->orderByDesc('id')->value('id');
            }
        }

        // 授课老师始终以本人登记；店长/超管代填时保留原值或留空
        $teacherName = $existing?->teacher_name ?: '';
        $teacherUserId = $existing?->teacher_user_id;
        if ($u->role === 'R_TEACHER') {
            $teacherName = (string) $u->name;
            $teacherUserId = $u->id;
        } else {
            $teacherName = (string) ($teacherName ?: $u->name);
            $teacherUserId = $teacherUserId ?: $u->id;
        }

        return [
            'venue' => $venue,
            'scene' => $p['scene'],
            'lead_id' => $leadId,
            'customer_id' => $customerId,
            'booking_id' => $p['bookingId'] ?: ($existing->booking_id ?? null),
            'teacher_user_id' => $teacherUserId,
            'teacher_name' => $teacherName,
            'student_name' => $p['studentName'],
            'student_phone' => $phone,
            'class_at' => $p['classAt'] !== '' ? $p['classAt'] : null,
            'course_name' => $p['courseName'],
            'student_type' => $p['studentType'],
            'red_flag' => (bool) ($p['generated']['red_flag'] ?? false),
            'source' => 'rules',
            'payload' => $p,
        ];
    }

    private function auditCreate(Request $r, PostClassReview $row): void
    {
        audit($r, '新增', '课后分析', $row->id, $row->student_name, $row->venue,
            $row->red_flag
                ? '填写课后分析：命中红线，未产出训练方案'
                : '填写课后分析：'.$row->student_type.'方向已生成');
    }
}
