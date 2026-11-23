<?php

namespace App\Services;

use App\Models\Lead;

/**
 * `leads.trial_cards` / `leads.trial_teacher` 历史脏值清洗（**唯一实现**）。
 *
 * ## 为什么单独抽成 service
 *
 * 这套逻辑有两个消费者，而且两条路径必须给出**完全一致**的判定：
 *  1. `php artisan yimai:leads-clean-trial-time`（运维习惯，服务器上一个命令就能看）；
 *  2. `POST /system/clean-trial-time`（馆主要在后台一键点，他没有 SSH 通道）。
 * 复制两份必然分叉 —— 典型的形态是「命令说命中 12 行、后台说命中 9 行」，
 * 然后再也没人敢按下那个按钮。所以命令与接口都只调用本类，**不允许各写一份**。
 *
 * 命令里只保留「怎么打印」，接口里只保留「怎么授权与回包」。
 *
 * ## 口径（与命令 docblock 同源，改动请只改这里）
 *
 * - 只清六个卡片字段 `date/time/topic/teacher/couponName/voucherCode`；
 *   `session/attended/noShow/cancelled/redeem/total/raw` 等键一律不碰。
 * - 脏 token：`undefined / null / NaN / [object Object]`（大小写不敏感，比较前 trim）。
 * - `date`/`time` 是**结构化取值**：脏 token 出现在字符串中间也要压掉并合并空白
 *   （`"2026-11-03 undefined"` → `"2026-11-03"`）；
 *   `topic`/`teacher`/`couponName`/`voucherCode` 是**自由文本**：只做「整串等于 token 才置空串」，
 *   不做子串替换（人名/课程名里出现 `NaN` 是真数据）。
 * - 清洗结果写空串而不是删键（前端与 `Lead` 的 `trialCards` 访问器都按「有键、值可空」处理）。
 * - JSON `null` 不算脏值：本类清的是**字符串 token**；把 null 也改成 '' 会重写一大批无关行
 *   并把 `updated_at` 全刷一遍，而业务上没有任何收益。
 *
 * ## 写库方式
 *
 * 读 `getRawOriginal()`（绕开 `trialCards` 访问器，避免把读时补出来的 `session` 一起写回库），
 * 只改目标字段，再由 `$casts` 的 array 编码 + `saveQuietly()` 落库：
 * 不写裸 SQL、不触发模型事件/通知/审计，只更新真正被清洗过的行。
 *
 * ## 幂等
 *
 * 清洗结果不再含任何脏 token，因此 apply 后再跑（含 dry-run）命中 0 行。
 *
 * ## 为什么不顺手清 `trial_teacher_user_id`
 *
 * 它参与 `scopeLeadsForUser()` 的可见性判断：贸然置空会让某位老师**忽然看不到**这批客资；
 * 而「该不该清」取决于当初这个 id 是不是 `withStaffIds()` 用同一份脏名解析出来的 ——
 * 这要人看一眼样例再决定。因此这里只**统计并给样例**（`staleOwnerRows`），永不修改。
 */
final class LeadTrialTimeCleaner
{
    /** 允许清洗的卡片字段（其它键一律不动） */
    public const CARD_FIELDS = ['date', 'time', 'topic', 'teacher', 'couponName', 'voucherCode'];

    /** 结构化字段：允许把出现在字符串中间的脏 token 压掉 */
    private const STRUCTURED_FIELDS = ['date', 'time'];

    /** 脏 token（全部小写，比对时 mb_strtolower + trim） */
    private const DIRTY_TOKENS = ['undefined', 'null', 'nan', '[object object]'];

    /** 压掉脏 token 的正则：`[object Object]` 中间的空格可能是任意空白 */
    private const STRIP_PATTERN = '/(?:undefined|\[object\s+object\]|nan|null)/i';

