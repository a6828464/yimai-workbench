<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use Illuminate\Http\Request;

final class ApprovalController extends Controller
{
    /** GET /approvals */
    public function index(Request $r)
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['R_SUPER', 'R_MANAGER']), 403);
        $q = Approval::query();
        if ($u->role === 'R_MANAGER') {
            $q->where('venue', $u->venue);
        }
        if ($s = $r->query('status')) {
            $q->where('status', $s);
        }
        $current = max(1, (int) $r->query('current', 1));
        $size = min(100, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $rows = $q->orderBy('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    }

    /** POST /approvals */
    public function store(Request $r)
    {
        if (! in_array($r->user()->role, ['R_SUPER', 'R_MANAGER'], true)) {
            return response()->json(['code' => 1, 'message' => '仅店长及以上可发起价格审批'], 403);
        }
        $d = $r->validate([
            'customerName' => 'required|string|max:20',
            'cardName' => 'required|string|max:40',
            'standardPrice' => 'required|integer|min:0',
            'requestPrice' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:200',
            'venue' => 'nullable|string|in:绿地店,东部店',
        ]);
        if ((float) $d['requestPrice'] >= (float) $d['standardPrice']) {
            return response()->json(['code' => 1, 'message' => '申请价应低于标准价'], 422);
        }
        $a = Approval::create([
            'customer_name' => $d['customerName'],
            'applicant' => $r->user()->name,
            'venue' => $r->user()->role === 'R_MANAGER' ? $r->user()->venue : ($d['venue'] ?? '双店'),
            'card_name' => $d['cardName'],
            'standard_price' => (int) $d['standardPrice'],
            'request_price' => (int) $d['requestPrice'],
            'reason' => $d['reason'] ?? '',
            'status' => '待店长初审',
            'apply_time' => now()->format('Y-m-d H:i'),
        ]);
        audit($r, '发起', '价格审批', $a->id, "价格审批单 #{$a->id}", '双店', "申请[{$a->card_name}] {$a->apply_time} 特价{$a->request_price}/标准{$a->standard_price}");

        return ok(camel($a));
    }

    /** POST /approvals/{id}/decide */
    public function decide(Request $r, int $id)
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['R_SUPER', 'R_MANAGER'], true), 403, '仅店长及以上可审批');
        $a = Approval::findOrFail($id);
        if ($u->role === 'R_MANAGER') {
            abort_unless($a->venue === $u->venue, 403, '无权操作其他门店审批');
        }
        $decision = $r->input('decision');
        $map = ['初审通过' => '待老板终审', '终审通过' => '已通过', '驳回' => '已驳回', '关联成交' => '已关联成交'];
        abort_unless(isset($map[$decision]), 422, '未知决定');
        // 状态机守卫：按当前状态与角色限制可执行的动作
        $state = $a->status;
        $ok = match ($decision) {
            '初审通过' => $state === '待店长初审' && in_array($u->role, ['R_SUPER', 'R_MANAGER'], true),
            '终审通过' => $state === '待老板终审' && $u->role === 'R_SUPER',
            '驳回' => ($state === '待店长初审' && in_array($u->role, ['R_SUPER', 'R_MANAGER'], true))
                || ($state === '待老板终审' && $u->role === 'R_SUPER'),
            '关联成交' => $state === '已通过' && $u->role === 'R_SUPER',
            default => false,
        };
        abort_unless($ok, 422, '当前状态不允许该操作');
        // 乐观条件更新：携带期望旧状态，防止并发双推同一审批单
        $affected = Approval::where('id', $id)->where('status', $state)->update(['status' => $map[$decision]]);
        abort_unless($affected === 1, 422, '当前状态已变化，请刷新后重试');
        $a = Approval::findOrFail($id);
        invalidateBusinessCaches('analytics');
        audit($r, $decision, '价格审批', $id, "价格审批单 #{$id}", '双店', "审批决定：{$decision}");

        return ok(camel($a));
    }
}
