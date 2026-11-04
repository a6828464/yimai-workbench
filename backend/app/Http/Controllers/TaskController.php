<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\WeChatSubscribeMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class TaskController extends Controller
{
    /**
     * 新待办提醒（微信订阅消息，模板 todoReminder）。
     *
     * 放 defer() 里：先把任务保存结果返回，微信调用在响应结束后执行 —— 微信慢、模板没配、
     * 负责人姓名对不上账号，用户都不会看到异常，任务照常创建（与客资/会员通知同一纪律）。
     *
     * 只在负责人能解析到**唯一**账号时发：「未分配」与未开通账号的人名在任务表里真实存在
     * （店长手写人名），那种情况由服务层静默跳过并记 skipped 流水，绝不阻断创建。
     */
    private function notifyTodoReminder(Task $task): void
    {
        $owner = trim((string) $task->owner);
        if ($owner === '' || $owner === '未分配') {
            return;
        }

        defer(function () use ($task, $owner) {
            try {
                app(WeChatSubscribeMessage::class)->notifyTodoReminder(
                    $owner,
                    (string) $task->title,
                    (string) $task->deadline,
                    (string) $task->venue
                );
            } catch (\Throwable $e) {
                Log::warning('[wx-subscribe] 待办通知失败', [
                    'task_id' => $task->id,
                    'owner' => $owner,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /** GET /tasks */
    public function index(Request $r)
    {
        $u = $r->user();
        // 可见范围收敛到 scopeTasksForUser（helpers.php）—— 任务可见范围的**唯一收口点**，
        // 角色识别与「角色不明 → 空集」兜底都在那里。本方法此前自己写了一遍四个角色分支
        // 外加一段兜底注释与 whereRaw，是六处重复实现之一（t17 消除）。
        $q = scopeTasksForUser(Task::query(), $u, 'list');
        if ($status = $r->query('status')) {
            $q->where('status', $status);
        }
        if ($venue = $r->query('venue')) {
            abort_if(! userHasRole($u, 'R_SUPER') && $venue !== $u->venue, 403, '无权查看其它门店任务');
            $q->where('venue', $venue);
        }
        $current = max(1, (int) $r->query('current', 1));
        $size = min(100, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->orderBy('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    }

    /** POST /tasks */
    public function store(Request $r)
    {
        abort_unless(userHasAnyRole($r->user(), ['R_SUPER', 'R_MANAGER', 'R_SERVICE', 'R_TEACHER']), 403, '无权创建任务');
        $d = $r->validate([
            'title' => 'required|string|max:50',
            'customerName' => 'required|string|max:20',
            'venue' => 'required|string|in:绿地店,东部店',
            'owner' => 'nullable|string|max:20',
            'priority' => 'nullable|in:高,中,低',
            'deadline' => 'nullable|string|max:24',
            'standard' => 'nullable|string|max:200',
        ]);
        abort_if(! userHasRole($r->user(), 'R_SUPER') && $d['venue'] !== $r->user()->venue, 403, '无权创建其它门店任务');
        if (userHasRole($r->user(), 'R_SERVICE') || userHasRole($r->user(), 'R_TEACHER')) {
            $d['owner'] = $r->user()->name;
        }
        $task = Task::create([
            'title' => $d['title'],
            'customer_name' => $d['customerName'],
            'venue' => $d['venue'],
            'owner' => $d['owner'] ?? '未分配',
            'owner_user_id' => staffUserId($d['owner'] ?? null),
            'priority' => $d['priority'] ?? '中',
            'deadline' => $d['deadline'] ?? '',
            'standard' => $d['standard'] ?? '',
            'status' => '待接收',
        ]);
        invalidateBusinessCaches('analytics');
        audit($r, '新增', '任务中心', $task->id, "{$task->title}·{$task->customer_name}", $task->venue, "创建任务，负责人[{$task->owner}]，验收标准[{$task->standard}]");
        // 指派给具体某人 → 微信提醒（模板未配时服务层自然 skipped）
        $this->notifyTodoReminder($task);

        return ok(camel($task));
    }

    /** PATCH /tasks/{id} */
    public function update(Request $r, int $id)
    {
        $u = $r->user();
        abort_if(userHasRole($u, 'R_MEDIA'), 403, '无权操作任务');
        // 与 index() 同一兜底：角色不明账号连**写**都不该放行。
        // 下面每个分支都是「按角色授权」，一个都不命中时没有任何守卫，原先这个账号能改任意
        // 门店任意人的任务（标题/负责人/门店/优先级），实测 PATCH 他人任务返回 200 且字段真的被改。
        // store() 早就有同类守卫（abort_unless userHasAnyRole），这里补齐同一口径：
        // 角色不明 = 身份未确认 = 一律 403，而不是「按门店猜一个范围」。
        abort_unless(isKnownRoleUser($u), 403, '无权操作任务');
        $task = DB::transaction(function () use ($r, $id, $u) {
            $task = Task::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if(userHasRole($u, 'R_MANAGER') && $task->venue !== $u->venue, 403, '无权操作其它门店任务');
            if (userHasRole($u, 'R_SERVICE') || userHasRole($u, 'R_TEACHER')) {
                $claimable = userHasRole($u, 'R_SERVICE') ? [$u->name, '未分配'] : [$u->name];
                abort_if($task->venue !== $u->venue || ! in_array($task->owner, $claimable, true), 403, '只能操作本人任务');
                $requested = (string) $r->input('status', $task->status);
                $allowedTransitions = [
                    '待接收' => ['进行中'],
                    '进行中' => ['待验收'],
                    '已退回' => ['进行中', '待验收'],
                ];
                abort_unless($requested === $task->status || in_array($requested, $allowedTransitions[$task->status] ?? [], true), 422, '任务状态流转无效');
                abort_if($r->hasAny(['title', 'venue', 'priority', 'deadline', 'standard']), 403, '老师只能认领或提报本人任务');
            }
            if (in_array($r->input('status'), ['已完成', '已退回'], true)) {
                abort_unless(userHasAnyRole($u, ['R_SUPER', 'R_MANAGER']), 403, '仅店长及以上可验收');
                abort_unless($task->status === '待验收', 422, '仅待验收任务可执行验收');
            }
            $allowed = ['title', 'customer_name', 'venue', 'owner', 'priority', 'deadline', 'standard', 'status'];
            $patch = collect(camelToSnake($r->all()))->only($allowed)->all();
            // 截止时间/验收标准可清空（非空文本列写 ''）；门店、负责人、状态是流转与归属依据，不许被清空
            $patch = normalizeEmptyValues('tasks', $patch, except: ['venue', 'owner', 'priority', 'status', 'title', 'customer_name']);
            if (array_key_exists('owner', $patch)) {
                // 改负责人时 id 一起改，否则该任务还会挂在上一个人的 id 上
                $patch['owner_user_id'] = staffUserId((string) $patch['owner']);
            }
            $task->update($patch);

            return $task;
        });
        invalidateBusinessCaches('analytics');
        audit($r, $r->input('_action', '修改'), '任务中心', $task->id, "{$task->title}·{$task->customer_name}", $task->venue, '任务流转：'.($r->input('status') ?: '字段更新'));

        return ok(camel($task));
    }
}
