<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TaskController extends Controller
{
    /** GET /tasks */
    public function index(Request $r)
    {
        $u = $r->user();
        $q = Task::query();
        if (userHasRole($u, 'R_MANAGER')) {
            $q->where('venue', $u->venue);
        }
        if (userHasRole($u, 'R_SERVICE')) {
            // 服务老师（会籍顾问）：本人名下 + 待认领池
            $q->where('venue', $u->venue)
                ->where(fn ($w) => $w->whereIn('owner', staffNames($u))->orWhere('owner', '未分配'));
        }
        if (userHasRole($u, 'R_TEACHER')) {
            // 授课老师：只处理派给本人的任务
            $q->where('venue', $u->venue)->whereIn('owner', staffNames($u));
        }
        if (userHasRole($u, 'R_MEDIA')) {
            $q->whereIn('owner', staffNames($u));
        }
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
            'priority' => $d['priority'] ?? '中',
            'deadline' => $d['deadline'] ?? '',
            'standard' => $d['standard'] ?? '',
            'status' => '待接收',
        ]);
        invalidateBusinessCaches('analytics');
        audit($r, '新增', '任务中心', $task->id, "{$task->title}·{$task->customer_name}", $task->venue, "创建任务，负责人[{$task->owner}]，验收标准[{$task->standard}]");

        return ok(camel($task));
    }

    /** PATCH /tasks/{id} */
    public function update(Request $r, int $id)
    {
        $u = $r->user();
        abort_if(userHasRole($u, 'R_MEDIA'), 403, '无权操作任务');
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
            $task->update(collect(camelToSnake($r->all()))->only($allowed)->all());

            return $task;
        });
        invalidateBusinessCaches('analytics');
        audit($r, $r->input('_action', '修改'), '任务中心', $task->id, "{$task->title}·{$task->customer_name}", $task->venue, '任务流转：'.($r->input('status') ?: '字段更新'));

        return ok(camel($task));
    }
}
