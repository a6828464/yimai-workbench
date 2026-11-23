<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PostClassReview;
use App\Models\TrainingPlan;
use Illuminate\Http\Request;

/**
 * 「客户分析 / 训练计划」单份内容的对外分享 token（v3.20.0）。
 *
 * ## 为什么用 token，而不是只靠手机号
 *
 * 馆主的担心是「客户点到别人的内容」。曾经的做法是：对客 H5 只凭一个短分享码，
 * 谁知道码就能看；若改成「按手机号查」，则**同一手机号名下的所有内容会一起露出**，
 * 而且手机号可以被撞中（11 位里前 7 位是号段，实际有效熵很低）。
 *
 * 所以这里用两道独立的锁：
 *  1. **不可枚举的随机 token**（`random_bytes(32)` = 256 bit，hex 64 位），
 *     与「kind + 内容 id」绑死 —— 它指向**唯一一份**内容，天然不可能串到别人；
 *     分享卡片的 path 带上这个 token，客户点开就固定到那一份。
 *  2. **手机号后四位**（`?code=`）作为第二道校验：链接被转发到群里时，
 *     不知道机主手机号的人打不开。后四位只是「你是不是机主」的弱证据，
 *     真正的安全边界是第 1 条 —— 两层同时成立才算通过。
 *
 * 为什么不用「手机号全号 + 姓名」当唯一凭据：那等于把 PII 变成口令，
 * 一旦泄漏无法撤回；token 可以随时换发/停用（`share.tokenEnabled`）。
 *
 * ## 落在哪张表
 *
 * **不新建表、不加列**：token 存进内容行自带的 `share` JSON 列（`PostClassReview` /
 * `TrainingPlan` 都已 `cast: array`，且 `share` 里本来就有旧分享码 `code`）。
 * 理由：
 *  - `published_shares.token` 列宽 40，装不下 64 位 hex（要改列宽就得动迁移，
 *    而迁移会牵动线上存量行）；
 *  - 内容只存在它自己的表里，`published_shares` 的 payload 是「快照拷贝」模型，
 *    这里不需要拷贝内容，只需要一个指针 + 一道访问规则。
 * 因为 token 只写在某一类内容行上，「拿 review 的 token 查 plan」在查询层面就不存在。
 *
 * ## 白名单
 *
 * 公开接口只回展示必需字段：**不含手机号全号、不含归属人账号/id、不含内部备注、不含金额**。
 * 投影逻辑写在本类的 `reviewPublicView()` / `planPublicView()` 里（逐字段挑，不用黑名单），
 * 新增字段必须显式加进去 —— 默认不下发。
 */
final class ShareTokenController extends Controller
{
    /** 内容类型 → 审计模块名 */
    private const KIND_MODULE = ['review' => '课后分析', 'plan' => '训练计划'];

    /** 公开分享的失败响应口径（与既有 /public/* 一致：errno + emsg） */
    private const ERR_GONE = ['errno' => 410, 'emsg' => '分享已失效'];
    private const ERR_NEED_CODE = ['errno' => 401, 'emsg' => '请输入手机号后四位'];
    private const ERR_BAD_CODE = ['errno' => 403, 'emsg' => '手机号不匹配'];

    /**
     * POST /share/{kind}/{id}/token
     *
     * 生成（或换发）单份内容的分享 token。权限校验**复用详情接口的判定**：
     * review 走 `PostClassReviewController::canView()`（与 show 同一个 isVisible），
     * plan 走 `TrainingPlanController::canView()`（与 index/canRead 同口径）。
     * 不在本类重写一份可见性规则 —— 两份必然分叉，而分叉的形态是「列表看不到、却能把链接发出去」。
     */
    public function issue(Request $r, string $kind, int $id)
    {
        $u = $r->user();
        $d = $r->validate([
            // 可选的校验码：缺省从内容里的手机号取后四位；传了就以此为准（4 位数字）
            'code' => 'nullable|string|max:8',
        ]);

        if ($kind === 'review') {
            $row = PostClassReview::findOrFail($id);
            abort_unless(app(PostClassReviewController::class)->canView($u, $row), 403, '无权分享该课后分析');
            // 命中红线的记录只做内部沟通卡，从不产出对客方案（与 GET /public/post-class 同一条红线）：
            // 这类内容一旦有对外链接，就等于把「建议进一步专业评估」之前的判断发给客户
            abort_if((bool) $row->red_flag, 422, '命中红线的课后分析不对外分享');
            // 未确认的草稿同样不发：老师可能还在改，发出去的是半成品
            abort_if((string) $row->status !== '已确认', 422, '请先确认课后分析再分享');
            $derivedTail = $this->tailOf((string) $row->student_phone);
        } else {
            $row = TrainingPlan::findOrFail($id);
            abort_unless(app(TrainingPlanController::class)->canView($r, $row), 403, '无权分享该训练计划');
            $derivedTail = $this->tailOfPlan($row);
        }

        $tail = trim((string) ($d['code'] ?? ''));
        if ($tail === '') {
            $tail = $derivedTail;
        } else {
            $tail = preg_replace('/\D+/', '', $tail) ?? '';
            abort_if(strlen($tail) !== 4, 422, '校验码必须是手机号后四位');
        }

        // 32 字节随机 = 256 bit；hex 64 位。旧分享码（8 位 hex）与它不冲突，两者并存
        $token = bin2hex(random_bytes(32));

        $share = is_array($row->share) ? $row->share : [];
        $share['token'] = $token;
        // 期望的「手机号后四位」在**签发时**就固化下来：内容里的手机号后续被改动
        // （客户换号、老师改错）不该让已发出的链接忽然失效或忽然放行
        $share['tokenTail'] = $tail;
        $share['tokenEnabled'] = true;
        $share['tokenBy'] = (string) $u->name;
        $share['tokenById'] = (int) $u->id;
        $share['tokenAt'] = now()->toIso8601String();
        $row->update(['share' => $share]);

        $module = self::KIND_MODULE[$kind];
        audit(
            $r,
            '生成分享',
            $module,
            $row->id,
            $module.'#'.$row->id,
            (string) $row->venue,
            '生成对外分享 token（'.($tail === '' ? '未设校验码' : '校验手机号后四位').'）'
        );

        return ok([
            'kind' => $kind,
            'id' => (int) $row->id,
            'token' => $token,
            // 前端据此告诉员工「链接需要客户输入手机号后四位」
            'needCode' => $tail !== '',
        ]);
    }

