<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微信小程序 code2session 客户端。
 *
 * 为什么单独抽出来：AuthController 里只应该出现「登录业务分支」，
 * 而 code2session 的错误码映射（40029/40163/45011/40226…）是一张需要
 * 独立测试的表，混在控制器里既测不了也改不动。
 *
 * 安全口径：
 *  - appsecret 只从服务端配置读取，任何情况下不写日志、不回传、不进响应体。
 *  - 微信返回的 errcode/errmsg 原样记录到日志用于排障，但对外只给归一化文案，
 *    防止把「appid 未配置」这类内部状态暴露给客户端。
 */
class WeChatSession
{
    /** code2session 端点（官方固定地址，不接受外部注入） */
    private const ENDPOINT = 'https://api.weixin.qq.com/sns/jscode2session';

    /**
     * 微信侧错误码 → 面向用户的中文提示。
     *
     * 关键区分：
     *  - 40029 / 40163 = code 无效或已使用 → 用户可以「重试一次」解决（前端重新 wx.login 拿新 code）
     *  - 45011           = 频率限制 → 用户重试无用，必须等，所以要单独文案
     *  - 40226           = 高风险用户被微信拦截 → 换账号才行
     *  - -1 / 45009      = 微信系统繁忙 → 归一到「稍后再试」
     *  - 40125 / 41002 / 41004 = **我们自己配错了**（appsecret 错 / appid 缺失 / secret 缺失）。
     *    实测：真实 appid + 错 secret 时微信回 40125（2026-11-04 验证）。
     *    这条绝不能归到「稍后再试」—— 管理员会去查网络/重试，而问题在 .env。
     *    对外不暴露具体是哪个键错，但对内必须 log::error 并给出可执行的排查指令。
     */
    private const ERROR_MESSAGES = [
        40029 => '微信登录凭证已失效，请重试',
        40163 => '微信登录凭证已被使用，请重试',
        45011 => '登录请求过于频繁，请稍后再试',
        40226 => '当前微信账号存在风险，无法登录',
        -1 => '微信服务繁忙，请稍后再试',
        45009 => '微信服务繁忙，请稍后再试',
        40125 => '微信登录配置有误，请联系管理员检查小程序密钥',
        41002 => '微信登录配置有误，请联系管理员检查小程序 AppID',
        41004 => '微信登录配置有误，请联系管理员检查小程序密钥',
    ];

    /** 这些错误码是「服务端配置问题」，必须打 error 日志（会触发告警），而不是 warning */
    private const CONFIG_ERROR_CODES = [40125, 41002, 41004];

    /**
     * 用 wx.login 拿到的 code 换取身份。
     *
     * @return array{ok: bool, openid?: string, unionid?: ?string, message?: string, detail?: array}
     */
    public static function resolve(string $code): array
    {
        $appid = (string) config('services.wechat.appid', '');
        $secret = (string) config('services.wechat.secret', '');

        // 配置缺失是部署问题，不是用户问题：直接给出「未开通」而不是伪造成功或抛 500。
        if ($appid === '' || $secret === '') {
            Log::warning('[wx-login] 未配置 WECHAT_MINI_APPID / WECHAT_MINI_SECRET，微信登录不可用');

            return ['ok' => false, 'message' => '微信登录未开通，请使用账号密码登录'];
        }

        try {
            $resp = Http::timeout(8)->get(self::ENDPOINT, [
                'appid' => $appid,
                'secret' => $secret,
                'js_code' => $code,
                'grant_type' => 'authorization_code',
            ]);
        } catch (\Throwable $e) {
            // 网络层失败：与「code 无效」区分开，前端提示应偏向「稍后再试」
            Log::warning('[wx-login] code2session 请求异常', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => '微信服务暂时不可用，请稍后再试'];
        }

        if (! $resp->ok()) {
            Log::warning('[wx-login] code2session HTTP 非 2xx', ['status' => $resp->status()]);

            return ['ok' => false, 'message' => '微信服务暂时不可用，请稍后再试'];
        }

        $data = $resp->json();

        // 微信把业务错误放在 200 响应体里，必须看 errcode 而不是 HTTP 状态码。
        $errcode = (int) ($data['errcode'] ?? 0);
        if ($errcode !== 0) {
            $isConfigError = in_array($errcode, self::CONFIG_ERROR_CODES, true);

            // 配置类错误用 error 级别：这类问题不改 .env 永远不会自愈，
            // 若混在 warning 里会被日常噪音淹没，配置错误可以在线上躺很久。
            $isConfigError
                ? Log::error('[wx-login] code2session 配置错误（检查 WECHAT_MINI_APPID / WECHAT_MINI_SECRET）', [
                    'errcode' => $errcode,
                    'errmsg' => $data['errmsg'] ?? '',
                ])
                : Log::warning('[wx-login] code2session 业务错误', [
                    'errcode' => $errcode,
                    'errmsg' => $data['errmsg'] ?? '',
                ]);

            return [
                'ok' => false,
                'message' => self::ERROR_MESSAGES[$errcode] ?? '微信登录失败，请稍后再试',
                'detail' => ['errcode' => $errcode],
            ];
        }

        $openid = (string) ($data['openid'] ?? '');
        if ($openid === '') {
            Log::warning('[wx-login] code2session 返回空 openid');

            return ['ok' => false, 'message' => '微信登录失败，请稍后再试'];
        }

        return [
            'ok' => true,
            'openid' => $openid,
            'unionid' => isset($data['unionid']) ? (string) $data['unionid'] : null,
        ];
    }
}
