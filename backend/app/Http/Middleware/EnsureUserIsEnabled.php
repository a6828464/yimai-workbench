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
    private const TEACHER_FIELDS_CREATE = [
        'leadDate', 'name', 'phone', 'wechat', 'demand', 'source', 'orderPlatform', 'venue',
        'serviceTeacher', 'status', 'remark',
        /*
         * ── v3.21.0（t19）：这两项是白名单**漏了**，不是业务不允许 ─────────────
         * 老师接电话约体验课时必须能录「约了哪天几点、什么课型」：
         *  - `trialCards`：体验课卡片（多节，含时间/主题/老师/券）；
         *  - `grade`：课型/体验课类型。
         * 两个字段 `LeadController::store` 的 `$leadFields` 本来就支持落库（列也在表里），
         * 所以这里补白名单即可，不动写入侧。
         */
        'trialCards', 'grade',
    ];

    private const TEACHER_FIELDS_UPDATE = ['demand', 'status', 'remark', 'serviceTeacher', 'trialTime', 'trialTopic', 'trialTeacher', 'trialCards'];

    private function assertTeacherFields(Request $request, bool $creating, User $user): void
    {
        $allowed = $creating ? self::TEACHER_FIELDS_CREATE : self::TEACHER_FIELDS_UPDATE;

        /*
         * ════════════════════════════════════════════════════════════════
         * v3.21.0（t19）：白名单外字段从「整单 403」改成「剥离 + 审计」
         * ════════════════════════════════════════════════════════════════
         *
         * ## 事故现场（2026-11 老师真机反馈「没有新建客资的权限」）
         *
         * 旧实现是这一行：
         *
         *     abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 403, '老师无权修改该客资字段');
         *
         * 它把**请求体的全部键**跟白名单比对，多一个键就整单 403。而小程序
         * `leads/form.vue` 的 `buildCreatePayload()` 必然带 7 个白名单外的键：
         * `grade / dealCard / dealAmount / couponName / voucherCode / redeemAmount / trialCards`
         * （`trialCards` 即使只是空数组也会出现在 JSON 里）。于是**每个老师 100% 建不了客资**，
         * 且报错文案是「老师无权修改该客资字段」—— 员工只会理解成「我没权限」，
         * 根本查不到真正原因。管理层走 `isManagerSide` 分支跳过这段检查，所以只有老师中招。
         *
         * ## 为什么改成剥离而不是继续拒绝
         *
         * 这个契约太脆：前端**多传一个与该权限无关的键**，整单就被拒。表单字段会不断增删，
         * 靠「两边键集合严格相等」来守越权，等于把一次表单改版升级成一次全量功能不可用。
         * 越权真正要防的是「字段被写入」，而不是「字段出现过」—— 所以：
         * 白名单外的键**从请求里摘掉**，后续校验与落库都看不到它，等同于「没填」。
         *
         * 剥离动作**必须留痕**（见 auditStrippedFields）：静默丢弃会制造新的
         * 「我明明填了，怎么没保存」—— 那正是这次事故的另一半。
         */
        $input = $request->all();
        $stripped = array_values(array_diff(array_keys($input), $allowed));
        if ($stripped !== []) {
            // 用 replace() 而不是 `$request->request->remove()`：JSON 请求的请求体在
            // `json()` 那个 bag 里（`request` bag 是空的），只摘 request bag 会**摘不掉**，
            // 于是「说了剥离、实际照样被控制器读到」。replace() 走 getInputSource()，
            // form 与 JSON 两种形态都正确。
            $request->replace(array_intersect_key($input, array_flip($allowed)));
            $this->auditStrippedFields($request, $user, $stripped, $creating);
        }

        // 下面两条是**真正的越权防线**，与上面的剥离无关，保持硬拒绝：
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

    /**
     * 被剥离字段的留痕（v3.21.0 / t19）。
     *
     * 为什么必须写：剥离本身是「静默丢弃」的近义词 —— 如果连一条日志都不留，
     * 下一位老师填了管理字段却看到没保存时，仍然只能靠猜（这次事故的一半正是这个）。
     * 落 `module = 留资管理`、`action = 字段剥离`，detail 里列出**字段名**（不含值：
     * 值是客户信息，日志不该成为新的 PII 面），谁在什么时候尝试提交了哪些管理字段一目了然。
     *
     * 只记字段名不记值，也不改请求的其他部分 —— 这条日志是「可追溯」，不是「可复现」。
     *
     * @param  array<int,string>  $stripped  被摘掉的键名（已按请求顺序）
     */
    private function auditStrippedFields(Request $request, User $user, array $stripped, bool $creating): void
    {
        $name = trim((string) $request->input('name', ''));
        $venue = (string) $request->input('venue', '');
        if ($venue === '') {
            $venue = (string) ($user->venue ?? '');
        }

        audit(
            $request,
            '字段剥离',
            '留资管理',
            $creating ? '0' : (string) ($request->route('id') ?? '0'),
            ($creating ? '新建客资' : '编辑客资').($name !== '' ? '：'.$name : ''),
            $venue !== '' ? $venue : '双店',
            '老师提交了白名单外字段，已剥离（未写入）：'.implode('、', $stripped)
        );
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
