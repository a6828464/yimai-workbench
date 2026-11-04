<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? Auth::guard('sanctum')->user();
        if (! $user) {
            return $next($request);
        }
        $request->setUserResolver(fn () => $user);
        abort_if(($user->status ?? '启用') !== '启用', 403, '账号已停用');

        $this->authorizeLeadRequest($request, $user);

        $response = $next($request);

        if ($request->is('api/leads/check') && $response->isSuccessful()) {
            $payload = $response->getData(true);
            $payload['data']['matches'] = array_values(array_filter(
                $payload['data']['matches'] ?? [],
                fn (array $match) => $this->canSeePhoneMatch($request, $user, $match)
            ));
            $payload['data']['exists'] = $payload['data']['matches'] !== [];
            $response->setData($payload);
        }

        return $response;
    }

    /**
     * 这个账号是否属于**管理层**（含「管理层 + 老师」的多角色叠加）。
     *
     * 为什么需要在留资入口区分：管理层是分配方，录客资要保持「未分配 + 可用完整字段」
     * （超管可跨店、新媒体是双店账号）；老师侧则只能录到自己名下、字段也受限。
     * 此前按 `elseif` 逐个角色判断，`R_SUPER + R_TEACHER` 这种叠加会掉进老师分支 ——
     * 于是超管录客资时 venue 被账号的 null 覆盖，`venue required` 直接 422（既有缺陷）。
     * 判定与 `LeadController::store` 的「自动挂本人」口径同源：两者都用这三个角色划管理层。
     */
    private function isManagerSide(User $user): bool
    {
        return userHasAnyRole($user, ['R_SUPER', 'R_MANAGER', 'R_MEDIA']);
    }

    private function authorizeLeadRequest(Request $request, User $user): void
    {
        if ($request->is('api/leads') && $request->isMethod('post')) {
            Validator::make($request->all(), $this->leadRules(true))->validate();
            // 分支只按「身份类别」选一次：管理层（含叠加老师角色）走管理层口径，
            // 纯老师侧才锁字段、锁门店、锁初始状态。
            if (userHasRole($user, 'R_MANAGER')) {
                // 店长：本店。venue 为空时不写 null 覆盖（否则请求里的合法门店会被抹成 null → 422）
                if ((string) $user->venue !== '') {
                    $request->merge(['venue' => $user->venue]);
                }
            } elseif (! $this->isManagerSide($user) && userHasAnyRole($user, ['R_SERVICE', 'R_TEACHER'])) {
                $this->assertTeacherFields($request, true, $user);
                $request->merge(['status' => '新留资']);
                if ((string) $user->venue !== '') {
                    $request->merge(['venue' => $user->venue]);
                }
            }

            return;
        }

        if (! $request->is('api/leads/*')) {
            return;
        }
        $id = $request->route('id');
        if (! is_numeric($id)) {
            return;
        }
        $lead = Lead::findOrFail((int) $id);
        abort_unless($this->canAccessLead($user, $lead), 403, '无权访问该客资');
        if ($request->isMethod('patch')) {
            Validator::make($request->all(), $this->leadRules(false))->validate();
            if (userHasRole($user, 'R_MANAGER')) {
                if ((string) $user->venue !== '') {
                    $request->merge(['venue' => $user->venue]);
                }
            } elseif (! $this->isManagerSide($user)
                && userHasAnyRole($user, ['R_SERVICE', 'R_TEACHER'])) {
                $this->assertTeacherFields($request, false, $user);
            }
        }
    }

    private function canSeePhoneMatch(Request $request, User $user, array $match): bool
    {
        if (($match['kind'] ?? '') === '已有留资') {
            $lead = Lead::find($match['id'] ?? 0);

            return $lead && $this->canAccessLead($user, $lead);
        }
        if (userHasRole($user, 'R_SUPER')) {
            return true;
        }
        if (userHasRole($user, 'R_MEDIA')) {
            return ($match['kind'] ?? '') === '留资';
        }

        // 角色不明账号（roles 漏写、或 role 是已下架的旧角色码）直接拒绝。
        //
        // 这个判断**必须放在门店/scope 收窄之前**，因为它挡的是更低一层的问题：
        // /api/leads/check 无状态、手机号可枚举，每个命中都回姓名 + 门店 + 卡名，
        // 本质是一台客户名单 oracle。收口前，这类账号能按号码反查出**跨店他人会员**
        // 的完整档案（实测 detail="东部私教年卡48次"），而同一时刻 /api/customers 已是空集 ——
        // 同一个人的可见范围在两个出口上互相矛盾，矛盾的那一侧就是洞口。
        //
        // 与下面 teacherSide 分支的关系（历史陷阱，改动时务必确认）：
        // 收口前这里调 `scopeCustomersForUser($query, $user)` 却**丢弃返回值**，靠该函数
        // 原地修改 $query 的副作用生效；而 t8 之前 scopeCustomersForUser 的兜底是
        // `return $query;`（不产生任何副作用），所以这条路径当时等于什么都没做。
        // t8 把兜底换成 `whereRaw('1 = 0')` 后才「顺带」生效。也就是说：这条判断的松紧
        // 一直取决于另一个函数的返回值形态，很脆弱。现在角色不明在入口处就拒绝，
        // 不再依赖任何一个 scope 函数的副作用；下面 teacherSide 分支保持原样，
        // 只负责五种已知角色里「服务老师/授课老师」按人收窄。
        // 判据共用 isKnownRoleUser（helpers.php），角色全集只定义一次。
        if (! isKnownRoleUser($user)) {
            return false;
        }

        // 与 check() 同口径：按归一后的号码（以及原始输入形态）匹配，否则带分隔符的录入
        // 会因为「查得到但判定为无权限」而被过滤掉，前端看到的仍是无重复。
        $rawPhone = trim((string) $request->query('phone'));
        $query = Customer::whereIn('phone', array_values(array_unique(array_filter([normalizePhone($rawPhone), $rawPhone]))))
            ->where('name', $match['name'] ?? '')
            ->where('venue', $match['venue'] ?? '');
        if (userHasRole($user, 'R_MANAGER')) {
            $query->where('venue', $user->venue);
        } elseif (userIsTeacherSide($user)) {
            // 这里**赋值**给 $query（原先丢弃返回值）：函数原地改查询、返回值也是同一个实例，
            // 赋值即同时保留「原地生效」与「返回值可见」两种语义，不再依赖调用方的疏忽。
            $query = scopeCustomersForUser($query, $user);
        }

        return $query->exists();
    }

    /**
     * 单条客资的读写准入。
     *
     * 判据已收口到 `userCanAccessLead()`（helpers.php）：中间件、今日待办的标记处理、
     * 以及任何新的单条入口都调用同一份，避免出现「列表里看不到、直达 URL 能改」。
     * 这里保留方法名只是因为 `authorizeLeadRequest()` / `canSeePhoneMatch()` 调用它。
     */
    private function canAccessLead(User $user, Lead $lead): bool
    {
        return userCanAccessLead($user, $lead);
    }

    /**
     * 服务老师与授课老师的可写字段一致。
     *
     * 两个角色拆分的是「可见范围」，不是编辑字段：授课老师也可以兼会籍顾问
     * （可见范围里的「归属于自己的会籍顾问的会员」），所以 serviceTeacher 需要放开给自己。
     * 保留的两条业务控制不变：不能指派给别人、不能自己标记成交。
     */
    private const TEACHER_FIELDS_CREATE = ['leadDate', 'name', 'phone', 'wechat', 'demand', 'source', 'orderPlatform', 'venue', 'serviceTeacher', 'status', 'remark'];

    private const TEACHER_FIELDS_UPDATE = ['demand', 'status', 'remark', 'serviceTeacher', 'trialTime', 'trialTopic', 'trialTeacher', 'trialCards'];

    private function assertTeacherFields(Request $request, bool $creating, User $user): void
    {
        $allowed = $creating ? self::TEACHER_FIELDS_CREATE : self::TEACHER_FIELDS_UPDATE;
        abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 403, '老师无权修改该客资字段');
        if ($request->exists('serviceTeacher')) {
            abort_unless(
                staffUserId((string) $request->input('serviceTeacher')) === (int) $user->id
                || in_array((string) $request->input('serviceTeacher'), staffNames($user), true),
                403,
                '老师只能将客资指派给自己'
            );
        }
        if ($request->exists('status')) {
            abort_if($request->input('status') === '已成交', 403, '老师不能直接标记成交，请由店长完成');
        }
    }

    private function leadRules(bool $creating): array
    {
        return [
            'leadDate' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'date'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'wechat' => ['sometimes', 'nullable', 'string', 'max:100'],
            'demand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'orderPlatform' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['绿地店', '东部店'])],
            'serviceTeacher' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['新留资', '已联系', '已约体验', '已体验', '已成交', '已流失', '爽约'])],
            'grade' => ['sometimes', 'nullable', 'string', 'max:4'],
            'trialTime' => ['sometimes', 'nullable', 'date'],
            'trialTopic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trialTeacher' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dealCard' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dealAmount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'redeemAmount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'voucherCode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'couponName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'couponTotal' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'couponRemaining' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'trialCards' => ['sometimes', 'nullable', 'array', 'max:50'],
            'trialCards.*' => ['array'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
