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

    private function authorizeLeadRequest(Request $request, User $user): void
    {
        if ($request->is('api/leads') && $request->isMethod('post')) {
            Validator::make($request->all(), $this->leadRules(true))->validate();
            if (userHasRole($user, 'R_MANAGER')) {
                $request->merge(['venue' => $user->venue]);
            } elseif (userHasRole($user, 'R_SERVICE')) {
                $this->assertTeacherFields($request, true, $user);
                $request->merge(['venue' => $user->venue, 'status' => '新留资']);
            } elseif (userHasRole($user, 'R_TEACHER')) {
                $this->assertTeacherFields($request, true, $user);
                $request->merge(['venue' => $user->venue, 'status' => '新留资']);
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
                $request->merge(['venue' => $user->venue]);
            } elseif (userHasRole($user, 'R_SERVICE') || userHasRole($user, 'R_TEACHER')) {
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

        $query = Customer::where('phone', trim((string) $request->query('phone')))
            ->where('name', $match['name'] ?? '')
            ->where('venue', $match['venue'] ?? '');
        if (userHasRole($user, 'R_MANAGER')) {
            $query->where('venue', $user->venue);
        } elseif (userIsTeacherSide($user)) {
            scopeCustomersForUser($query, $user);
        }

        return $query->exists();
    }

    private function canAccessLead(User $user, Lead $lead): bool
    {
        if ($lead->venue !== $user->venue && ! userHasAnyRole($user, ['R_SUPER', 'R_MEDIA'])) {
            return false;
        }

        // 多角色取并集：任一角色能给到的可见性即成立
        if (userHasAnyRole($user, ['R_SUPER', 'R_MEDIA'])) {
            return true;
        }
        if (userHasRole($user, 'R_MANAGER') && $lead->venue === $user->venue) {
            return true;
        }
        // 服务老师（会籍顾问）：本人名下的客资 + 待承接池
        if (userHasRole($user, 'R_SERVICE') && in_array($lead->service_teacher, ['', $user->name], true)) {
            return true;
        }
        // 授课老师：本人作为会籍顾问的 + 本人上过体验课的 + 本人私教学员对应的 + 本人录入的
        if (userHasRole($user, 'R_TEACHER')) {
            if (in_array($user->name, [(string) $lead->service_teacher, (string) $lead->trial_teacher], true)) {
                return true;
            }
            if (in_array((string) $lead->created_by, staffNames($user), true)) {
                return true;
            }
            $phone = (string) $lead->phone;

            return $phone !== '' && in_array($phone, privateStudentKeys($user)['phones'], true);
        }

        return false;
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
            abort_unless(in_array((string) $request->input('serviceTeacher'), staffNames($user), true), 403, '老师只能将客资指派给自己');
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
