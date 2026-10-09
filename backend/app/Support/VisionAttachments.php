<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 朋友圈「图片素材」多模态透传（AiController::chat 的图片侧实现）。
 *
 * ## 设计口径（为什么这样写）
 *
 * - **只在内存里过一遍**：dataURL 解码仅用于格式/体积校验，随后原样交给上游；
 *   任何字段都不写库、不写日志（`input_summary` 另有脱敏兜底）。
 * - **不新增出站能力**：只接受 `data:image/(jpeg|png|webp);base64,...` 内联载荷，
 *   远程 URL 会被校验规则直接拒掉，因此代理层不引入「服务端按用户给的地址去取图」
 *   这条新路径 —— SSRF 面与改造前一致。
 * - **绝不静默截断**：超体积/坏格式一律 422 + 中文可读提示。截断会让「模型看到的图」
 *   与「用户上传的图」不是同一张，属于典型的假绿失败面。
 * - **payload 兼容**：无图片时 `appendMessage()` 原样返回入参数组，上游 payload
 *   与改造前逐字节一致。
 */
final class VisionAttachments
{
    /** 单次请求最多图片数（与前端 admin-web 侧的 ≤3 张契约一致） */
    public const MAX_IMAGES = 3;

    /** 单张图片 base64 解码后的字节上限：1.5MB（可由 services.ai.max_image_bytes 覆盖） */
    private const DEFAULT_MAX_BYTES = 1572864;

    /**
     * 多图解码后**总体积**上限：4MB（可由 services.ai.max_total_image_bytes 覆盖）。
     *
     * 为什么应用层还要单独卡一道：`post_max_size` 缺省 8M，而单张上限 1.5MB × 3 张
     * 的 base64 膨胀后约 6.0MB，已经吃掉 75% —— 再叠加 messages/prompt 就贴到天花板，
     * 表现为「框架级英文 413 / body 被丢」。在应用层先按解码后字节数拦下，
     * 才能给出「减少图片数量」这种可照做的中文提示。
     */
    private const DEFAULT_MAX_TOTAL_BYTES = 4194304;

    /**
     * 请求体整体超过服务器上限（PHP post_max_size / 前置代理）时的统一文案。
     *
     * 两处使用：控制器兜底（body 被丢弃且无 Content-Length 痕迹）与全局
     * ValidatePostSize 抛出的 PostTooLargeException 渲染（见 bootstrap/app.php）。
     * 两处文案必须是同一句 —— 否则用户看到的话取决于 SAPI 怎么填 CONTENT_LENGTH。
     */
    public const OVERSIZE_BODY_MESSAGE = '图片总体积超出服务器上限，请减少图片数量或压缩后再试';

    /** 合法的 dataURL 形态（与控制器校验规则同一口径） */
    public const DATA_URL_REGEX = '#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#';

    /** 单张图片字节上限 */
    public static function maxBytes(): int
    {
        $configured = (int) (config('services.ai.max_image_bytes') ?: self::DEFAULT_MAX_BYTES);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_BYTES;
    }

    /** 多图解码后总体积上限 */
    public static function maxTotalBytes(): int
    {
        $configured = (int) (config('services.ai.max_total_image_bytes') ?: self::DEFAULT_MAX_TOTAL_BYTES);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_TOTAL_BYTES;
    }

    /**
     * 逐张校验（格式已在控制器规则里把关，这里补「能不能解码」与「解码后多大」）。
     *
     * 返回按入参顺序重排后的 dataURL 列表；不合法直接 422 + 中文提示。
     * 索引按「用户看到的顺序」计（第 1 张 = 数组第一个元素）。
     *
     * @param  array<int|string, mixed>  $dataUrls
     * @return list<string>
     */
    public static function inspect(array $dataUrls): array
    {
        $items = array_values($dataUrls);
        abort_if(count($items) > self::MAX_IMAGES, 422, '一次最多上传 '.self::MAX_IMAGES.' 张图片，请减少图片数量后重试');

        $maxBytes = self::maxBytes();
        $maxTotalBytes = self::maxTotalBytes();
        $totalBytes = 0;
        $checked = [];

        foreach ($items as $index => $dataUrl) {
            $position = $index + 1;
            $dataUrl = (string) $dataUrl;

            abort_unless(
                (bool) preg_match(self::DATA_URL_REGEX, $dataUrl),
                422,
                '第 '.$position.' 张图片格式不支持，请上传 JPG/PNG/WebP 图片'
            );

            $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
            abort_if($binary === false, 422, '第 '.$position.' 张图片内容损坏，请重新选择图片');

            $size = strlen($binary);
            abort_if(
                $size > $maxBytes,
                422,
                sprintf(
                    // 两位小数：只给一位时「约 1.5MB / 上限 1.5MB」会自相矛盾，
                    // 用户看了不知道到底超没超（实测踩过）。
                    '第 %d 张图片过大（%.2fMB），单张上限 %.2fMB，请压缩后重试',
                    $position,
                    $size / 1048576,
                    $maxBytes / 1048576
                )
            );

            // 挡掉「base64 合法但不是图片」的载荷：这类请求上游必然报错，
            // 早点用中文说清楚比让模型接口回一句英文更好。
            $info = @getimagesizefromstring($binary);
            abort_if(
                ! is_array($info) || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true),
                422,
                '第 '.$position.' 张图片内容不是有效的图片文件，请重新选择图片（仅支持 JPG/PNG/WebP）'
            );

            $totalBytes += $size;
            $checked[] = $dataUrl;
        }

