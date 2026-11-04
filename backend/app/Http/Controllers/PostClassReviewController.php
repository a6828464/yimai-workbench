<?php

namespace App\Http\Controllers;

use App\Models\BodyTestReport;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\PostClassReview;
use App\Models\TrainingPlan;
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
    /**
     * 「上过课」的签到口径（candidates 唯一来源判据）。
     *
     * - `signed`  同步把上游「已签到 / 已完成」映射成的权威值。经营看板、课时费、
     *             出勤窗口都按它算，课后分析的候选人必须与它们同口径。
     * - `unknown` / 空  同步拿不到可识别的状态文本（上游写「已到店」之类）的历史行。
     *             它们确实发生在过去，排除掉会让老数据整批从列表消失 —— 那正是
     *             「老师看不到自己上过课的会员」的成因之一，故按已发生处理。
     * - 排除 `cancelled`（取消/作废）、`no_show`（爽约/未到）、`booked`（预约·待上课）：
     *             这三种都不是「上过课」。
     */
    private const ATTENDED_STATUSES = ['signed', 'unknown', ''];

    /** 课类分组标签（两组互斥：私教 / 小班+团课） */
    private const GROUP_LABELS = ['private' => '私教', 'group' => '小班+团课'];

    /** 可写角色：授课老师本人、店长、超管（服务老师只读，用于顾问衔接） */
    private function assertCanWrite(User $u): void
    {
        abort_unless(userHasAnyRole($u, ['R_SUPER', 'R_MANAGER', 'R_TEACHER']), 403, '仅授课老师、店长可填写课后分析');
    }

    private function isVisible(User $u, PostClassReview $row): bool
    {
        if (userHasRole($u, 'R_SUPER')) {
            return true;
        }
        if ($row->venue !== $u->venue) {
            return false;
        }
        if (userHasRole($u, 'R_MANAGER')) {
            return true;
        }
        if (userHasRole($u, 'R_TEACHER')) {
            return (int) $row->teacher_user_id === (int) $u->id
                || staffOwnsRow($u, $row, 'teacher_user_id', 'teacher_name');
        }
        if (userHasRole($u, 'R_SERVICE')) {
            return $this->serviceOwns($u, $row);
        }

        return false;
    }

    /** 服务老师是否对这条记录有可见关系（自己名下的会员或客资） */
    private function serviceOwns(User $u, PostClassReview $row): bool
    {
        if ($row->customer_id) {
            $c = Customer::find($row->customer_id);
            // 归属判断走 staffOwnsRow（id 或姓名/别名并集），与列表范围同口径
            if ($c && (staffOwnsRow($u, $c, 'consultant_user_id', 'consultant')
                || staffOwnsRow($u, $c, 'owner_user_id', 'owner'))) {
                return true;
            }
        }
        if ($row->lead_id) {
            $l = Lead::find($row->lead_id);
            if ($l !== null && staffOwnsRow($u, $l, 'service_teacher_user_id', 'service_teacher')) {
                return true;
            }
        }
        // 兜底：关联 id 缺失（手工新建等）时按手机号判断归属
        $phone = (string) $row->student_phone;
        if ($phone !== '') {
            $owned = Customer::where('phone', $phone)->where('venue', $row->venue)
                ->where(fn ($w) => $w->where(staffOwnerFilter($u, 'consultant_user_id', 'consultant'))->orWhere(staffOwnerFilter($u, 'owner_user_id', 'owner')))
                ->exists();
            if ($owned) {
                return true;
            }

            return Lead::where('phone', $phone)->where('venue', $row->venue)
                ->where(staffOwnerFilter($u, 'service_teacher_user_id', 'service_teacher'))->exists();
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
     * GET /post-class-reviews/candidates：本页可见的人群。
     *
     * 三类来源合并展示（用户口径）：
     *  1) class  —— 上过课的：排课表里近 N 天**实际签到**的课，按「会员 × 课类分组」聚合成一行
     *  2) lead   —— 留资管理里分配给他的：service_teacher 或 trial_teacher 是本人
     *  3) member —— 约课系统里会籍顾问归属他的：customers.consultant 是本人
     *
     * 「上过课」按课类分组（2026-11 馆主口径）：
     *  - `private`（私教）单独一类；
     *  - `group`（小班 small ＋ 团课 group 合并）一类 —— 馆里这两类都是一对多集体课，
     *    课后分析填写口径相同，分开放只会让人在两个 tab 之间来回找。
     * 两组互斥：一节课只落进其中一组；同一会员两类课都上过时会在两组各出现一行，
     * 那是他真实上过的两类课，不是重复数据。
     *
     * 参数口径：
     *  - `days`    窗口天数，默认 7，夹在 1..60；
     *  - `group`   private|group|all（默认 all）—— 只裁剪 records；
     *              `groups` 计数恒为全量，否则另一组的角标永远是 0；
     *  - 角色兜底：识别不出角色的账号返回全空（失败关闭）；非店长/超管且不带 `teacher`
     *    的非老师侧账号（如新媒体）`records` 置空 —— 门店全量候选只对店长/超管开放；
     *  - `teacher` 指定老师。老师（授课/服务）**只能用本人**，传别人一律 403；
     *              店长 / 超管 / 新媒体可代看他人（staffUserId 解析 id / 姓名 / 别名 / 昵称）。
     *
     * 会员/客资的关联同时按手机号与「姓名+门店」匹配：随心瑜预约行不保证带手机号，
     * 只按手机号匹配会让「客资状态」整列都是空的。
     */
    public function candidates(Request $r)
    {
        $u = $r->user();
        $days = min(60, max(1, (int) $r->query('days', 7)));
        $venue = (string) $r->query('venue', '');
        $group = (string) $r->query('group', 'all');
        if (! in_array($group, ['private', 'group', 'all'], true)) {
            $group = 'all';
        }
        // 角色识别不出来（`roles` 漏写、或 role 是已下架的旧角色码）：**失败关闭**。
        // 这条链原先没有任何「都不是」分支 —— 这类账号既不进 R_TEACHER 也不进 R_SERVICE 条件，
        // 于是查询上只剩门店维度，等于把「本店全部预约行的学员」当成候选人发出去。
        // 与 scopeLeadsForUser / scopeCustomersForUser 的兜底同口径：空集，不是全量。
        if (! isKnownRoleUser($u)) {
            return ok([
                'records' => [],
                'leads' => [],
                'members' => [],
                'groups' => ['private' => 0, 'group' => 0],
                'groupsPending' => ['private' => 0, 'group' => 0],
                'pendingCount' => 0,
                'days' => $days,
                'group' => $group,
                'teacher' => null,
            ]);
        }

        $isService = userHasRole($u, 'R_SERVICE');
        $proxy = $this->resolveCandidateTeacher($r, $u);

        $venueFilter = function ($q) use ($u, $venue) {
            if (userHasRole($u, 'R_SUPER')) {
                if ($venue !== '') {
                    $q->where('venue', $venue);
                }
            } else {
                $q->where('venue', $u->venue);
            }

            return $q;
        };
        // 双店账号（新媒体，venue 为空）代看某位老师时，门店锁会把范围压成空集：
        // 这时可见范围完全由「该老师本人」这条条件承担，不再追加门店条件。
        $skipVenueLock = $proxy !== null && (string) $u->venue === '' && ! userHasRole($u, 'R_SUPER');

        // ---------- 1) 上过课的 ----------
        $bookingQ = KyBooking::query()
            ->whereBetween('start_at', [now()->subDays($days)->startOfDay(), now()->endOfDay()])
            ->whereIn('status', self::ATTENDED_STATUSES);
        if (! $skipVenueLock) {
            $venueFilter($bookingQ);
        }

        if ($proxy !== null) {
            // 本人 / 代看：只看目标老师名下的课（id 与姓名/别名并集，与归属判定同一口径）
            $bookingQ->where(staffOwnerFilter($proxy, 'teacher_user_id', 'teacher_name'));
        } elseif (userHasRole($u, 'R_TEACHER')) {
            $bookingQ->where(staffOwnerFilter($u, 'teacher_user_id', 'teacher_name'));
        } elseif ($isService) {
            // 服务老师不授课：看自己名下会员上过的课
            $myPhones = Customer::query()->where('venue', $u->venue)
                ->where(fn ($w) => $w->where(staffOwnerFilter($u, 'consultant_user_id', 'consultant'))->orWhere(staffOwnerFilter($u, 'owner_user_id', 'owner')))
                ->where('phone', '!=', '')->pluck('phone')->all();
            $bookingQ->whereIn('phone', $myPhones !== [] ? $myPhones : ['__none__']);
        }
        // 上限从 200 提到 2000：小班/团课一次课就是一二十个学员，7 个自然日很容易超过 200 行，
        // 截断的表现正是「老师看不到自己上过课的全部会员」。
        $rows = $bookingQ->orderByDesc('start_at')->limit(2000)->get();
        $doneIds = PostClassReview::whereIn('booking_id', $rows->pluck('id')->all() ?: [-1])
            ->pluck('booking_id')->all();

        // 手机号 / 姓名+门店 双索引匹配会员与客资
        $normalize = fn ($v) => preg_replace('/\D+/', '', (string) $v) ?? '';
        $phones = $rows->map(fn ($b) => $normalize($b->phone))->filter()->unique()->values()->all();
        $names = $rows->map(fn ($b) => (string) $b->member_name)->filter()->unique()->values()->all();

        $customerIdx = [];
        $leadIdx = [];
        // 没有可用匹配键时不要去拉全表：`where(function () {})` 是空约束，会把整库会员读进内存
        if ($phones !== [] || $names !== []) {
            $customerQ = Customer::query()->where(function ($w) use ($phones, $names) {
                if ($phones !== []) {
                    $w->whereIn('phone', $phones);
                }
                if ($names !== []) {
                    $w->orWhereIn('name', $names);
                }
            });
            foreach ($customerQ->get(['id', 'name', 'phone', 'venue', 'external_id', 'layer', 'main_card', 'remain_times']) as $c) {
                if ($normalize($c->phone) !== '') {
                    $customerIdx['p:'.$normalize($c->phone)] = $c;
                }
                $customerIdx['n:'.$c->name.'|'.$c->venue] = $c;
            }

            $leadQ = Lead::query()->where(function ($w) use ($phones, $names) {
                if ($phones !== []) {
                    $w->whereIn('phone', $phones);
                }
                if ($names !== []) {
                    $w->orWhereIn('name', $names);
                }
            })->orderBy('id');
            foreach ($leadQ->get(['id', 'name', 'phone', 'venue', 'status']) as $l) {
                $p = $normalize($l->phone);
                if ($p !== '') {
                    $leadIdx['p:'.$p] = $l;   // 取最新一条（orderBy id 升序，后写覆盖）
                }
                $leadIdx['n:'.$l->name.'|'.$l->venue] = $l;
            }
        }

        $lookup = function (string $phone, string $name, string $venue) use ($customerIdx, $leadIdx): array {
            $keys = [];
            if ($phone !== '') {
                $keys[] = 'p:'.$phone;
            }
            if ($name !== '') {
                $keys[] = 'n:'.$name.'|'.$venue;
            }
            $c = null;
            $l = null;
            foreach ($keys as $k) {
                $c ??= $customerIdx[$k] ?? null;
                $l ??= $leadIdx[$k] ?? null;
            }

            return [$c, $l];
        };

        // 同一会员在同一课类分组里只出一行：取最近的一节课，并累计同一窗口内的节数。
        // 没有会员档案的（体验客/裸预约行）按手机号，再退回「姓名 + 门店」，不合并同名不同人。
        $records = [];
        $seat = [];
        foreach ($rows as $b) {
            $name = (string) $b->member_name;
            $kind = (string) $b->courseKind();
            $bucket = $kind === 'private' ? 'private' : 'group';
            [$c, $l] = $lookup($normalize($b->phone), $name, (string) $b->venue);
            // 随心瑜预约行不保证带手机号：回填匹配到的会员/客资手机号，
            // 否则列表里既联系不上人，也没法用来建课后分析
            $phone = $normalize($b->phone);
            if ($phone === '') {
                $phone = $normalize($c->phone ?? '') ?: $normalize($l->phone ?? '');
            }

            // 来源渠道：老会员（会员系统已建档）／新建客资（只有留资）／未建档。
            // 老会员本来就没有留资记录，不能因为查不到留资就显示成「未建客资」——
            // 那是两个渠道，不是数据缺失。
            $isMember = $c && ((string) $c->external_id !== '' || (string) $c->layer !== 'P5');

            $identity = $c?->id
                ? 'c:'.$c->id
                : ($l?->id
                    ? 'l:'.$l->id
                    : ($phone !== '' ? 'p:'.$phone : 'n:'.$name.'|'.(string) $b->venue));
            $key = $bucket.'|'.$identity;
            if (isset($seat[$key])) {
                // 已有更近的一行：只累计节数，展示字段保持「最近一节课」的值
                $records[$seat[$key]]['classCount']++;

                continue;
            }
            $seat[$key] = count($records);

            $records[] = [
                'source' => 'class',
                'personType' => $isMember ? 'member' : ($l ? 'lead' : 'none'),
                'memberCard' => $isMember ? (string) ($c->main_card ?? '') : '',
                'memberRemain' => $isMember ? $c->remain_times : null,
                'bookingId' => $b->id,
                'classAt' => $b->start_at?->format('Y-m-d H:i'),
                'date' => $b->start_at?->toDateString(),
                'time' => $b->start_at?->format('H:i'),
                'venue' => (string) $b->venue,
                'courseName' => (string) $b->course_name,
                'teacherName' => (string) $b->teacher_name,
                'studentName' => $name,
                'phoneTail' => $phone !== '' ? substr($phone, -4) : '',
                'phone' => $phone,
                'kind' => KyBooking::KIND_LABELS[$kind] ?? '团课',
                'isTrial' => (bool) $b->is_trial,
                'scene' => $b->is_trial ? 'trial' : 'private',
                'status' => (string) $b->status,
                'customerId' => $c?->id,
                'leadId' => $l?->id,
                'leadStatus' => (string) ($l->status ?? ''),
                'hasReview' => in_array($b->id, $doneIds, true),
                // ── v3.11：课类分组 + 最近一节课（列表已按「会员」聚合，不再一行一节课）──
                'courseKind' => $kind,
                'courseKindLabel' => KyBooking::KIND_LABELS[$kind] ?? '团课',
                'group' => $bucket,
                'groupLabel' => self::GROUP_LABELS[$bucket],
                'lastClassAt' => $b->start_at?->format('Y-m-d H:i'),
                'lastClassTitle' => (string) $b->course_name,
                'classCount' => 1,
            ];
        }

        // 分组计数恒为全量：group 参数只裁剪 records，否则另一组的角标永远是 0
        $groups = ['private' => 0, 'group' => 0];
        $groupsPending = ['private' => 0, 'group' => 0];
        foreach ($records as $x) {
            $groups[$x['group']]++;
            if (! $x['hasReview']) {
                $groupsPending[$x['group']]++;
            }
        }
        $scopedRecords = $group === 'all'
            ? $records
            : array_values(array_filter($records, fn ($x) => $x['group'] === $group));

        // 「门店全量候选」只对店长 / 超管开放。
        //
        // 为什么要有这条：上面的分支链只覆盖「代看 / 授课老师 / 服务老师」三种按人收窄的情形，
        // 其它账号（新媒体，以及任何非老师侧角色）走到这里时查询上**只剩门店维度** ——
        // 于是本店 7 天内所有预约行的学员都被当成候选人发出去。新媒体自己的可见范围是
        // 「本人/部门录入的客资」，不是门店花名册，不能靠不传 teacher 参数就拿到。
        //
        // 老师侧（R_SERVICE / R_TEACHER）不置空：它们的 records 本来就是**本人学员**
        // （授课老师按 teacher 收窄、服务老师按名下会员手机号收窄），不是门店全量。
        // 这条与验收里的用例矩阵一致：media 不传 teacher → 空；manager/super 不传 → 本店/双店全量；
        // teacher 不传 → 只见本人；代看（带 teacher 参数）路径完全不变。
        if ($proxy === null
            && ! userHasAnyRole($u, ['R_SUPER', 'R_MANAGER'])
            && ! userIsTeacherSide($u)) {
            $scopedRecords = [];
            $groups = ['private' => 0, 'group' => 0];
            $groupsPending = ['private' => 0, 'group' => 0];
        }

        // ---------- 2) 留资分配给他的（服务老师与授课老师都看自己的；代看时看目标老师的） ----------
        $owner = $proxy ?? $u;
        $leads = [];
        $myLeadQ = Lead::query()->where(function ($w) use ($owner) {
            $w->where(staffOwnerFilter($owner, 'service_teacher_user_id', 'service_teacher'))->orWhere(staffOwnerFilter($owner, 'trial_teacher_user_id', 'trial_teacher'));
        });
        if (! $skipVenueLock) {
            $venueFilter($myLeadQ);
        }
        foreach ($myLeadQ->orderByDesc('id')->limit(200)->get() as $l) {
            $p = $normalize($l->phone);
            $leads[] = [
                'source' => 'lead',
                'personType' => 'lead',
                'leadId' => $l->id,
                'customerId' => null,
                'studentName' => (string) $l->name,
                'phone' => $p,
                'phoneTail' => $p !== '' ? substr($p, -4) : '',
                'venue' => (string) $l->venue,
                'leadStatus' => (string) $l->status,
                'demand' => (string) $l->demand,
                'leadSource' => (string) $l->source,
                'leadDate' => (string) $l->lead_date,
                'hasReview' => PostClassReview::where('lead_id', $l->id)->exists(),
            ];
        }

        // ---------- 3) 会籍顾问归属他的会员 ----------
        $members = [];
        $myCustomerQ = Customer::query()
            ->where(fn ($w) => $w->where(staffOwnerFilter($owner, 'consultant_user_id', 'consultant'))->orWhere(staffOwnerFilter($owner, 'owner_user_id', 'owner')));
        if (! $skipVenueLock) {
            $venueFilter($myCustomerQ);
        }
        foreach ($myCustomerQ->orderByDesc('id')->limit(200)->get() as $c) {
            $members[] = [
                'source' => 'member',
                'personType' => 'member',
                'memberCard' => (string) $c->main_card,
                'memberRemain' => $c->remain_times,
                'customerId' => $c->id,
                'leadId' => null,
                'studentName' => (string) $c->name,
                'phone' => $normalize($c->phone),
                'phoneTail' => $normalize($c->phone) !== '' ? substr($normalize($c->phone), -4) : '',
                'venue' => (string) $c->venue,
                'mainCard' => (string) $c->main_card,
                'remainTimes' => $c->remain_times,
                'hasReview' => PostClassReview::where('customer_id', $c->id)->exists(),
            ];
        }

        return ok([
            'records' => $scopedRecords,
            'leads' => $leads,
            'members' => $members,
            'groups' => $groups,
            'groupsPending' => $groupsPending,
            'pendingCount' => count(array_filter($scopedRecords, fn ($x) => $x['hasReview'] === false)),
            'days' => $days,
            'group' => $group,
            'teacher' => $proxy === null ? null : ['id' => (int) $proxy->id, 'name' => (string) $proxy->name],
        ]);
    }

    /**
     * 解析 candidates 的 teacher 参数。
     *
     * 老师（授课 / 服务）**只能用本人**：传自己可以（id / 姓名 / 别名 / 昵称都认），
     * 传别人一律 403 —— 「老师能看到谁的学员」是权限边界，不能靠前端不下发参数来保证。
     * 店长 / 超管 / 新媒体可以指定他人代看（馆里真实用法：店长替休假老师补填课后分析）。
     * 解析口径与归属判定一致：纯数字按账号 id，其余走 staffUserId（本名 → 别名 → 安全昵称）。
     */
    private function resolveCandidateTeacher(Request $r, User $u): ?User
    {
        $raw = trim((string) $r->query('teacher', ''));
        if ($raw === '') {
            return null;
        }
        $id = ctype_digit($raw) ? (int) $raw : staffUserId($raw);

        if (! userHasAnyRole($u, ['R_SUPER', 'R_MANAGER', 'R_MEDIA'])) {
            abort_unless($id !== null && (int) $id === (int) $u->id, 403, '老师只能查看本人的学员');

            return $u;
        }

        $target = $id === null ? null : User::find($id);
        // 不回显原始输入：那是用户可控字节，直接拼进 JSON 会让畸形编码变成 500
        abort_if($target === null, 422, '指定的老师不存在');

        return $target;
    }

    /** GET /post-class-reviews */
    public function index(Request $r)
    {
        $u = $r->user();
        $q = PostClassReview::query();

        if (userHasRole($u, 'R_SUPER')) {
            if ($v = trim((string) $r->query('venue', ''))) {
                $q->where('venue', $v);
            }
        } else {
            $q->where('venue', $u->venue);
        }
        if (userHasRole($u, 'R_TEACHER')) {
            $q->where(fn ($w) => $w->where('teacher_user_id', $u->id)->orWhere(staffOwnerFilter($u, 'teacher_user_id', 'teacher_name')));
        }
        if (userHasRole($u, 'R_SERVICE')) {
            // 服务老师：自己名下会员/客资的课后分析（顾问衔接用）
            $customerIds = Customer::query()->where('venue', $u->venue)
                ->where(fn ($w) => $w->where(staffOwnerFilter($u, 'consultant_user_id', 'consultant'))->orWhere(staffOwnerFilter($u, 'owner_user_id', 'owner')))
                ->pluck('id')->all();
            $leadIds = Lead::query()->where('venue', $u->venue)->where(staffOwnerFilter($u, 'service_teacher_user_id', 'service_teacher'))
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
        $this->linkBodyTest($payload, $row);
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
        $this->linkBodyTest($payload, $row);
        // 对客方案文案可被改写，必须留痕（与 store/confirm/destroy 保持一致）
        audit($r, '修改', '课后分析', $row->id, (string) $row->student_name, (string) $row->venue, '更新课后分析');

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

    /**
     * POST /post-class-reviews/{id}/to-plan：把课后分析流转成一份训练计划草稿。
     *
     * 门店现状是「老师当场给方向 → 会员办卡后进入长期训练」，
     * 这一步把已经产出并确认的三阶段方向直接带进训练计划，
     * 老师不用在训练计划里再录一遍会员情况和方向。
     *
     * 计划以「待老师确认」状态落库，老师到训练计划页继续排课次。
     */
    public function toPlan(Request $r, int $id)
    {
        $u = $r->user();
        $row = PostClassReview::findOrFail($id);
        abort_unless($this->isVisible($u, $row), 403, '无权操作该课后分析');
        $this->assertCanWrite($u);
        abort_if($row->red_flag, 422, '命中红线的记录不生成训练计划，请先完成专业评估');

        $payload = $row->payload ?? [];
        $plan = $payload['plan'] ?? null;
        abort_if(! is_array($plan), 422, '该课后分析还没有生成训练方向');

        $bodyTest = $payload['bodyTest'] ?? null;
        $profile = is_array($bodyTest) ? ($bodyTest['profile'] ?? []) : [];

        // 关注要点：优先用体测发现的偏离项，其次用本次观察项
        $focus = [];
        foreach ((array) ($bodyTest['abnormal'] ?? []) as $it) {
            $focus[] = ($it['name'] ?? '').' '.($it['bandLabel'] ?? '');
        }
        if ($focus === []) {
            foreach ((array) ($plan['basis'] ?? []) as $b) {
                $focus[] = (string) ($b['label'] ?? '');
            }
        }

        $phases = [];
        foreach ((array) ($plan['phases'] ?? []) as $ph) {
            $items = (array) ($ph['focus'] ?? []);
            if ($items === [] && ! empty($ph['goal'])) {
                $items = [(string) $ph['goal']];
            }
            $phases[] = [
                'name' => (string) ($ph['name'] ?? ''),
                'duration' => trim((string) ($ph['duration'] ?? '').'（'.(string) ($ph['durationTimes'] ?? '').'）', '（）'),
                'items' => $items,
            ];
        }

        $cautions = array_values(array_filter(array_merge(
            (array) ($plan['cautions'] ?? []),
            (array) ($plan['homeWork'] ?? []) ? ['回家作业：'.implode('；', (array) $plan['homeWork'])] : []
        )));

        $perWeek = (int) ($plan['frequency']['max'] ?? 2) ?: 2;
        // 注意：payload 不带 id —— TrainingPlanController::index 用
        // array_merge(['id' => $p->id], payload)，payload 里的 id 会覆盖真实主键
        $planPayload = [
            'memberName' => (string) $row->student_name,
            'age' => isset($profile['age']) ? (string) $profile['age'] : '',
            'gender' => ($profile['sex'] ?? '') === '男' ? '男' : '女',
            'height' => isset($profile['height']) ? (string) $profile['height'] : '',
            'weight' => isset($profile['weight']) ? (string) $profile['weight'] : '',
            'bodyFat' => isset($profile['bodyFatRate']) ? (string) $profile['bodyFatRate'] : '',
            'focus' => implode('、', array_slice(array_unique($focus), 0, 5)),
            'coreGoal' => (string) $row->student_type.'：'.(string) ($phases[0]['items'][0] ?? ''),
            'freq' => (string) ($plan['frequency']['text'] ?? ''),
            'stageWeeks' => (string) ($perWeek * 8),
            'stageGoal' => (string) ($phases[0]['items'][0] ?? ''),
            'risks' => $row->red_flag ? '命中红线，须先完成专业评估' : '',
            'status' => '待老师确认',
            'content' => [
                'summary' => (string) $row->student_type.'方向：'.(string) ($plan['frequency']['text'] ?? ''),
                'phases' => $phases,
                'cautions' => $cautions !== [] ? $cautions : ['讲观察不讲诊断，讲方向不承诺疗效，不承诺围度或体重数字。'],
            ],
            'source' => 'fallback',
            'createdBy' => (string) $row->teacher_name,
            'createdAt' => now()->format('Y-m-d H:i'),
            'confirmedAt' => '',
            'images' => [],
            'share' => ['enabled' => false, 'code' => '', 'views' => 0],
        ];

        $created = TrainingPlan::create([
            'member_name' => (string) $row->student_name,
            'payload' => $planPayload,
            'status' => '待老师确认',
            'source' => 'fallback',
            'created_by' => (string) ($row->teacher_name ?: $u->name),
            'created_by_user_id' => staffUserId((string) ($row->teacher_name ?: $u->name)),
            'source_review_id' => $row->id,
            'source_body_test_id' => $payload['bodyTest']['id'] ?? null,
        ]);
        audit($r, '新增', '训练计划', $created->id, $row->student_name, $row->venue,
            '由课后分析（#'.$row->id.'）流转生成训练计划');

        return ok(['planId' => $created->id]);
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
        if (! userHasAnyRole($u, ['R_SUPER', 'R_MANAGER', 'R_TEACHER'])) {
            return ok(['count' => 0]);
        }
        $days = min(30, max(1, (int) $r->query('days', 3)));

        $q = KyBooking::query()
            ->whereBetween('start_at', [now()->subDays($days)->startOfDay(), now()->endOfDay()])
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where(function ($w) {
                $w->where('is_trial', true)->orWhere('course_kind', 'private');
            });
        if (userHasRole($u, 'R_SUPER')) {
            // 双店
        } elseif (userHasRole($u, 'R_TEACHER')) {
            $q->where(staffOwnerFilter($u, 'teacher_user_id', 'teacher_name'))->where('venue', $u->venue);
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
            'bodyTestReportId' => 'nullable|integer',
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

        // 体测报告：把体态与体成分映射出的观察项并进来，老师不用再逐项勾；
        // 报告里的红线提示也并进来，由规则引擎统一做硬拦截。
        $bodyTestReportId = $r->input('bodyTestReportId') ?: null;
        $bodyTest = null;
        if ($bodyTestReportId) {
            $bodyTest = BodyTestReport::find((int) $bodyTestReportId);
            if ($bodyTest) {
                $existing = array_column($observations, 'key');
                foreach ((array) $bodyTest->observations as $key) {
                    if (! in_array($key, $existing, true)) {
                        $observations[] = ['key' => (string) $key, 'level' => '中'];
                        $existing[] = $key;
                    }
                }
                foreach ((array) $bodyTest->red_flags as $flag) {
                    $redFlags[] = (string) $flag;
                }
                $redFlags = array_values(array_unique($redFlags));
            }
        }

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
            'bodyTestReportId' => $bodyTest?->id,
            'bodyTest' => $bodyTest ? [
                'id' => $bodyTest->id,
                'testedAt' => $bodyTest->tested_at?->format('Y-m-d'),
                'score' => $bodyTest->score,
                'profile' => $bodyTest->profile,
                'abnormal' => $bodyTest->abnormal,
                'posture' => $bodyTest->posture,
                'directions' => $bodyTest->directions,
                'health' => $bodyTest->health,
                'sourceUrl' => $bodyTest->source_url,
            ] : null,
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
        if (userHasRole($u, 'R_TEACHER')) {
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

    /** 把体测报告挂到本次分析对应的会员/客资上，方便后续在会员档案里纵向对比 */
    private function linkBodyTest(array $payload, PostClassReview $row): void
    {
        $id = $payload['bodyTestReportId'] ?? null;
        if (! $id) {
            return;
        }
        $report = BodyTestReport::find($id);
        if (! $report) {
            return;
        }
        $report->fill([
            'customer_id' => $report->customer_id ?: $row->customer_id,
            'lead_id' => $report->lead_id ?: $row->lead_id,
            'member_name' => $report->member_name !== '' ? $report->member_name : $row->student_name,
            'phone' => $report->phone !== '' ? $report->phone : $row->student_phone,
            'venue' => $report->venue !== '' ? $report->venue : $row->venue,
        ])->save();
    }

    private function auditCreate(Request $r, PostClassReview $row): void
    {
        audit($r, '新增', '课后分析', $row->id, $row->student_name, $row->venue,
            $row->red_flag
                ? '填写课后分析：命中红线，未产出训练方案'
                : '填写课后分析：'.$row->student_type.'方向已生成');
    }
}
