<?php

namespace App\Http\Controllers;

use App\Models\PostClassReview;
use App\Services\BodyTestReportService;
use App\Models\PublishedShare;
use App\Models\TrainingPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * GET /public/sales/{token}
     *
     * 销售分享（谈单工具对客 H5）。判定全部 fail-closed：
     *  1. 记录存在且**来源可信** —— `token_source='server'`，即由本服务端签发并标记。
     *     只靠「形如 16 位 hex」不够：形状可伪造，任何 16hex 都满足，它只说明
     *     「看起来像」而非来源（见 ShareController::looksLikeServerToken）；
     *  2. `enabled` 为**真值** —— 显式比较而非 `(bool)` 转换：DB 里可能是
     *     '0'/'false'/'off'（驱动差异、手工改库、历史脏数据），而 PHP 中
     *     `(bool) 'false' === true`，会把「已停用」判成启用继续下发；
     *     列不存在时（迁移窗口）按启用处理，等价修复前语义，避免既有链接全 404；
     *  3. 下发字段走**白名单**：顶层仅 share/info/products/coaches/cases，
     *     案例仅 id/coachId/goal/desc/stages/authorized，且只含已授权条目。
     *     未授权案例原文（含健康/生育类描述）不能随公开接口出网 —— 不依赖
     *     前端对客页的过滤，也不依赖入库时是否已清理干净。
     */
    public function sales(string $token)
    {
        $share = PublishedShare::where('type', 'sales')->where('token', $token)->first();
        if (! $share
            || ! self::trustedSource($share)
            || ! self::enabled($share)) {
            return response()->json(['errno' => 404, 'emsg' => '分享不存在或已停用'], 404);
        }

        $payload = is_array($share->payload) ? $share->payload : [];

        // 字段白名单：即使历史快照里夹带了别的字段（内部备注、会员 id、体测原文），
        // 也不出网。对客页只渲染这些字段，多出来的没有存在理由。
        $out = [];
        foreach (self::SALES_TOP_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $out[$field] = $payload[$field];
            }
        }

        // 服务端过滤是权威：payload 里即使混入未授权案例（历史快照、绕过前端提交的请求），
        // 也不会出现在响应中；案例内部同样只保留白名单字段。
        $out['cases'] = array_values(array_filter(array_map(
            static function ($case) {
                if (! is_array($case)) {
                    return null;
                }
                if (filter_var($case['authorized'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
                    return null;
                }
                $kept = [];
                foreach (self::CASE_FIELDS as $f) {
                    if (array_key_exists($f, $case)) {
                        $kept[$f] = $case[$f];
                    }
                }
                $kept['authorized'] = true;

                return $kept;
            },
            is_array($payload['cases'] ?? null) ? $payload['cases'] : []
        )));

        // 分享页按 route.params.code === data.share.code 判定链接有效性，
        // 这里以权威 token 覆盖，避免历史快照里的旧码让页面判为「已失效」。
        $out['share'] = [
            'enabled' => true,
            'code' => $share->token,
            'views' => (int) ($payload['share']['views'] ?? 0),
        ];

        return ok($out);
    }

    /** 顶层允许对外的字段（与 ShareController 同口径；对客页只渲染这些） */
    private const SALES_TOP_FIELDS = ['share', 'info', 'products', 'coaches', 'cases'];

    /** 案例允许对外的字段（不含任何内部备注/会员标识） */
    private const CASE_FIELDS = ['id', 'coachId', 'goal', 'desc', 'stages', 'authorized'];

    /**
     * 分享码来源是否可信：`token_source='server'`（服务端签发时写入）。
     *
     * 列不存在时（迁移窗口）保守判为不可信 —— 宁可让本人重新发布换发一次码，
     * 也不把来源不明的码当成权威链接下发。
     */
    private static function trustedSource(PublishedShare $share): bool
    {
        if (! Schema::hasTable('published_shares')
            || ! Schema::hasColumn('published_shares', 'token_source')) {
            return false;
        }

        return (string) ($share->token_source ?? '') === 'server';
    }

    /**
     * 该行是否启用 —— 显式真值判定，一切非真值 fail-closed。
     *
     * `(bool) 'false'` 在 PHP 里是 true，所以「已停用」的记录若以字符串形态存储
     * （驱动差异、手工改库、脏数据）会被判成启用并继续对外下发。
     * filter_var 对 'false'/'off'/'no'/'0'/'' 返回 false，正是要的语义。
     * 列不存在时（迁移窗口）按 true 处理：等价修复前语义，避免既有链接全 404。
     */
    private static function enabled(PublishedShare $share): bool
    {
        if (! Schema::hasTable('published_shares')
            || ! Schema::hasColumn('published_shares', 'enabled')) {
            return true;
        }

        return filter_var($share->enabled, FILTER_VALIDATE_BOOLEAN) === true;
    }
}
