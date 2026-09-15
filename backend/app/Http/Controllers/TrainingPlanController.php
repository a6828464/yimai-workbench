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
    /**
     * GET /training-plans
     *
     * 可见范围与其他模块一致：
     *  - 超管：全部
     *  - 店长：本店全部（老师转出来的计划店长要看得到）
     *  - 服务老师/授课老师：自己创建的
     * 之前统一按 created_by 过滤，导致老师转出来的计划只有他自己能看到。
     */
    public function index(Request $r)
    {
        $u = $r->user();
        $q = TrainingPlan::query();

        if (userHasRole($u, 'R_SUPER')) {
            // 不加条件
        } elseif (userHasRole($u, 'R_MANAGER')) {
            $q->where('venue', $u->venue);
        } else {
            $q->where('created_by', $u->name);
        }

        return ok($q->orderBy('id')->get()->map(fn ($p) => $this->present($p)));
    }

    /** 单条是否可读：范围口径与 index 一致 */
    private function canRead(Request $r, TrainingPlan $row): bool
    {
        $u = $r->user();
        if (userHasRole($u, 'R_SUPER')) {
            return true;
        }
        if (userHasRole($u, 'R_MANAGER')) {
            return (string) $row->venue === (string) $u->venue;
        }

        return (string) $row->created_by === (string) $u->name;
    }

    /** POST /training-plans：新建一条（也可用于带客户端 id 的补传） */
    public function store(Request $r)
    {
        $plan = $r->input('plan');
        abort_unless(is_array($plan), 422, 'plan 必须是对象');

        $venue = userHasRole($r->user(), 'R_SUPER') ? '' : (string) $r->user()->venue;
        $result = DB::transaction(fn () => $this->upsertOne($plan, $this->owner($r), $venue, userHasRole($r->user(), 'R_MANAGER')));

        return ok($result);
    }

    /** PUT /training-plans/{id}：更新可见范围内的一条（创建人 / 本店店长 / 超管） */
    public function update(Request $r, int $id)
    {
        $row = TrainingPlan::find($id);
        abort_if(! $row || ! $this->canRead($r, $row), 404, '计划不存在或不在你的可见范围内');

        $plan = $r->input('plan');
        abort_unless(is_array($plan), 422, 'plan 必须是对象');

        $row->update($this->attributes($plan, $this->owner($r)));

        return ok(['id' => $row->id]);
    }

    /** DELETE /training-plans/{id}：删除范围同上 */
    public function destroy(Request $r, int $id)
    {
        $row = TrainingPlan::find($id);
        abort_if(! $row || ! $this->canRead($r, $row), 404, '计划不存在或不在你的可见范围内');
        $row->delete();

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

        $ids = DB::transaction(function () use ($plans, $name, $deletedIds, $r) {
            if ($deletedIds !== []) {
                TrainingPlan::where('created_by', $name)->whereIn('id', $deletedIds)->delete();
            }
            $ids = [];
            foreach ($plans as $p) {
                if (! is_array($p)) {
                    continue;
                }
                // 超管不锁定门店，取空；店长/老师用自己的门店
                $venue = userHasRole($r->user(), 'R_SUPER') ? '' : (string) $r->user()->venue;
                $ids[] = $this->upsertOne($p, $name, $venue, userHasRole($r->user(), 'R_MANAGER'));
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
            'venue' => (string) $p->venue,
        ]);
    }

    /**
     * 单条 upsert。
     *
     * - 命中本人已存在的 id → 原地更新（保留 source_review_id 等不由前端维护的字段）
     * - 否则新建；客户端 id 未被任何账号占用时沿用它，否则由数据库分配
     */
    private function upsertOne(array $plan, string $name, ?string $venue = null, bool $isManager = false): array
    {
        $clientId = (int) ($plan['id'] ?? 0);
        $existing = $clientId > 0 ? TrainingPlan::find($clientId) : null;

        // 命中别人的计划时（店长/超管可读），按范围放行，但**不夺走归属**：
        // created_by 保持原样，否则店长一保存就变成自己的计划了
        if ($existing && ((string) $existing->created_by === $name || $isManager)) {
            $attrs = $this->attributes($plan, $existing->created_by, $existing->venue ?: $venue);
            unset($attrs['created_by']);
            $existing->update($attrs);

            return ['clientId' => $clientId, 'serverId' => $existing->id];
        }

        $attrs = $this->attributes($plan, $name, $venue);
        if ($clientId > 0 && ! TrainingPlan::whereKey($clientId)->exists()) {
            $attrs['id'] = $clientId;
        }
        $row = TrainingPlan::create($attrs);

        return ['clientId' => $clientId, 'serverId' => $row->id];
    }

    private function attributes(array $p, string $name, ?string $venue = null): array
    {
        return [
            'member_name' => (string) ($p['memberName'] ?? '') ?: '未命名',
            'venue' => (string) ($venue ?? ''),
            'payload' => $p,
            // 独立列与 payload 同步维护：公开分享页 / 其它读取方按列取，
            // 只写 payload 会让这些列一直是空的
            'profile' => [
                'age' => $p['age'] ?? '', 'gender' => $p['gender'] ?? '', 'height' => $p['height'] ?? '',
                'weight' => $p['weight'] ?? '', 'bodyFat' => $p['bodyFat'] ?? '', 'focus' => $p['focus'] ?? '',
            ],
            'goal' => [
                'coreGoal' => $p['coreGoal'] ?? '', 'freq' => $p['freq'] ?? '',
                'stageWeeks' => $p['stageWeeks'] ?? '', 'stageGoal' => $p['stageGoal'] ?? '',
                'risks' => $p['risks'] ?? '',
            ],
            'content' => is_array($p['content'] ?? null) ? $p['content'] : null,
            'images' => is_array($p['images'] ?? null) ? $p['images'] : null,
            'status' => (string) ($p['status'] ?? '草稿'),
            'share' => is_array($p['share'] ?? null) ? $p['share'] : null,
            'source' => (string) ($p['source'] ?? ''),
            'created_by' => $name,
            'confirmed_at' => ($p['status'] ?? '') === '已确认' ? now() : null,
        ];
    }
}
