<?php

namespace App\Http\Controllers;

use App\Models\TrainingPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 训练计划（按人隔离）。
 *
 * 这里以**逐条**为单位读写，不再提供「整表替换」语义：
 * 计划有两个创建入口 —— 前端新建、课后分析流转（服务端落库），
 * 整表替换时任何一份较早的客户端列表提交上来，都会把服务端刚写入的那份抹掉
 * （表现为「点了转训练计划，到训练计划里却找不到」）。
 *
 * 批量接口 bulk 仍然保留，但它只是把逐条逻辑循环一遍、并在同一事务里处理显式删除，
 * 语义与单条接口完全一致。
 */
final class TrainingPlanController extends Controller
{
    /** GET /training-plans */
    public function index(Request $r)
    {
        return ok(TrainingPlan::where('created_by', $r->user()->name)->orderBy('id')->get()
            ->map(fn ($p) => $this->present($p)));
    }

    /** POST /training-plans：新建一条（也可用于带客户端 id 的补传） */
    public function store(Request $r)
    {
        $plan = $r->input('plan');
        abort_unless(is_array($plan), 422, 'plan 必须是对象');

        $result = DB::transaction(fn () => $this->upsertOne($plan, $this->owner($r)));

        return ok($result);
    }

    /** PUT /training-plans/{id}：更新本人名下的一条 */
    public function update(Request $r, int $id)
    {
        $row = TrainingPlan::whereKey($id)->where('created_by', $this->owner($r))->first();
        abort_if(! $row, 404, '计划不存在或不属于当前账号');

        $plan = $r->input('plan');
        abort_unless(is_array($plan), 422, 'plan 必须是对象');

        $row->update($this->attributes($plan, $this->owner($r)));

        return ok(['id' => $row->id]);
    }

    /** DELETE /training-plans/{id} */
    public function destroy(Request $r, int $id)
    {
        $deleted = TrainingPlan::whereKey($id)->where('created_by', $this->owner($r))->delete();
        abort_if($deleted === 0, 404, '计划不存在或不属于当前账号');

        return ok(['id' => $id]);
    }

    /**
     * PUT /training-plans/bulk：逐条 upsert + 显式删除。
     *
     * 返回 clientId → serverId 的映射：客户端新建的计划带的是本地 id，
     * 而 id 是全局主键（跨账号可能撞车），服务端在冲突时会另分配主键，
     * 客户端据此校正本地 id，后续更新才不会打偏。
     */
    public function bulkSave(Request $r)
    {
        $plans = $r->input('plans');
        abort_unless(is_array($plans), 422, 'plans 必须是数组');

        $name = $this->owner($r);
        $deletedIds = array_values(array_filter(array_map('intval', (array) $r->input('deletedIds', []))));

        $ids = DB::transaction(function () use ($plans, $name, $deletedIds) {
            if ($deletedIds !== []) {
                TrainingPlan::where('created_by', $name)->whereIn('id', $deletedIds)->delete();
            }
            $ids = [];
            foreach ($plans as $p) {
                if (is_array($p)) {
                    $ids[] = $this->upsertOne($p, $name);
                }
            }

            return $ids;
        });

        return ok(['saved' => count($ids), 'ids' => $ids]);
    }

    private function owner(Request $r): string
    {
        return (string) $r->user()->name;
    }

    private function present(TrainingPlan $p): array
    {
        return array_merge(['id' => $p->id], $p->payload ?? [], [
            // 上游来源：这份计划是哪次课后分析 / 哪份体测得出的
            'sourceReviewId' => $p->source_review_id,
            'sourceBodyTestId' => $p->source_body_test_id,
        ]);
    }

    /**
     * 单条 upsert。
     *
     * - 命中本人已存在的 id → 原地更新（保留 source_review_id 等不由前端维护的字段）
     * - 否则新建；客户端 id 未被任何账号占用时沿用它，否则由数据库分配
     */
    private function upsertOne(array $plan, string $name): array
    {
        $clientId = (int) ($plan['id'] ?? 0);
        $existing = $clientId > 0
            ? TrainingPlan::whereKey($clientId)->where('created_by', $name)->first()
            : null;

        if ($existing) {
            $existing->update($this->attributes($plan, $name));

            return ['clientId' => $clientId, 'serverId' => $existing->id];
        }

        $attrs = $this->attributes($plan, $name);
        if ($clientId > 0 && ! TrainingPlan::whereKey($clientId)->exists()) {
            $attrs['id'] = $clientId;
        }
        $row = TrainingPlan::create($attrs);

        return ['clientId' => $clientId, 'serverId' => $row->id];
    }

    private function attributes(array $p, string $name): array
    {
        return [
            'member_name' => (string) ($p['memberName'] ?? '') ?: '未命名',
            'payload' => $p,
            'status' => (string) ($p['status'] ?? '草稿'),
            'share' => is_array($p['share'] ?? null) ? $p['share'] : null,
            'source' => (string) ($p['source'] ?? ''),
            'created_by' => $name,
            'confirmed_at' => ($p['status'] ?? '') === '已确认' ? now() : null,
        ];
    }
}
