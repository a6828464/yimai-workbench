<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\WeChatSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'userName' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $data['userName'])
            ->orWhere('name', $data['userName'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'userName' => '账号或密码错误',
            ]);
        }

        if (($user->status ?? '启用') === '停用') {
            throw ValidationException::withMessages([
                'userName' => '账号已停用，请联系管理员',
            ]);
        }

        $token = $user->createToken('workbench')->plainTextToken;

        return response()->json([
            'code' => 0,
            'data' => [
                'token' => $token,
                'refreshToken' => $token,
                'userInfo' => $user->userInfo(),
            ],
        ]);
    }

    /**
     * 微信授权登录（小程序 P2 硬门）。
     *
     * 链路：小程序 wx.login → code → 本接口 → code2session → openid → 匹配已绑定账号 → Sanctum Token。
     *
     * 三条硬口径，改之前先读：
     *  1. **绝不自动建号**。openid 是「这个微信」而不是「这个员工」，自动建号等于把
     *     员工系统开放给任何扫码的人；账号仍由「人员管理」开通，这里只做身份匹配。
     *  2. **未绑定就明确拒绝**，并给出可执行的下一步（先用账号密码登录 / 找管理员绑定），
     *     而不是回一个含糊的 500。前端凭 message 展示，所以文案必须是人话。
     *  3. **停用账号一律拦下**，与 login() 同一口径 —— 否则小程序会成为绕过停用的后门。
     */
    public function wxLogin(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:128',
        ]);

        $session = WeChatSession::resolve($data['code']);
        if (! $session['ok']) {
            return response()->json([
                'code' => 1,
                'message' => $session['message'],
            ], 422);
        }

        $openid = $session['openid'];
        $user = User::where('wx_openid', $openid)->first();

        if (! $user) {
            // 未绑定：区分「有 unionid 但没绑」与「全新微信」，便于排障；对外文案一致。
            return response()->json([
                'code' => 1,
                'message' => '该微信尚未绑定员工账号，请先用账号密码登录，或联系管理员在「人员管理」完成绑定',
            ], 403);
        }

        if (($user->status ?? '启用') === '停用') {
            return response()->json([
                'code' => 1,
                'message' => '账号已停用，请联系管理员',
            ], 403);
        }

        // unionid 可能后续才下发（同开放平台绑定变化），拿到就补齐，不覆盖已有值。
        if (! empty($session['unionid']) && empty($user->wx_unionid)) {
            $user->forceFill(['wx_unionid' => $session['unionid']])->save();
        }

        $token = $user->createToken('workbench-weixin')->plainTextToken;

        return response()->json([
            'code' => 0,
            'data' => [
                'token' => $token,
                'refreshToken' => $token,
                'userInfo' => $user->userInfo(),
            ],
        ]);
    }

    /** 自助注册（默认关闭，后台可配置 REGISTRATION_ENABLED=true 开启） */
    public function register(Request $request)
    {
        $enabled = filter_var(config('services.registration.enabled', false), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return response()->json(['code' => 1, 'message' => '当前未开放自助注册，请联系管理员在「人员管理」开通账号'], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:20',
            'userName' => 'required|string|max:20|alpha_num|unique:users,username',
            'password' => 'required|string|min:8|max:64',
            'venue' => 'required|string|in:绿地店,东部店',
            'code' => 'nullable|string',
        ]);

        // 邀请码校验（可选：配置 REGISTRATION_CODE 兜底）
        $configCode = (string) config('services.registration.code', '');
        if ($configCode !== '' && ($data['code'] ?? '') !== $configCode) {
            return response()->json(['code' => 1, 'message' => '邀请码错误'], 422);
        }

        // 自助注册账号的角色：与「人员管理」开通账号同一口径 —— roles 是唯一事实来源，
        // role 单值列同步写成 primaryRole（这里只有 R_TEACHER 一个角色，两者必然相等）。
        // 之前只写 role 单值、roles 留空，靠 userRoles() 的回退才能工作：账号在 UI 上
        // 显示成一个角色，但「多角色叠加」的模型对它不成立，后面有人在 roles 上做
        // 判断（如 AccountController 的角色展示/编辑、多角色叠加的可见范围并集）就会踩空。
        $roles = ['R_TEACHER'];

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['userName'],
            'email' => $data['userName'].'@yimai.local',
            'password' => $data['password'],
            'role' => primaryRole($roles),
            'roles' => $roles,
            'venue' => $data['venue'],
            'venues' => [$data['venue']],
            'status' => '启用',
        ]);

        $token = $user->createToken('workbench')->plainTextToken;

        return response()->json([
            'code' => 0,
            'data' => [
                'token' => $token,
                'refreshToken' => $token,
                'userInfo' => $user->userInfo(),
            ],
        ]);
    }

    /**
     * GET /auth/wx-identity —— 当前登录者**自己的** openid。
     *
     * 为什么单独开一个端点而不是塞进 /me：openid 被能从 `$hidden` 里拿出来用，
     * 是因为「员工要把自己的 openid 报给管理员做绑定」这件事必须有出口；
     * 但它只在「本人已登录」的前提下才有意义，塞进 /me 会让每次启动都多带一个
     * 可被冒用的键。放这里，绑定页只在自己点开时取一次。
     *
     * 安全：只返回请求者自己的，不接任何 id 参数，不需要超管。
     */
    public function wxIdentity(Request $request)
    {
        $u = $request->user();

        return response()->json([
            'code' => 0,
            'data' => [
                'openid' => (string) ($u->wx_openid ?? ''),
                'bound' => ! empty($u->wx_openid),
                'boundAt' => $u->wx_bound_at?->toIso8601String(),
                'hasUnionid' => ! empty($u->wx_unionid),
            ],
        ]);
    }

    /**
     * PUT /auth/wx-self-bind —— 本人把「当前这个微信」绑到自己账号上（自助绑定）。
     *
     * 与管理员绑定的分工：管理员那条（/accounts/{key}/wx-binding）需要有人把 openid
     * 抄过去，员工一多就是来回沟通。这里让「已经用账号密码登录过的人」直接把自己的
     * 微信绑上：openid 从 code2session 现取，不经任何人手抄，也就抄不错。
     *
     * 三条硬口径：
     *  1. **必须已登录**（账号密码登录过）；否则等于开放注册。
     *  2. **一个微信只能绑一个账号**，先查后写 + 唯一索引兜底，冲突给可读 422。
     *  3. **本人已有绑定则直接覆盖**（换微信的场景），但覆盖前同样要查冲突。
     */
    public function wxSelfBind(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:128']);

        $session = WeChatSession::resolve($data['code']);
        if (! $session['ok']) {
            return response()->json(['code' => 1, 'message' => $session['message']], 422);
        }

        $openid = (string) $session['openid'];
        $u = $request->user();

        $taken = User::where('wx_openid', $openid)->where('id', '!=', $u->id)->first();
        if ($taken !== null) {
            return response()->json([
                'code' => 1,
                'message' => '当前微信已绑定到账号「'.$taken->name.'」，请先让管理员解绑',
            ], 422);
        }

        $u->forceFill([
            'wx_openid' => $openid,
            'wx_unionid' => $session['unionid'] ?: $u->wx_unionid,
            'wx_bound_at' => $u->wx_bound_at ?? now(),
        ])->save();

        audit($request, '绑定', '账号', $u->id, $u->name, (string) $u->venue, '自助绑定微信');

        return response()->json([
            'code' => 0,
            'data' => ['bound' => true, 'boundAt' => $u->wx_bound_at?->toIso8601String()],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'code' => 0,
            'data' => $request->user()->userInfo(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['code' => 0]);
    }
}
