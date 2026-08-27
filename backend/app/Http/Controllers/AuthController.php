<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\User;
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

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['userName'],
            'email' => $data['userName'].'@yimai.local',
            'password' => $data['password'],
            'role' => 'R_TEACHER',
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