    /**
     * GET /public/share/{token}?code=后四位（免登录）。
     *
     * 失败口径严格区分，方便对客页给出不同文案：
     *  - token 不存在 / 已停用 → **410 分享已失效**（不再区分「没这条」与「已停用」，
     *    否则就成了「这个 token 曾经存在」的存在性探测）；
     *  - 需要校验但没给 code → **401 请输入手机号后四位**；
     *  - code 与固化下来的后四位不一致 → **403 手机号不匹配**。
     */
    public function show(Request $r, string $token)
    {
        // 形态先挡一层：只有 32 字节 hex 才可能是本接口签发的码，
        // 免得每次请求都去两个表的 JSON 列里扫任意垃圾串
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return response()->json(self::ERR_GONE, 410);
        }

        // 先 review 再 plan：token 只写在签发时那一类内容行上，因此天然不可跨用。
        // （有人拿 review 的 token 来查 plan，两表都查不到 → 410，而不是「串到别人」）
        $review = PostClassReview::where('share->token', $token)->first();
        $plan = $review === null ? TrainingPlan::where('share->token', $token)->first() : null;
        if ($review === null && $plan === null) {
            return response()->json(self::ERR_GONE, 410);
        }

        $row = $review ?? $plan;
        $kind = $review !== null ? 'review' : 'plan';
        $share = is_array($row->share) ? $row->share : [];

        // 停用判定用显式真值解析：DB/JSON 里可能是 'false'/'0'/'off'，
        // 而 PHP 的 `(bool) 'false' === true` 会把「已停用」当成启用（与 published_shares 同一个坑）
        if (filter_var($share['tokenEnabled'] ?? true, FILTER_VALIDATE_BOOLEAN) !== true) {
            return response()->json(self::ERR_GONE, 410);
        }

        $tail = (string) ($share['tokenTail'] ?? '');
        if ($tail !== '') {
            $code = trim((string) $r->query('code', ''));
            if ($code === '') {
                return response()->json(self::ERR_NEED_CODE, 401);
            }
            // 定长比较用 hash_equals：避免逐字符早退带来的时间侧信道（这里是弱凭据，
            // 但成本为零，没必要留一个可以逐位试的通道）
            if (! hash_equals($tail, $code)) {
                return response()->json(self::ERR_BAD_CODE, 403);
            }
        }

        $this->recordView($r, $kind, $row, $tail !== '');

