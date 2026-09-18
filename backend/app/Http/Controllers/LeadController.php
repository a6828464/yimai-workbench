<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Http\Request;

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

    private array $leadFields = ['lead_date', 'name', 'phone', 'wechat', 'demand', 'source', 'order_platform', 'venue', 'service_teacher', 'status', 'grade', 'trial_time', 'trial_topic', 'trial_teacher', 'deal_card', 'deal_amount', 'redeem_amount', 'voucher_code', 'coupon_name', 'coupon_total', 'coupon_remaining', 'trial_cards', 'remark'];

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
        $rows = $q->orderByDesc('id')->forPage($current, $size)->get()->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
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
            $layer = $c->layer === 'P5' ? '留资' : '会员';
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
        $d = $r->validate([
            'name' => 'required|string', 'source' => 'required|string', 'venue' => 'required|string',
            'leadDate' => 'nullable|date', 'dealAmount' => 'nullable|numeric|min:0|decimal:0,2', 'redeemAmount' => 'nullable|numeric|min:0|decimal:0,2',
        ]);
        $values = array_intersect_key(camelToSnake($r->all()), array_flip($this->leadFields)) + ['created_by' => $r->user()->name];
        $values = $this->withStaffIds($values);
        $values = $this->withNormalizedPhone($values);
        // 清空的字段（null）按列定义落成该列能接受的形态：可空列写 null，非空文本列写 ''。
        // status 不可清空：写空会让这条留资在按状态筛选/统计里消失，留空一律按「新留资」入档。
        $values = normalizeEmptyValues('leads', $values, except: ['status']);
        $values['status'] = $values['status'] ?? '新留资';
        $values['lead_date'] = $values['lead_date'] ?? now()->toDateString();
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
        $changes = $this->withStaffIds($changes);
        $changes = $this->withNormalizedPhone($changes);
        // 显式清空的字段落成该列能接受的形态；没传的字段不在 $changes 里，保持原值。
        // 注意别再写"$changes[$f] ?? '' 就置 null"那种兜底 —— 那会把用户没碰过的字段一起清掉
        // （老师只改备注也会把成交金额抹成 null）。lead_date 是非空日期列，由列定义兜住（清不掉）。
        $changes = normalizeEmptyValues('leads', $changes, except: ['venue', 'source', 'status', 'name']);
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
