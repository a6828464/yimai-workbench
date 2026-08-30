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
            if ($user->role === 'R_MANAGER') {
                $request->merge(['venue' => $user->venue]);
            } elseif ($user->role === 'R_TEACHER') {
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
            if ($user->role === 'R_MANAGER') {
                $request->merge(['venue' => $user->venue]);
            } elseif ($user->role === 'R_TEACHER') {
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
        if ($user->role === 'R_SUPER') {
            return true;
        }
        if ($user->role === 'R_MEDIA') {
            return ($match['kind'] ?? '') === '留资';
        }

        $query = Customer::where('phone', trim((string) $request->query('phone')))
            ->where('name', $match['name'] ?? '')
            ->where('venue', $match['venue'] ?? '');
        if ($user->role === 'R_MANAGER') {
            $query->where('venue', $user->venue);
        } elseif ($user->role === 'R_TEACHER') {
            $query->where('venue', $user->venue)
                ->where(fn ($q) => $q->where('owner', $user->name)->orWhere('consultant', $user->name));
        }

        return $query->exists();
    }

    private function canAccessLead(User $user, Lead $lead): bool
    {
        return match ($user->role) {
            'R_SUPER', 'R_MEDIA' => true,
            'R_MANAGER' => $lead->venue === $user->venue,
            'R_TEACHER' => $lead->venue === $user->venue
                && in_array($lead->service_teacher, ['', $user->name], true),
            default => false,
        };
    }

    private function assertTeacherFields(Request $request, bool $creating, User $user): void
    {
        $allowed = $creating
            ? ['leadDate', 'name', 'phone', 'wechat', 'demand', 'source', 'orderPlatform', 'venue', 'serviceTeacher', 'status', 'remark']
            : ['demand', 'status', 'remark', 'serviceTeacher', 'trialTime', 'trialTopic', 'trialTeacher', 'trialCards'];
        abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 403, '老师无权修改该客资字段');
        if ($request->exists('serviceTeacher')) {
            abort_unless($request->input('serviceTeacher') === $user->name, 403, '老师只能将客资指派给自己');
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
            'dealAmount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'redeemAmount' => ['sometimes', 'nullable', 'integer', 'min:0'],
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
