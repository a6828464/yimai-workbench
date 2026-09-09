<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

final class AccountController extends Controller
{
    /** GET /accounts */
    public function index(Request $r)
    {
        abort_unless($r->user()->role === 'R_SUPER', 403);
        $roleMap = ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_TEACHER' => '老师', 'R_MEDIA' => '新媒体'];
        $selfName = $r->user()->name;

        return ok(User::orderBy('id')->get()->map(function ($u) use ($roleMap, $selfName) {
            return [
                'key' => $u->username,
                'userName' => $u->name,
                'roleCode' => $u->role,
                'roleLabel' => $roleMap[$u->role] ?? $u->role,
                'venues' => $u->venues ?? [],
                'email' => $u->email,
                'status' => $u->status ?? '启用',
                'self' => $u->name === $selfName,
            ];
        }));
    }

    /** POST /accounts */
    public function store(Request $r)
    {
        requireSuper($r);
        $d = $r->validate([
            'userName' => 'required|string|max:20',
            'name' => 'nullable|string|max:20',
            'roleCode' => 'required|string|in:R_SUPER,R_MANAGER,R_TEACHER,R_MEDIA',
            'venues' => 'required|array|min:1',
            'venues.*' => 'string|in:绿地店,东部店',
            'email' => 'nullable|email|max:60',
            'password' => 'required|string|min:8|max:64',
        ]);
        abort_unless(User::where('username', $d['userName'])->doesntExist(), 422, '登录名已存在');

        $name = trim((string) ($d['name'] ?? '')) !== '' ? trim((string) $d['name']) : $d['userName'];
        $venues = $d['venues'];
        $venue = $d['roleCode'] === 'R_MANAGER' ? $venues[0] : ($d['roleCode'] === 'R_TEACHER' ? $venues[0] : null);
        $user = User::create([
            'name' => $name,
            'username' => $d['userName'],
            'email' => $d['email'] ?? ($d['userName'].'@yimai.local'),
            'password' => $d['password'],
            'role' => $d['roleCode'],
            'venue' => $venue,
            'venues' => $venues,
            'status' => '启用',
        ]);
        $roleMap = ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_TEACHER' => '老师', 'R_MEDIA' => '新媒体'];
        $roleLabel = $roleMap[$d['roleCode']] ?? $d['roleCode'];
        audit($r, '新增', '人员管理', $user->id, $user->name, is_string($venue) ? $venue : '双店', "开通账号：{$user->name} ({$roleLabel}) / 门店[".implode('、', $venues).']');

        return ok(['key' => $user->username]);
    }

    /** PATCH /accounts/{key} */
    public function update(Request $r, string $key)
    {
        requireSuper($r);
        $user = User::where('username', $key)->firstOrFail();
        if ($user->id === $r->user()->id) {
            abort(422, '不能操作自己的账号');
        }

        $action = $r->input('action', 'update');
        $allowed = ['update', 'disable', 'enable', 'resetPassword', 'delete'];
        abort_unless(in_array($action, $allowed, true), 422, '未知操作');

        $d = $r->validate([
            'roleCode' => 'nullable|string|in:R_SUPER,R_MANAGER,R_TEACHER,R_MEDIA',
            'venues' => 'nullable|array|min:1',
            'venues.*' => 'string|in:绿地店,东部店',
            'password' => 'nullable|string|min:8|max:64',
        ]);
        $detail = '';
        if ($action === 'delete') {
            $targetName = $user->name;
            $targetVenue = $user->venue;
            $user->tokens()->delete();
            $user->delete();
            audit($r, '删除', '人员管理', $user->id, $targetName, is_string($targetVenue) ? $targetVenue : '双店', "删除账号：{$targetName}（登录名 {$key}）");

            return ok(['ok' => true]);
        }
        if ($action === 'disable' || $action === 'enable') {
            $user->update(['status' => $action === 'disable' ? '停用' : '启用']);
            if ($action === 'disable') {
                $user->tokens()->delete();
            }
            $detail = $action === 'disable' ? '停用账号' : '启用账号';
        } elseif ($action === 'resetPassword') {
            abort_unless(! empty($d['password']), 422, '请输入新密码');
            $user->update(['password' => $d['password']]);
            $user->tokens()->delete();
            $detail = '重置密码';
        } else {
            $patch = [];
            if (! empty($d['roleCode']) && $d['roleCode'] !== $user->role) {
                $patch['role'] = $d['roleCode'];
                // 店长/老师必须有门店范围；超管/新媒体为双店
                $patch['venue'] = in_array($d['roleCode'], ['R_MANAGER', 'R_TEACHER'], true) ? (($d['venues'] ?? $user->venues)[0] ?? null) : null;
                $detail = '角色调整';
            }
            if (! empty($d['venues'])) {
                $patch['venues'] = $d['venues'];
            }
            if (! empty($patch)) {
                $user->update($patch);
            }
            $detail = $detail ?: '资料更新';
        }
        audit($r, $action === 'resetPassword' ? '重置' : '修改', '人员管理', $user->id, $user->name, is_string($user->venue) ? $user->venue : '双店', $detail);

        return ok(['ok' => true]);
    }
}