        return ok($kind === 'review'
            ? $this->reviewPublicView($review)
            : $this->planPublicView($plan));
    }

    /**
     * 访问审计：谁在什么时候看过（公开接口没有登录态，只能记「访客 + IP + UA」）。
     *
     * 为什么必须记：馆主担心「客户点到别人的内容」，出问题时需要有据可查 ——
     * 至少能回答「这份内容被访问过几次、从哪个 IP、什么时候」。写进既有的
     * `audit_logs`（不新增表），用 `module = 公开分享` 与内部留痕区分开。
     * 记的东西刻意不含 code 与手机号：审计日志本身不该成为 PII 泄漏面。
     */
    private function recordView(Request $r, string $kind, object $row, bool $checked): void
    {
        $module = self::KIND_MODULE[$kind];
        AuditLog::create([
            'operator_id' => null,
            'operator_name' => '访客',
            'operator_role' => 'guest',
            'action' => '公开访问',
            'module' => '公开分享',
            'target_id' => (string) $row->id,
            'target_label' => $module.'#'.$row->id,
            'venue' => (string) ($row->venue ?? ''),
            'detail' => $module.' 分享页被访问（'.($checked ? '手机号后四位校验通过' : '未设置校验码').'）',
            'ip' => (string) $r->ip(),
            'user_agent' => mb_substr((string) $r->header('User-Agent'), 0, 300),
        ]);
    }

    /**
     * 客户分析对客白名单：结论 / 维度评分 / 建议 / 教练名。
     *
     * 逐字段挑（白名单），不是从 payload 里 `unset` 几个危险键（黑名单必然漏）。
     * 不含：手机号、学员姓名之外的任何人、归属账号/id、内部备注、金额、红线细节。
     */
    private function reviewPublicView(PostClassReview $row): array
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $generated = is_array($payload['generated'] ?? null) ? $payload['generated'] : [];
        $objective = $payload['objective'] ?? ($generated['objective'] ?? []);
        if (! is_array($objective)) {
            $objective = [];
        }

        // 维度评分：payload 里没有数值分，老师的「观察项 + 档位」就是维度评级
        $dimensions = [];
        foreach ((array) ($payload['observations'] ?? []) as $obs) {
            if (! is_array($obs)) {
                continue;
            }
            $dimensions[] = [
                'key' => (string) ($obs['key'] ?? ''),
                'label' => (string) ($obs['label'] ?? ($obs['name'] ?? '')),
                'level' => (string) ($obs['level'] ?? ''),
            ];
        }

        // 建议：老师给的分阶段方案（名称/时长/目标/重点）
        $suggestions = [];
        foreach ((array) ($objective['plan'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }
            $suggestions[] = [
                'name' => (string) ($phase['name'] ?? ''),
                'duration' => (string) ($phase['duration'] ?? ''),
                'goal' => (string) ($phase['goal'] ?? ''),
                'focus' => (string) ($phase['focus'] ?? ''),
            ];
        }

        return [
            'kind' => 'review',
            'teacherName' => (string) $row->teacher_name,
            'conclusion' => (string) ($objective['observed'] ?? ''),
            'dimensions' => $dimensions,
            'suggestions' => $suggestions,
            'nextStep' => (string) ($objective['nextStep'] ?? ''),
            'confirmedAt' => optional($row->confirmed_at)->toDateString(),
        ];
    }

    /**
     * 训练计划对客白名单：阶段 / 动作 / 组次 / 注意事项 / 教练名。
     *
     * 不含：会员姓名与手机号、归属账号/id、内部备注（payload.remark）、金额。
     * `created_by` 只作**教练姓名**下发（这是馆主要求的「教练名」），不带 id。
     */
    private function planPublicView(TrainingPlan $plan): array
    {
        $payload = is_array($plan->payload) ? $plan->payload : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];

        $phases = [];
        foreach ((array) ($content['phases'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }
            $actions = [];
            foreach ((array) ($phase['actions'] ?? []) as $action) {
                if (! is_array($action)) {
                    continue;
                }
                $actions[] = [
                    'name' => (string) ($action['name'] ?? ''),
                    'sets' => (string) ($action['sets'] ?? ''),
                    'reps' => (string) ($action['reps'] ?? ''),
                    'rest' => (string) ($action['rest'] ?? ''),
                    'note' => (string) ($action['note'] ?? ''),
                ];
            }
            $phases[] = [
                'name' => (string) ($phase['name'] ?? ''),
                'goal' => (string) ($phase['goal'] ?? ''),
                'weeks' => (string) ($phase['weeks'] ?? ''),
                'actions' => $actions,
            ];
        }

        $cautions = [];
        foreach ((array) ($content['cautions'] ?? []) as $caution) {
            if (is_string($caution) && trim($caution) !== '') {
                $cautions[] = $caution;
            }
        }

        return [
            'kind' => 'plan',
            'teacherName' => (string) $plan->created_by,
            'coreGoal' => (string) ($payload['coreGoal'] ?? ''),
            'summary' => (string) ($content['summary'] ?? ''),
            'phases' => $phases,
            'cautions' => $cautions,
            'confirmedAt' => optional($plan->confirmed_at)->toDateTimeString(),
        ];
    }

    /** 手机号后四位（不足 4 位视为「没有可用校验码」→ 空串，表示不需要校验） */
    private function tailOf(?string $phone): string
    {
        $digits = normalizePhone($phone);
        if (strlen($digits) < 4) {
            return '';
        }

        return substr($digits, -4);
    }

    /**
     * 训练计划没有手机号列（`training_plans` 只有 member_name）：
     * 优先取 payload 里的 memberPhone，其次沿 `source_review_id` 回到那次课后分析的
     * 学员手机号 —— 计划多由课后分析流转而来，这条链能覆盖绝大多数真实数据。
     * 都没有就返回空串（不设校验码），此时安全性完全依赖 256 bit token 本身。
     */
    private function tailOfPlan(TrainingPlan $plan): string
    {
        $payload = is_array($plan->payload) ? $plan->payload : [];
        $direct = $this->tailOf((string) ($payload['memberPhone'] ?? ($payload['phone'] ?? '')));
        if ($direct !== '') {
            return $direct;
        }
        if ((int) $plan->source_review_id > 0) {
            $phone = PostClassReview::whereKey((int) $plan->source_review_id)->value('student_phone');

            return $this->tailOf((string) $phone);
        }

        return '';
    }
}
