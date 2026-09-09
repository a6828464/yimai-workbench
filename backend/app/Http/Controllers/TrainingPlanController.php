<?php

namespace App\Http\Controllers;

use App\Models\TrainingPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TrainingPlanController extends Controller
{
    /** GET /training-plans */
    public function index(Request $r)
    {
        return ok(TrainingPlan::where('created_by', $r->user()->name)->orderBy('id')->get()
            ->map(fn ($p) => array_merge(['id' => $p->id], $p->payload ?? [])));
    }

    /** PUT /training-plans/bulk */
    public function bulkSave(Request $r)
    {
        $plans = $r->input('plans');
        abort_unless(is_array($plans), 422, 'plans 必须是数组');

        // 整表替换（按人隔离）：id 由前端维护，服务端原样持久化
        DB::transaction(function () use ($plans, $r) {
            TrainingPlan::where('created_by', $r->user()->name)->delete();
            foreach ($plans as $p) {
                if (! is_array($p)) {
                    continue;
                }
                TrainingPlan::create([
                    'id' => (int) ($p['id'] ?? 0),
                    'member_name' => (string) ($p['memberName'] ?? '') ?: '未命名',
                    'payload' => $p,
                    'status' => (string) ($p['status'] ?? '草稿'),
                    'share' => is_array($p['share'] ?? null) ? $p['share'] : null,
                    'source' => (string) ($p['source'] ?? ''),
                    'created_by' => $r->user()->name,
                    'confirmed_at' => ($p['status'] ?? '') === '已确认' ? now() : null,
                ]);
            }
        });

        return ok(['saved' => count($plans)]);
    }
}
