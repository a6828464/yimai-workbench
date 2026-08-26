<?php

namespace App\Http\Controllers;

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
