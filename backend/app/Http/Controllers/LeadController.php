<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    private array $leadFields = ['lead_date', 'name', 'phone', 'wechat', 'demand', 'source', 'order_platform', 'venue', 'service_teacher', 'status', 'grade', 'trial_time', 'trial_topic', 'trial_teacher', 'deal_card', 'deal_amount', 'redeem_amount', 'voucher_code', 'coupon_name', 'coupon_total', 'coupon_remaining', 'trial_cards', 'remark'];

    /** GET /leads */
    public function index(Request $r)
    {
        $u = $r->user();
        $q = Lead::query()->where('venue', 'like', '%');
        if ($u->role === 'R_MANAGER') {
            $q->where('venue', $u->venue);
        }
        if ($u->role === 'R_TEACHER') {
            if ($u->venue) {
                $q->where('venue', $u->venue);
            }
            $q->where(function ($w) use ($u) {
                $w->where('service_teacher', $u->name)->orWhere('service_teacher', '');
            });
        }
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
            $q->where(function ($w) use ($c) {
                $w->where('phone', 'like', "%{$c}%")
                    ->orWhere('wechat', 'like', "%{$c}%");
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
        $rows = $q->orderByDesc('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    }

    /** GET /leads/check：新增留资时校验手机号是否已命中会员 / 已有留资 */
    public function check(Request $r)
    {
        $phone = trim((string) $r->query('phone', ''));
        if ($phone === '') {
            return ok(['exists' => false, 'matches' => []]);
        }

        $matches = [];
        foreach (Customer::where('phone', $phone)->get() as $c) {
            $layer = $c->layer === 'P5' ? '留资' : '会员';
            $matches[] = ['kind' => $layer, 'name' => $c->name, 'venue' => $c->venue, 'detail' => trim((string) $c->main_card) !== '' && $c->main_card !== '—' ? $c->main_card : '尚未购卡'];
        }
        foreach (Lead::where('phone', $phone)->orderByDesc('id')->get() as $l) {
            $matches[] = ['kind' => '已有留资', 'name' => $l->name, 'venue' => $l->venue, 'detail' => $l->status, 'id' => $l->id];
        }

        return ok(['exists' => count($matches) > 0, 'matches' => $matches]);
    }

    /** POST /leads */
    public function store(Request $r)
    {
        $d = $r->validate([
            'name' => 'required|string', 'source' => 'required|string', 'venue' => 'required|string',
            'leadDate' => 'nullable|date', 'dealAmount' => 'nullable|numeric|min:0|decimal:0,2', 'redeemAmount' => 'nullable|numeric|min:0|decimal:0,2',
        ]);
        $values = array_intersect_key(camelToSnake($r->all()), array_flip($this->leadFields)) + ['created_by' => $r->user()->name, 'status' => $r->input('status', '新留资')];
        $values['lead_date'] = $values['lead_date'] ?? now()->toDateString();
        foreach (['deal_amount', 'redeem_amount'] as $f) {
            if (($values[$f] ?? '') === '') {
                $values[$f] = null;
            }
        }
        if ($values['status'] === '已成交') {
            $values['deal_at'] = now();
        }
        if ((float) ($values['redeem_amount'] ?? 0) > 0) {
            $values['redeemed_at'] = now();
        }
        $lead = Lead::create($values);
        audit($r, '新增', '前端客资', $lead->id, "{$lead->name}（{$lead->source}）", $lead->venue, '录入客资');
        invalidateBusinessCaches('analytics');

        return ok(['id' => $lead->id]);
    }

    /** PATCH /leads/{id} */
    public function update(Request $r, int $id)
    {
        $lead = Lead::findOrFail($id);
        $before = json_encode(camel($lead), JSON_UNESCAPED_UNICODE);
        $r->validate([
            'leadDate' => 'nullable|date', 'dealAmount' => 'nullable|numeric|min:0', 'redeemAmount' => 'nullable|numeric|min:0',
        ]);
        $changes = array_intersect_key(camelToSnake($r->all()), array_flip($this->leadFields));
        if (isset($changes['lead_date']) && $changes['lead_date'] === '') {
            unset($changes['lead_date']);
        }
        foreach (['deal_amount', 'redeem_amount'] as $f) {
            if (($changes[$f] ?? '') === '') {
                $changes[$f] = null;
            }
        }
        if (($changes['status'] ?? null) === '已成交' && ! $lead->deal_at) {
            $changes['deal_at'] = now();
        }
        if ((float) ($changes['redeem_amount'] ?? 0) > 0 && ! $lead->redeemed_at) {
            $changes['redeemed_at'] = now();
        }
        $lead->update($changes);
        audit($r, '修改', '前端客资', $id, "{$lead->name}（{$lead->source}）", $lead->venue, '字段更新');
        invalidateBusinessCaches('analytics');

        return ok(['before' => json_decode($before), 'after' => camel($lead)]);
    }

    /** DELETE /leads/{id}：删除留资，权限与「编辑」一致（店长本店 / 超管新媒体全部 / 老师本人或未分配），删除写留痕 */
    public function destroy(Request $r, int $id)
    {
        $u = $r->user();
        $lead = Lead::findOrFail($id);
        if ($u->role === 'R_MANAGER' && $lead->venue !== $u->venue) {
            abort(403, '无权限：仅可删除本店留资');
        }
        if ($u->role === 'R_TEACHER') {
            if ($u->venue && $lead->venue !== $u->venue) {
                abort(403, '无权限：仅可删除本店留资');
            }
            if ($lead->service_teacher !== '' && $lead->service_teacher !== $u->name && $lead->created_by !== $u->name) {
                abort(403, '无权限：仅可删除自己名下或未分配的留资');
            }
        }
        if (! in_array($u->role, ['R_SUPER', 'R_MANAGER', 'R_TEACHER', 'R_MEDIA'], true)) {
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