        // 多图聚合：单张都合格，但合计会把请求体顶到 post_max_size 上限。
        // 这里提前用中文说清楚，而不是让框架回一句英文 413 / 让 body 被静默丢弃。
        abort_if(
            $totalBytes > $maxTotalBytes,
            422,
            sprintf(
                '%d 张图片合计 %.2fMB，超出本次总量上限 %.2fMB，请减少图片数量或压缩后再试',
                count($checked),
                $totalBytes / 1048576,
                $maxTotalBytes / 1048576
            )
        );

        return $checked;
    }

    /**
     * 把图片与「最后一条 user 消息的文本」合并成一条 OpenAI 兼容多模态消息，
     * 追加在 `messages` 末尾。
     *
     * 无图片时原样返回（上游 payload 与改造前逐字节一致）。
     * 文本为空（例如对话里根本没有 user 消息）时省略 text 分片，只发图片分片 ——
     * 上游对空 text 分片的容忍度不一致，少一个分片比多一个空串安全。
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  list<string>  $dataUrls
     * @return array<int, array<string, mixed>>
     */
    public static function appendMessage(array $messages, array $dataUrls): array
    {
        if ($dataUrls === []) {
            return $messages;
        }

        $text = '';
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'user') {
                $text = (string) ($message['content'] ?? '');
            }
        }

        $parts = [];
        if ($text !== '') {
            $parts[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($dataUrls as $dataUrl) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
        }

        $messages[] = ['role' => 'user', 'content' => $parts];

        return $messages;
    }

    /**
     * 生成 Historical 摘要（`model_generation_records.input_summary`）。
     *
     * 300 字符截断口径不变；额外把任何 `data:image/...;base64,...` 片段替成 `[图片]`，
     * 使「base64 不落库」成为**代码级不变量**，而不是「当前调用方恰好没这么传」。
     */
    public static function summary(string $text): string
    {
        $scrubbed = (string) preg_replace(
            '#data:[a-z0-9.+-]+/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]+#i',
            '[图片]',
            $text
        );

        return mb_substr($scrubbed, 0, 300);
    }

    /**
     * 上游 4xx 是不是「不支持读图 / 不支持该图片格式」类错误。
     *
     * 只在**本次请求带了图片**时被调用，所以像 `image` / `vision` 这类宽泛词不会误伤
     * 纯文本请求；命中即如实告诉用户去换模型，不做静默去图重试（那会让用户误以为
     * 文案是「看过图」写的）。
     */
    public static function looksLikeImageUnsupported(int $status, string $body): bool
    {
        if ($status < 400 || $status > 499) {
            return false;
        }

        return (bool) preg_match(
            '#(image|vision|multimodal|modality|图片|不支持|not support|unsupported|invalid content type)#i',
            $body
        );
    }

    /**
     * `PostTooLargeException`（全局 ValidatePostSize 中间件抛出，即 Content-Length 超 post_max_size）
     * 的 API 渲染：换成上面那句中文，返回 413。
     *
     * **为什么必须在这里兜**：该中间件在控制器之前执行，所以只靠控制器里的空 body 判定，
     * 「声明了 Content-Length 的常见情形」永远走不到 —— 用户拿到的是框架默认英文
     * 「The POST data is too large.」。非 API 请求返回 null，交给框架默认渲染，不影响其它入口。
     */
    public static function renderPostTooLarge(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json(['code' => 1, 'message' => self::OVERSIZE_BODY_MESSAGE], 413);
    }
}
