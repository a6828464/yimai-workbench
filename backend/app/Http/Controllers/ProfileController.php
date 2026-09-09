<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class ProfileController extends Controller
{
    /** GET /my/profile */
    public function myProfile(Request $r)
    {
        $u = $r->user();

        return ok([
            'name' => $u->name,
            'nickname' => $u->nickname,
            'phone' => $u->phone,
            'avatar' => $u->avatar,
            'email' => $u->email,
            'role' => $u->role,
            'venues' => $u->venues ?? [],
            'profile' => (array) ($u->profile ?? []),
        ]);
    }

    /** PUT /my/profile */
    public function updateMyProfile(Request $r)
    {
        $d = $r->validate([
            'nickname' => 'nullable|string|max:30',
            'email' => 'sometimes|required|email|max:255|unique:users,email,'.$r->user()->id,
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9][0-9\s-]{5,19}$/'],
            // 头像为前端 canvas 压缩后的 data URI（96~128px jpeg，通常 <30KB）
            'avatar' => 'nullable|string',
            'profile' => 'nullable|array',
            'profile.gender' => 'nullable|string|in:男,女',
            'profile.age' => 'nullable|string|max:3',
            'profile.years' => 'nullable|string|max:10',
            'profile.specialties' => 'nullable|array|max:20',
            'profile.specialties.*' => 'string|max:30',
            'profile.persona' => 'nullable|array',
            'profile.persona.role' => 'nullable|string|max:30',
            'profile.persona.audiences' => 'nullable|array|max:20',
            'profile.persona.audiences.*' => 'string|max:60',
            'profile.xhs' => 'nullable|array',
            'profile.xhs.ipType' => 'nullable|string|in:个人IP,门店IP',
            'profile.xhs.accountName' => 'nullable|string|max:60',
            'profile.xhs.role' => 'nullable|string|max:30',
            'profile.xhs.audiences' => 'nullable|array|max:20',
            'profile.xhs.audiences.*' => 'string|max:60',
            'profile.xhs.style' => 'nullable|string|max:30',
            'profile.xhs.conversion' => 'nullable|string|max:30',
            'profile.xhs.localFocus' => 'nullable|boolean',
        ]);
        abort_if(filled($d['avatar'] ?? null) && strlen((string) $d['avatar']) > 300000, 422, '头像图片过大，请重新选择或压缩');
        if (filled($d['avatar'] ?? null)) {
            abort_unless(preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $d['avatar'], $match), 422, '头像格式无效');
            abort_if(base64_decode($match[2], true) === false, 422, '头像内容无效');
        }
        $u = $r->user();
        $oldNickname = (string) ($u->nickname ?? '');
        $oldEmail = (string) $u->email;
        $oldPhone = (string) ($u->phone ?? '');
        if (array_key_exists('nickname', $d)) {
            $u->nickname = trim((string) ($d['nickname'] ?? '')) ?: null;
        }
        if (array_key_exists('email', $d)) {
            $u->email = strtolower(trim((string) $d['email']));
        }
        if (array_key_exists('phone', $d)) {
            $u->phone = trim((string) ($d['phone'] ?? '')) ?: null;
        }
        if (array_key_exists('avatar', $d)) {
            $u->avatar = ($d['avatar'] ?? null) ?: null;
        }
        if (! empty($d['profile']) && is_array($d['profile'])) {
            $u->profile = array_merge((array) ($u->profile ?? []), $d['profile']);
        }
        $u->save();
        $changed = [];
        if ((string) $u->nickname !== $oldNickname) {
            $changed[] = '昵称';
        }
        if ((string) $u->email !== $oldEmail) {
            $changed[] = '邮箱';
        }
        if ((string) $u->phone !== $oldPhone) {
            $changed[] = '手机号';
        }
        audit($r, '修改', '个人中心', $u->id, $u->name, '双店', $changed ? '更新'.implode('、', $changed) : '更新个人资料/营销人设');

        return ok(profilePayload($u));
    }

    /** PUT /my/password */
    public function updateMyPassword(Request $r)
    {
        $d = $r->validate([
            'oldPassword' => 'required|string',
            'newPassword' => 'required|string|min:8|max:64',
        ]);
        $u = $r->user();
        abort_unless(Hash::check($d['oldPassword'], $u->password), 422, '当前密码不正确');
        abort_if($d['oldPassword'] === $d['newPassword'], 422, '新密码不能与当前密码相同');
        $u->update(['password' => $d['newPassword']]);
        // 改密后除本会话外的设备一律下线
        $currentTokenId = optional($u->currentAccessToken())->id;
        $others = $u->tokens();
        if ($currentTokenId) {
            $others->where('id', '!=', $currentTokenId);
        }
        $others->delete();
        audit($r, '修改', '个人中心', $u->id, $u->name, '双店', '修改登录密码（其他设备已下线）');

        return ok(['ok' => true]);
    }
}