    /**
     * 跑一次清洗（默认只统计）。
     *
     * @param  bool  $apply  false = dry-run（只统计，绝不写库）
     * @param  int  $chunk  每批读取行数（按 id 分块，避免大表一次性 hydrate）
     * @param  int  $sampleLimit  返回多少条 before/after 样例（0 = 不要）
     * @return array{
     *     mode:string, scannedRows:int, hitRows:int, cardHitRows:int, teacherHitRows:int,
     *     fieldHits:array<string,int>, writtenRows:int,
     *     samples:array<int,array{id:int,changed:array<string,array{0:mixed,1:mixed}>}>,
     *     staleOwnerRows:int,
     *     staleOwnerSamples:array<int,array{id:int,trialTeacher:string,trialTeacherUserId:int}>
     * }
     */
    public function run(bool $apply = false, int $chunk = 500, int $sampleLimit = 3): array
    {
        $chunk = max(1, $chunk);
        $sampleLimit = max(0, $sampleLimit);

        $scanned = 0;
        $hitRows = 0;
        $cardHitRows = 0;
        $teacherHitRows = 0;
        $written = 0;
        $fieldHits = array_fill_keys(self::CARD_FIELDS, 0);
        $samples = [];
        $staleOwnerRows = 0;
        $staleOwnerSamples = [];

        Lead::query()
            ->select(['id', 'trial_cards', 'trial_teacher', 'trial_teacher_user_id'])
            ->chunkById($chunk, function ($leads) use (
                $apply, $sampleLimit, &$scanned, &$hitRows, &$cardHitRows, &$teacherHitRows,
                &$written, &$fieldHits, &$samples, &$staleOwnerRows, &$staleOwnerSamples
            ): void {
                foreach ($leads as $lead) {
                    $scanned++;

                    $rawCards = $lead->getRawOriginal('trial_cards');
                    $cards = is_array($rawCards) ? $rawCards : (json_decode((string) $rawCards, true) ?: []);

                    $cleanCards = [];
                    $changedFields = [];
                    $cardHitRowsInLead = 0;
                    foreach ($cards as $index => $card) {
                        if (! is_array($card)) {
                            $cleanCards[] = $card;   // 非数组条目原样保留：不猜、不删

                            continue;
                        }
                        $before = $card;
                        $card = $this->sanitizeCard($card, $fieldHits);
                        if ($card !== $before) {
                            $cardHitRowsInLead++;
                            foreach ($card as $field => $value) {
                                if (in_array($field, self::CARD_FIELDS, true)
                                    && ($before[$field] ?? null) !== $value) {
                                    $changedFields['cards['.$index.'].'.$field] = [$before[$field] ?? null, $value];
                                }
                            }
                        }
                        $cleanCards[] = $card;
                    }

                    // trial_teacher 是「第一节 teacher」的镜像列，脏值会一起污染（影响可见性与课时统计）
                    $rawTeacher = $lead->getRawOriginal('trial_teacher');
                    $teacherDirty = $this->isDirtyToken($rawTeacher);
                    if ($teacherDirty) {
                        $changedFields['trial_teacher'] = [$rawTeacher, ''];
                    }

                    // 「脏名 + 残留归属 id」：只读统计（见类注释最后一节）
                    $teacherEmptyAfter = $teacherDirty || trim((string) $rawTeacher) === '';
                    $staleOwnerId = (int) ($lead->getRawOriginal('trial_teacher_user_id') ?? 0);
                    if ($teacherEmptyAfter && $staleOwnerId > 0) {
                        $staleOwnerRows++;
                        if (count($staleOwnerSamples) < min(3, max(1, $sampleLimit))) {
                            $staleOwnerSamples[] = [
                                'id' => (int) $lead->id,
                                'trialTeacher' => '',
                                'trialTeacherUserId' => $staleOwnerId,
                            ];
                        }
                    }

                    if ($cardHitRowsInLead === 0 && ! $teacherDirty) {
                        continue;   // 没命中：完全不碰这一行（updated_at 也不动）
                    }

                    $hitRows++;
                    if ($cardHitRowsInLead > 0) {
                        $cardHitRows++;
                    }
                    if ($teacherDirty) {
                        $teacherHitRows++;
                    }

                    if (count($samples) < $sampleLimit) {
                        $samples[] = ['id' => (int) $lead->id, 'changed' => $changedFields];
                    }

                    if (! $apply) {
                        continue;
                    }

                    if ($cardHitRowsInLead > 0) {
                        $lead->trial_cards = $cleanCards;
                    }
                    if ($teacherDirty) {
                        $lead->trial_teacher = '';
                    }
                    $lead->saveQuietly();
                    $written++;
                }
            });

        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            'scannedRows' => $scanned,
            'hitRows' => $hitRows,
            'cardHitRows' => $cardHitRows,
            'teacherHitRows' => $teacherHitRows,
            'fieldHits' => $fieldHits,
            'writtenRows' => $written,
            'samples' => $samples,
            'staleOwnerRows' => $staleOwnerRows,
            'staleOwnerSamples' => $staleOwnerSamples,
        ];
    }

    /**
     * 清洗一张卡片（只动 CARD_FIELDS）。
     *
     * @param  array<string,mixed>  $card
     * @param  array<string,int>  $fieldHits  按字段累加命中次数（引用传，报告用）
     * @return array<string,mixed>
     */
    private function sanitizeCard(array $card, array &$fieldHits): array
    {
        foreach (self::CARD_FIELDS as $field) {
            if (! array_key_exists($field, $card)) {
                continue;   // 键本来就没有：不补键（补了会改变卡片形状）
            }
            $value = $card[$field];

            if ($this->isDirtyToken($value)) {
                $card[$field] = '';
                $fieldHits[$field]++;

                continue;
            }

            // 只有结构化字段做子串压缩；自由文本只做上面的整串判定
            if (in_array($field, self::STRUCTURED_FIELDS, true) && is_string($value)) {
                $stripped = $this->stripDirtyTokens($value);
                if ($stripped !== $value) {
                    $card[$field] = $stripped;
                    $fieldHits[$field]++;
                }
            }
        }

        return $card;
    }

    /** 整串是否等于脏 token（大小写不敏感）；只认字符串，JSON null 不算（见类注释） */
    private function isDirtyToken(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return in_array(mb_strtolower(trim($value)), self::DIRTY_TOKENS, true);
    }

    /** 压掉字符串里的脏 token 与多余空白（`"2026-11-03 undefined"` → `"2026-11-03"`） */
    private function stripDirtyTokens(string $value): string
    {
        $stripped = preg_replace(self::STRIP_PATTERN, ' ', $value);
        $stripped = $stripped ?? $value;
        $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;

        return trim($stripped);
    }
}
