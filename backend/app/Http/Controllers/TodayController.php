<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TodoAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TodayController extends Controller
{
    /** GET /today/snapshot */
    public function snapshotShow(Request $r)
    {
        $snap = AppSetting::oldest('id')->first()?->snapshot ?? [];

        return ok($snap);
    }

    /** PUT /today/snapshot */
    public function snapshotUpdate(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'todayBookings' => 'required|array',
            'todayBookings.绿地店' => 'required|integer|min:0',
            'todayBookings.东部店' => 'required|integer|min:0',
            'trialBookings' => 'required|array',
            'trialBookings.绿地店' => 'required|integer|min:0',
            'trialBookings.东部店' => 'required|integer|min:0',
            'todayKinds' => 'nullable|array',
            'todayKinds.绿地店' => 'nullable|array',
            'todayKinds.东部店' => 'nullable|array',
        ]);
        $s = setting();
        $s = DB::transaction(function () use ($s, $d, $r) {
            $locked = AppSetting::whereKey($s->id)->lockForUpdate()->first() ?? $s;
            $snap = $locked->snapshot ?? [];
            $snap['todayBookings'] = [
                '绿地店' => (int) ($d['todayBookings']['绿地店'] ?? 0),
                '东部店' => (int) ($d['todayBookings']['东部店'] ?? 0),
            ];
            $snap['trialBookings'] = [
                '绿地店' => (int) ($d['trialBookings']['绿地店'] ?? 0),
                '东部店' => (int) ($d['trialBookings']['东部店'] ?? 0),
            ];
            if (isset($d['todayKinds'])) {
                $snap['todayKinds'] = [
                    '绿地店' => [
                        '私教' => (int) ($d['todayKinds']['绿地店']['私教'] ?? 0),
                        '小班' => (int) ($d['todayKinds']['绿地店']['小班'] ?? 0),
                        '团课' => (int) ($d['todayKinds']['绿地店']['团课'] ?? 0),
                    ],
                    '东部店' => [
                        '私教' => (int) ($d['todayKinds']['东部店']['私教'] ?? 0),
                        '小班' => (int) ($d['todayKinds']['东部店']['小班'] ?? 0),
                        '团课' => (int) ($d['todayKinds']['东部店']['团课'] ?? 0),
                    ],
                ];
            }
            $snap['fetchedAt'] = array_merge((array) ($snap['fetchedAt'] ?? []), [
                '绿地店' => now()->format('Y-m-d H:i:s'),
                '东部店' => now()->format('Y-m-d H:i:s'),
            ]);
            $snap['fetchedBy'] = $r->user()->name;
            $locked->update(['snapshot' => $snap]);

            return $snap;
        });

        return ok($s);
    }

    /** GET /today/summary */
    public function summary(Request $r)
    {
        $u = $r->user();

        $custQ = scopeCustomersForUser(Customer::query(), $u);
        $scopedCustomers = $custQ->get();

        $leadQ = scopeLeadsForUser(Lead::query(), $u);
        $leads = $leadQ->get();

        $taskQ = Task::query();
        if ($u->role === 'R_MANAGER') {
            $taskQ->where('venue', $u->venue);
        }
        if ($u->role === 'R_TEACHER') {
            $taskQ->where(fn ($w) => $w->where('owner', $u->name)->orWhere('owner', '未分配'));
        }
        if ($u->role === 'R_MEDIA') {
            $taskQ->where('owner', $u->name);
        }
        $overdueTasks = $taskQ->where('status', '已逾期')->count();

        $renewalIds = filteredIds('待续课');
        $expiringMembers = $scopedCustomers->whereIn('id', $renewalIds)->count();
        $tomorrow = now()->addDay()->toDateString();

        $setting = AppSetting::oldest('id')->first();
        $snap = $setting?->snapshot;

        return ok([
            'newLeads' => $scopedCustomers->where('layer', 'P5')->count() + $leads->where('status', '新留资')->count(),
            'pendingFollowup' => $scopedCustomers->where('next_action_time', '!=', '')->where('next_action_time', '<=', $tomorrow.' 23:59:59')->count(),
            'expiringMembers' => $expiringMembers,
            'riskCount' => $overdueTasks + $scopedCustomers->where('owner', '未分配')->count() + $leads->where('status', '新留资')->count(),
            'pendingApprovals' => $u->role === 'R_MEDIA' ? 0 : Approval::where('status', 'like', '待%')->when($u->role === 'R_MANAGER', fn ($q) => $q->where('venue', $u->venue))->count(),
            'todayBookings' => [
                '绿地店' => (! $u->venue || $u->venue === '绿地店') ? ($snap['todayBookings']['绿地店'] ?? 0) : 0,
                '东部店' => (! $u->venue || $u->venue === '东部店') ? ($snap['todayBookings']['东部店'] ?? 0) : 0,
            ],
            'trialBookings' => [
                '绿地店' => (! $u->venue || $u->venue === '绿地店') ? ($snap['trialBookings']['绿地店'] ?? 0) : 0,
                '东部店' => (! $u->venue || $u->venue === '东部店') ? ($snap['trialBookings']['东部店'] ?? 0) : 0,
            ],
            'todayKinds' => isset($snap['todayKinds']) ? [
                '绿地店' => (! $u->venue || $u->venue === '绿地店') ? (array) ($snap['todayKinds']['绿地店'] ?? ['私教' => 0, '小班' => 0, '团课' => 0]) : ['私教' => 0, '小班' => 0, '团课' => 0],
                '东部店' => (! $u->venue || $u->venue === '东部店') ? (array) ($snap['todayKinds']['东部店'] ?? ['私教' => 0, '小班' => 0, '团课' => 0]) : ['私教' => 0, '小班' => 0, '团课' => 0],
            ] : null,
            'scopeLabel' => $u->venue ? "本店 · {$u->venue}" : '双店',
            'snapshotTime' => in_array($u->role, ['R_SUPER', 'R_MANAGER'], true)
                ? (is_array($snap['fetchedAt'] ?? null) ? implode(' / ', array_values(array_filter((array) $snap['fetchedAt']))) : (string) ($snap['fetchedAt'] ?? ''))
                : '',
            // 区分「今天真没预约」与「该店从未同步/快照缺失」，前端据此给出可读提示而非误显示 0
            'snapshotAvailable' => $snap !== null && is_array($snap['todayBookings'] ?? null),
        ]);
    }

    /** GET /today/followups */
    public function followups(Request $r)
    {
        $u = $r->user();
        if ($u->role === 'R_MEDIA') {
            return ok([]);
        }
        $q = scopeCustomersForUser(Customer::query(), $u)->whereIn('layer', ['P0', 'P1', 'P5']);

        return ok($q->orderBy('id')->limit(6)->get()->map(function ($c) {
            $arr = camel($c);
            $days = $c->last_visit ? (int) ((time() - strtotime($c->last_visit)) / 86400) : 9999;
            $arr['lastVisitDays'] = $days;

            return $arr;
        }));
    }

    /** GET /today/alerts */
    public function alerts(Request $r)
    {
        $u = $r->user();
        $alerts = [];

        $leadQ = scopeLeadsForUser(Lead::query(), $u)->where('status', '新留资');
        foreach ($leadQ->orderByDesc('id')->limit(2)->get() as $l) {
            $alerts[] = ['id' => 9000 + $l->id, 'level' => '高', 'text' => "[{$l->venue}] 新客资 {$l->name} 待首响（{$l->source}）", 'action' => '24小时内完成首轮联系'];
        }

        $taskQ = Task::query()->where('status', '已逾期');
        if ($u->role === 'R_MANAGER') {
            $taskQ->where('venue', $u->venue);
        }
        if ($u->role === 'R_TEACHER') {
            $taskQ->where('venue', $u->venue)->where(fn ($w) => $w->where('owner', $u->name)->orWhere('owner', '未分配'));
        }
        if ($u->role === 'R_MEDIA') {
            $taskQ->whereRaw('1 = 0');
        }
        foreach ($taskQ->limit(4)->get() as $t) {
            $alerts[] = ['id' => 100 + $t->id, 'level' => '中', 'text' => "任务「{$t->title}-{$t->customer_name}」已逾期", 'action' => '提醒责任人完成闭环'];
        }

        $expiring = scopeCustomersForUser(Customer::query(), $u)
            ->when($u->role === 'R_MEDIA', fn ($q) => $q->whereRaw('1 = 0'))
            ->whereIn('id', filteredIds('待续课'))->orderBy('expire_date')->limit(2)->get();
        foreach ($expiring as $c) {
            $alerts[] = ['id' => 200 + $c->id, 'level' => '中', 'text' => "{$c->name} 卡项临近到期（剩余{$c->remain_times}节）", 'action' => '确认续费窗口沟通结果'];
        }

        return ok($alerts);
    }

    /** GET /today/todo */
    public function todo(Request $r)
    {
        $u = $r->user();
        $today = now()->startOfDay();
        $isMedia = $u->role === 'R_MEDIA';
        $isSuper = $u->role === 'R_SUPER';

        // 今日已处理的待办（全店共享：任何人标记，全员消隐，留痕可溯）
        $doneActions = TodoAction::query()->whereDate('action_date', $today->toDateString())->get()->keyBy('todo_key');
        $doneInfo = function (string $key) use ($doneActions): array {
            $rec = $doneActions->get($key);
            if (! $rec) {
                return ['done' => false];
            }

            return [
                'done' => true,
                'doneAction' => (string) $rec->action,
                'doneBy' => (string) $rec->user_name,
                'doneAt' => $rec->created_at?->timezone(config('app.timezone'))->format('H:i'),
                'doneRemark' => (string) $rec->remark,
            ];
        };

        // 五清单口径复用会员管理引擎，一次算好 id => 清单集合 映射
        $lists = memberListIds();
        $listMap = [];
        foreach (['待续课', '预流失', '待复活', '出勤降低', 'VIP'] as $key) {
            foreach ($lists[$key] ?? [] as $id) {
                $listMap[$id][] = $key;
            }
        }

        $scopedCustomers = scopeCustomersForUser(Customer::query(), $u)->get()->map(fn ($c) => camel($c));
        $customerByPhone = $scopedCustomers->filter(fn ($c) => (string) $c['phone'] !== '')->keyBy('phone');
        $customerByExternal = $scopedCustomers->filter(fn ($c) => (string) ($c['externalId'] ?? '') !== '')
            ->keyBy(fn ($c) => preg_replace('/^ky:\d+:/', '', (string) $c['externalId']));
        $customerByName = $scopedCustomers->keyBy(fn ($c) => $c['venue'].'|'.$c['name']);
        $matchCustomer = function (string $phone, string $memberId, string $name, string $venue) use ($customerByPhone, $customerByExternal, $customerByName) {
            if ($phone !== '' && isset($customerByPhone[$phone])) {
                return $customerByPhone[$phone];
            }
            if ($memberId !== '' && isset($customerByExternal[$memberId])) {
                return $customerByExternal[$memberId];
            }
            if ($name !== '' && isset($customerByName[$venue.'|'.$name])) {
                return $customerByName[$venue.'|'.$name];
            }

            return null;
        };
        $daysBetween = fn ($date) => $date ? (int) now()->startOfDay()->diffInDays($date, false) : null;
        $serviceFlags = function (array $c) use ($listMap, $daysBetween): array {
            $flags = $listMap[$c['id']] ?? [];
            if (($c['evalLevel'] ?? null) === 'low') {
                $flags[] = '评估低分';
            }
            if (! empty($c['needsHelp'])) {
                $flags[] = '需协助';
            }
            if (($daysBetween($c['expireDate']) ?? 99) >= 0 && ($daysBetween($c['expireDate']) ?? 99) <= 7) {
                $flags[] = '7天内到期';
            }

            return array_values(array_unique($flags));
        };

        // ---- 今日预约（随心瑜预约事实，排除已取消/爽约） ----
        $bookings = [];
        $bookingTrialCount = 0;
        if (! $isMedia) {
            $bookingQ = KyBooking::query()
                ->whereBetween('start_at', [$today->copy(), $today->copy()->endOfDay()])
                ->whereNotIn('status', ['cancelled', 'no_show']);
            if (! $isSuper) {
                $bookingQ->where('venue', $u->venue);
            }
            foreach ($bookingQ->orderBy('start_at')->get() as $b) {
                $phone = preg_replace('/\D+/', '', (string) $b->phone) ?? '';
                $customer = $matchCustomer($phone, (string) $b->member_id, (string) $b->member_name, (string) $b->venue);
                $rawData = is_array($b->raw) ? $b->raw : ((array) json_decode((string) $b->raw, true));
                $kind = match ((string) ($rawData['course_type'] ?? '')) {
                    '2' => '私教',
                    '3' => '小班',
                    default => '团课',
                };
                $bookings[] = [
                    'id' => $b->id,
                    'key' => 'booking:'.$b->id,
                    'time' => $b->start_at?->format('H:i'),
                    'memberName' => (string) $b->member_name,
                    // 预约行 KY 未带手机号时，回填匹配到的会员档案手机号
                    'phone' => $phone !== '' ? $phone : (string) ($customer['phone'] ?? ''),
                    'phoneTail' => $phone !== '' ? substr($phone, -4) : '',
                    'venue' => (string) $b->venue,
                    'course' => (string) $b->course_name,
                    'kind' => $kind,
                    'teacher' => (string) $b->teacher_name,
                    'status' => (string) $b->status,
                    'isTrial' => (bool) $b->is_trial,
                    'customerId' => $customer['id'] ?? null,
                    'lists' => $customer ? $serviceFlags($customer) : [],
                    'birthdayToday' => $customer ? isBirthdayToday($customer['birthday'] ?? null) : false,
                ] + $doneInfo('booking:'.$b->id);
                if ($b->is_trial) {
                    $bookingTrialCount++;
                }
            }
        }

        // ---- 待续费：待续课清单（临近到期优先） ----
        $renewals = [];
        if (! $isMedia) {
            $renewals = $scopedCustomers
                ->filter(fn ($c) => in_array('待续课', $listMap[$c['id']] ?? [], true))
                ->map(function ($c) use ($daysBetween, $listMap, $doneInfo) {
                    $expireDays = $daysBetween($c['expireDate']);

                    return [
                        'id' => $c['id'],
                        'key' => 'renewal:'.$c['id'],
                        'name' => $c['name'],
                        'phone' => (string) ($c['phone'] ?? ''),
                        'phoneTail' => $c['phoneTail'],
                        'venue' => $c['venue'],
                        'mainCard' => $c['mainCard'],
                        'remainTimes' => $c['remainTimes'],
                        'expireDate' => $c['expireDate'],
                        'expireDays' => $expireDays,
                        'urgent' => $expireDays !== null && $expireDays <= 7,
                        'owner' => $c['owner'],
                        'consultant' => $c['consultant'] ?? '',
                        'evalLevel' => $c['evalLevel'] ?? null,
                        'hasRenewalPlan' => ! empty($c['renewalPlan']),
                        'lists' => $listMap[$c['id']] ?? [],
                    ] + $doneInfo('renewal:'.$c['id']);
                })
                ->sortBy(fn ($c) => [$c['expireDays'] === null ? 9999 : $c['expireDays'], $c['remainTimes'] ?? 999])
                ->values()->all();
        }

        // ---- 可能流失：预流失 + 待复活 + 出勤降低 ----
        $churnRisks = [];
        if (! $isMedia) {
            $riskSets = ['预流失', '待复活', '出勤降低'];
            $churnRisks = $scopedCustomers
                ->filter(fn ($c) => collect($riskSets)->contains(fn ($k) => in_array($k, $listMap[$c['id']] ?? [], true)))
                ->map(function ($c) use ($listMap, $doneInfo) {
                    $lists = array_values(array_intersect($listMap[$c['id']] ?? [], ['预流失', '待复活', '出勤降低']));
                    $lastVisitDays = $c['lastVisit'] ? abs((int) ((time() - strtotime((string) $c['lastVisit'])) / 86400)) : null;

                    return [
                        'id' => $c['id'],
                        'key' => 'churn:'.$c['id'],
                        'name' => $c['name'],
                        'phone' => (string) ($c['phone'] ?? ''),
                        'phoneTail' => $c['phoneTail'],
                        'venue' => $c['venue'],
                        'lists' => $lists,
                        'lastVisit' => $c['lastVisit'],
                        'lastVisitDays' => $lastVisitDays,
                        'stopReason' => $c['stopReason'],
                        'expectedReturn' => $c['expectedReturn'],
                        'needsHelp' => (bool) $c['needsHelp'],
                        'evalLevel' => $c['evalLevel'] ?? null,
                        'owner' => $c['owner'],
                        'consultant' => $c['consultant'] ?? '',
                    ] + $doneInfo('churn:'.$c['id']);
                })
                ->sortByDesc(fn ($c) => $c['lastVisitDays'] ?? 999)
                ->values()->all();
        }

        // ---- 生日关怀：今日 + 未来7天 ----
        $birthdays = [];
        if (! $isMedia) {
            $start = $today->copy();
            $window = [];
            for ($i = 0; $i <= 7; $i++) {
                $window[] = $start->copy()->addDays($i)->format('m-d');
            }
            $birthdays = $scopedCustomers
                ->filter(fn ($c) => ! empty($c['birthday']) && in_array(substr((string) $c['birthday'], 5, 5), $window, true))
                ->map(function ($c) use ($today, $listMap, $doneInfo) {
                    $md = substr((string) $c['birthday'], 5, 5);
                    $offset = (int) ((strtotime(date('Y').'-'.$md) - strtotime($today->format('Y-m-d'))) / 86400);
                    $offset = $offset < 0 ? $offset + 366 : $offset; // 跨年兜底（2/29）

                    return [
                        'id' => $c['id'],
                        'key' => 'birthday:'.$c['id'],
                        'name' => $c['name'],
                        'phone' => (string) ($c['phone'] ?? ''),
                        'phoneTail' => $c['phoneTail'],
                        'venue' => $c['venue'],
                        'birthday' => $c['birthday'],
                        'isToday' => $offset === 0,
                        'daysLater' => $offset,
                        'age' => (int) date('Y') - (int) substr((string) $c['birthday'], 0, 4),
                        'owner' => $c['owner'],
                        'consultant' => $c['consultant'] ?? '',
                        'lists' => $listMap[$c['id']] ?? [],
                    ] + $doneInfo('birthday:'.$c['id']);
                })
                ->sortBy(fn ($c) => [$c['isToday'] ? 0 : 1, $c['daysLater']])
                ->values()->all();
        }

        // ---- 今日体验课：随心瑜体验预约事实 + 留资体验课卡片 ----
        $trials = [];
        if (! $isMedia) {
            $trialQ = KyBooking::query()
                ->whereBetween('start_at', [$today->copy(), $today->copy()->endOfDay()])
                ->where('is_trial', true)
                ->whereNotIn('status', ['cancelled', 'no_show']);
            if (! $isSuper) {
                $trialQ->where('venue', $u->venue);
            }
            foreach ($trialQ->orderBy('start_at')->get() as $b) {
                $digits = preg_replace('/\D+/', '', (string) $b->phone) ?? '';
                $trials[] = [
                    'key' => 'trial:ky-'.$b->id,
                    'time' => $b->start_at?->format('H:i'),
                    'name' => (string) $b->member_name,
                    'phone' => $digits,
                    'phoneTail' => $digits !== '' ? substr($digits, -4) : '',
                    'venue' => (string) $b->venue,
                    'topic' => (string) $b->course_name,
                    'teacher' => (string) $b->teacher_name,
                    'source' => 'ky',
                    'status' => (string) $b->status,
                ] + $doneInfo('trial:ky-'.$b->id);
            }
        }
        $leadQ = scopeLeadsForUser(Lead::query(), $u);
        $newLeads = [];
        foreach ($leadQ->get() as $l) {
            $arr = camel($l);
            // 留资体验课卡片中的今日体验
            foreach ((array) ($l->trial_cards ?? []) as $card) {
                $cardDate = substr((string) ($card['time'] ?? ''), 0, 10);
                if ($cardDate === $today->format('Y-m-d')) {
                    $trialKey = 'trial:lead-'.$l->id.'-'.($card['session'] ?? '');
                    $trials[] = [
                        'key' => $trialKey,
                        'time' => mb_substr((string) ($card['time'] ?? ''), 11, 5) ?: (string) ($card['time'] ?? ''),
                        'name' => (string) $l->name,
                        'phone' => (string) $l->phone,
                        'phoneTail' => substr((string) $l->phone, -4),
                        'venue' => (string) $l->venue,
                        'topic' => (string) ($card['topic'] ?? ''),
                        'teacher' => (string) ($card['teacher'] ?? ''),
                        'source' => 'lead',
                        'status' => (string) $l->status,
                    ] + $doneInfo($trialKey);
                }
            }
            if ($arr['status'] === '新留资') {
                $newLeads[] = [
                    'id' => $l->id,
                    'key' => 'lead:'.$l->id,
                    'name' => (string) $l->name,
                    'phone' => (string) $l->phone,
                    'phoneTail' => substr((string) $l->phone, -4),
                    'venue' => (string) $l->venue,
                    'source' => (string) $l->source,
                    'demand' => (string) $l->demand,
                    'grade' => (string) $l->grade,
                    'serviceTeacher' => (string) $l->service_teacher,
                    'leadDate' => (string) $l->lead_date,
                    'stale' => $l->created_at && $l->created_at->diffInHours(now()) >= 24,
                    'remark' => (string) $l->remark,
                ] + $doneInfo('lead:'.$l->id);
            }
        }
        $newLeads = collect($newLeads)
            ->sortByDesc(fn ($l) => [$l['stale'] ? 1 : 0, $l['id']])
            ->values()->all();
        usort($trials, fn ($a, $b) => strcmp((string) $a['time'], (string) $b['time']));

        // ---- 今日任务：今天到期或逾期 ----
        $taskQ = Task::query()
            ->whereNotIn('status', ['已完成'])
            ->where('deadline', '!=', '')
            ->where('deadline', '<=', $today->format('Y-m-d 23:59'));
        if ($isMedia) {
            $taskQ->where('owner', $u->name);
        } else {
            if (! $isSuper) {
                $taskQ->where('venue', $u->venue);
            }
            if ($u->role === 'R_TEACHER') {
                $taskQ->where(fn ($w) => $w->where('owner', $u->name)->orWhere('owner', '未分配'));
            }
        }
        $tasks = collect($taskQ->orderBy('deadline')->get())->map(fn ($t) => camel($t))
            ->map(fn ($t) => $t + ['overdue' => (string) $t['deadline'] < $today->format('Y-m-d 00:00') || $t['status'] === '已逾期'])
            ->all();

        return ok([
            'date' => $today->format('Y-m-d'),
            // 返回当前生效的清单阈值（客户管理可调），工作台展示并与会员管理口径保持一致
            'rules' => rules(),
            'bookings' => ['items' => $bookings, 'trialCount' => $bookingTrialCount],
            'renewals' => $renewals,
            'churnRisks' => $churnRisks,
            'birthdays' => $birthdays,
            'trials' => $trials,
            'newLeads' => $newLeads,
            'tasks' => $tasks,
            'counts' => [
                'bookings' => count($bookings),
                'renewals' => count($renewals),
                'churnRisks' => count($churnRisks),
                'birthdays' => count($birthdays),
                'trials' => count($trials),
                'newLeads' => count($newLeads),
                'tasks' => count($tasks),
            ],
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /** POST /today/todo/action */
    public function todoAction(Request $r)
    {
        $u = $r->user();
        $d = $r->validate([
            'type' => 'required|in:bookings,renewals,churnRisks,birthdays,trials,newLeads',
            'key' => 'required|string|max:80',
            'action' => 'required|string|max:30',
            'remark' => 'nullable|string|max:200',
            'customerId' => 'nullable|integer',
            'leadId' => 'nullable|integer',
            // renewals/churnRisks 标记沟通时同步更新会员最近触达
            'touch' => 'nullable|boolean',
        ]);
        $today = now()->toDateString();
        $typeLabels = [
            'bookings' => '今日预约', 'renewals' => '待续费', 'churnRisks' => '流失风险',
            'birthdays' => '生日关怀', 'trials' => '体验课', 'newLeads' => '新客首响',
        ];

        DB::transaction(function () use ($r, $u, $d, $today, $typeLabels) {
            // 业务流转 1：新客首响 → 留资状态机（已首响→已联系、无效客资→已流失）
            if ($d['type'] === 'newLeads' && ! empty($d['leadId'])) {
                $lead = Lead::find($d['leadId']);
                if ($lead) {
                    $canHandle = in_array($u->role, ['R_SUPER', 'R_MANAGER'], true)
                        || (string) $lead->service_teacher === (string) $u->name
                        || (string) $lead->service_teacher === '';
                    abort_unless($canHandle, 403, '无权处理该客资');
                    $next = match ($d['action']) {
                        '已首响' => '已联系',
                        '无效客资' => '已流失',
                        default => null,
                    };
                    if ($next !== null && (string) $lead->status !== $next) {
                        $lead->update(['status' => $next]);
                        audit($r, '标记处理', '前端客资', $lead->id, "{$lead->name}（{$lead->source}）", $lead->venue, "今日待办标记：状态变更为 {$next}");
                    }
                }
            }

            // 业务流转 2：续费/流失沟通 → 更新会员最近触达，保证 2 周触达口径不断档
            if (! empty($d['customerId']) && ! empty($d['touch'])) {
                $c = Customer::find($d['customerId']);
                if ($c) {
                    abort_unless(canAccessCustomer($u, $c), 403, '无权处理该会员');
                    $c->update(['last_touch' => $today]);
                }
            }

            TodoAction::updateOrCreate(
                ['todo_key' => $d['key'], 'action_date' => $today],
                [
                    'todo_type' => $d['type'],
                    'action' => $d['action'],
                    'remark' => (string) ($d['remark'] ?? ''),
                    'user_id' => $u->id,
                    'user_name' => $u->name,
                    'user_role' => $u->role,
                    'venue' => (string) ($u->venue ?? ''),
                ]
            );
            invalidateBusinessCaches();
            audit($r, '标记处理', '今日待办', $d['key'], $typeLabels[$d['type']], (string) ($u->venue ?? '双店'), $d['action'].((string) ($d['remark'] ?? '') !== '' ? '：'.$d['remark'] : ''));
        });

        return ok(['done' => true]);
    }
}
