<?php

namespace App\Http\Controllers;

use App\Models\PostClassReview;
use App\Models\PublishedShare;
use App\Models\TrainingPlan;
use Illuminate\Support\Facades\DB;

final class PublicShareController extends Controller
{
    /**
     * GET /public/post-class/{code}：学员版训练方向（课后分析对客页）。
     *
     * 红线记录一律不对外：命中红线的课后分析只用于内部沟通卡，
     * 不会生成对客训练方案，因此这里也直接 404。
     */
    public function postClass(string $code)
    {
        $row = PostClassReview::where('share->code', $code)->first();
        if (! $row
            || ($row->share['enabled'] ?? false) !== true
            || $row->status !== '已确认'
            || $row->red_flag) {
            return response()->json(['errno' => 404, 'emsg' => '分享不存在或已停用'], 404);
        }
        // 行锁内读改写，避免并发访问丢计数（views 存于 share JSON，跨库不便原子自增）
        DB::transaction(function () use ($row) {
            $locked = PostClassReview::whereKey($row->id)->lockForUpdate()->first();
            if ($locked) {
                $locked->share = array_merge($locked->share ?? [], ['views' => (int) ($locked->share['views'] ?? 0) + 1]);
                $locked->save();
            }
        });

        $payload = $row->payload ?? [];
        $generated = $payload['generated'] ?? [];

        return ok([
            'studentName' => $row->student_name,
            'studentType' => $row->student_type,
            'teacherName' => $row->teacher_name,
            'venue' => $row->venue,
            'classAt' => $row->class_at?->toDateString(),
            'courseName' => $row->course_name,
            'sceneLabel' => \App\Services\PostClassPlanEngine::SCENES[$row->scene] ?? '',
            'objective' => $payload['objective'] ?? ($generated['objective'] ?? null),
            'plan' => $payload['plan'] ?? ($generated['plan'] ?? null),
            'script' => $payload['script'] ?? ($generated['script'] ?? null),
            'confirmedAt' => $row->confirmed_at?->toDateString(),
        ]);
    }
    /** GET /public/training/{code} */
    public function training(string $code)
    {
        $plan = TrainingPlan::where('share->code', $code)->first();
        if (! $plan || ($plan->share['enabled'] ?? false) !== true || $plan->status !== '已确认') {
            return response()->json(['errno' => 404, 'emsg' => '分享不存在或已停用'], 404);
        }
        // 行锁内读改写，避免并发访问丢计数（views 存于 share JSON，跨库不便原子自增）
        DB::transaction(function () use ($plan) {
            $locked = TrainingPlan::whereKey($plan->id)->lockForUpdate()->first();
            if ($locked) {
                $locked->share = array_merge($locked->share ?? [], ['views' => (int) ($locked->share['views'] ?? 0) + 1]);
                $locked->save();
            }
        });

        return ok([
            'memberName' => $plan->member_name,
            'profile' => $plan->profile,
            'goal' => $plan->goal,
            'content' => $plan->content,
            'images' => $plan->images,
            'confirmedAt' => optional($plan->confirmed_at)->toDateString(),
        ]);
    }

    /** GET /public/sales/{token} */
    public function sales(string $token)
    {
        $share = PublishedShare::where('type', 'sales')->where('token', $token)->first();
        if (! $share) {
            return response()->json(['errno' => 404, 'emsg' => '分享不存在或已停用'], 404);
        }

        return ok($share->payload);
    }
}
