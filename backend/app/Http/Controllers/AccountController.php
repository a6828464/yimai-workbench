<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

final class AccountController extends Controller
{
    /** 可选角色，顺序即权限从大到小 */
    private const ASSIGNABLE = ['R_SUPER', 'R_MANAGER', 'R_TEACHER', 'R_SERVICE', 'R_MEDIA'];

    /** 需要锁定到单一门店的角色（超管/新媒体为双店） */
    private const VENUE_BOUND = ['R_MANAGER', 'R_SERVICE', 'R_TEACHER'];

    private function roleMap(): array
    {
        return ['R_SUPER' => '超管', 'R_MANAGER' => '店长', 'R_SERVICE' => '服务老师', 'R_TEACHER' => '授课老师', 'R_MEDIA' => '新媒体'];
    }

    /** 角色码数组 → 中文标签串（按权限从大到小排，展示顺序稳定） */
    private function label(array $roles): string
    {
        $map = $this->roleMap();
        $ordered = array_values(array_filter(self::ASSIGNABLE, fn ($r) => in_array($r, $roles, true)));

        return implode(' + ', array_map(fn ($x) => $map[$x] ?? $x, $ordered));
    }

    /** GET /accounts */
    public function index(Request $r)
    {
        abort_unless(userHasRole($r->user(), 'R_SUPER'), 403);
        $selfName = $r->user()->name;

        return ok(User::orderBy('id')->get()->map(function ($u) use ($selfName) {
            $roles = userRoles($u);

            return [
                'key' => $u->username,
                'userName' => $u->name,
                // roleCode 保留为「主角色」，roles 为全部角色（前端按多选渲染）
                'roleCode' => primaryRole($roles),
                'roles' => $roles,
                'roleLabel' => $this->label($roles),
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
            // roles 与 roleCode 二选一：roles 是多角色入口，roleCode 保留给旧客户端
            'roles' => 'nullable|array|min:1',
            'roles.*' => 'string|in:R_SUPER,R_MANAGER,R_SERVICE,R_TEACHER,R_MEDIA',
            'roleCode' => 'nullable|string|in:R_SUPER,R_MANAGER,R_SERVICE,R_TEACHER,R_MEDIA',
            'venues' => 'required|array|min:1',
            'venues.*' => 'string|in:绿地店,东部店',
            'email' => 'nullable|email|max:60',
            'password' => 'required|string|min:8|max:64',
        ]);
        abort_unless(User::where('username', $d['userName'])->doesntExist(), 422, '登录名已存在');

        // 兼容旧客户端：只传 roleCode 时按单角色处理
        $roles = array_values(array_unique($d['roles'] ?? array_filter([$d['roleCode'] ?? ''])));
        abort_if($roles === [], 422, '请至少选择一个角色');

        $name = trim((string) ($d['name'] ?? '')) !== '' ? trim((string) $d['name']) : $d['userName'];
        $venues = $d['venues'];
        // 含任一门店绑定角色（店长/服务老师/授课老师）即锁定到单一门店
        $bound = array_intersect($roles, self::VENUE_BOUND) !== [];
        $venue = $bound ? $venues[0] : null;
        $user = User::create([
            'name' => $name,
            'username' => $d['userName'],
            'email' => $d['email'] ?? ($d['userName'].'@yimai.local'),
            'password' => $d['password'],
            'role' => primaryRole($roles),
            'roles' => $roles,
            'venue' => $venue,
            'venues' => $venues,
            'status' => '启用',
        ]);
        audit($r, '新增', '人员管理', $user->id, $user->name, is_string($venue) ? $venue : '双店',
            "开通账号：{$user->name}（{$this->label($roles)}）/ 门店[".implode('、', $venues).']');

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
            'roles' => 'nullable|array|min:1',
            'roles.*' => 'string|in:R_SUPER,R_MANAGER,R_SERVICE,R_TEACHER,R_MEDIA',
            'roleCode' => 'nullable|string|in:R_SUPER,R_MANAGER,R_SERVICE,R_TEACHER,R_MEDIA',
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
            // 兼容旧客户端：只传 roleCode 时按单角色处理
            $roles = $d['roles'] ?? (isset($d['roleCode']) && $d['roleCode'] !== '' ? [$d['roleCode']] : null);
            if ($roles !== null) {
                $roles = array_values(array_unique(array_filter($roles)));
                abort_if($roles === [], 422, '请至少选择一个角色');
                $before = userRoles($user);
                $patch['roles'] = $roles;
                $patch['role'] = primaryRole($roles);
                // 含门店绑定角色即锁定到单一门店；否则放开为双店
                $bound = array_intersect($roles, self::VENUE_BOUND) !== [];
                $patch['venue'] = $bound ? (($d['venues'] ?? $user->venues)[0] ?? null) : null;
                $detail = '角色调整：'.$this->label($before).' → '.$this->label($roles);
            }
            if (! empty($d['venues'])) {
                $patch['venues'] = $d['venues'];
            }
            if (! empty($patch)) {
                $user->update($patch);
                // 角色变了要立刻生效：清掉旧 token，避免前端还拿着旧角色继续用
                if (isset($patch['roles'])) {
                    $user->tokens()->delete();
                }
            }
            $detail = $detail ?: '资料更新';
        }
        audit($r, $action === 'resetPassword' ? '重置' : '修改', '人员管理', $user->id, $user->name, is_string($user->venue) ? $user->venue : '双店', $detail);

        return ok(['ok' => true]);
    }
}
