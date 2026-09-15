<?php

namespace App\Http\Controllers;

use App\Models\PostClassReview;
use App\Services\BodyTestReportService;
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
            // 体测解读：只给客观数据（指标/实测值/标准区间）。
            // 设备原文含「矫正」等表达禁忌词，还有接近诊断的表述，一律不对客。
            'bodyTest' => BodyTestReportService::toCustomerView($payload['bodyTest'] ?? null),
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

        // 分享页期望的是与前端 TrainingPlan 同形状的对象（它要读 status / share 判断有效性，
        // 还要读 content.phases、coreGoal、stageWeeks 等）。payload 里存的就是这份完整对象，
        // 因此以 payload 为基底，再用数据库的权威字段覆盖：
        //  - 之前只返回 profile/goal/content 几个独立列，既缺 status/share 导致页面永远判为无效，
        //    又因为改造后这些列不再写入而为 null，页面即使打开也是空白。
        $payload = is_array($plan->payload) ? $plan->payload : [];

        return ok(array_merge($payload, [
            'id' => $plan->id,
            'memberName' => $plan->member_name,
            'status' => $plan->status,
            'share' => $plan->share,
            'createdBy' => $plan->created_by,
            'confirmedAt' => optional($plan->confirmed_at)->toDateTimeString(),
        ]));
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
