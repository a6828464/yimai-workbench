<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * 清理 `leads.trial_cards` / `leads.trial_teacher` 里被写进库的 `undefined` 类脏值。
 *
 * ## 为什么要有这条命令
 *
 * 小程序早先的体验课「时间」用的是原生 picker，取值口径错配，用户一选中时间框就把字符串
 * `"undefined"` 拼进了卡片，落库形态是 `trial_cards[i].time = "2026-11-03 undefined"`。
 * 前端（t7）已经把取值修好、展示侧也做了容错，但**库里那批脏值不会自己消失**，
 * 而且会被后续读方当真：`TodayController` 用 `time` 的前 10 位做「今日体验课」判定
 * （脏值恰好还能截出日期，看起来像正常数据）、`LeadController::withStaffIds()`
 * 会把第一节的 `teacher` 镜像到 `leads.trial_teacher`（脏值一起污染，进而影响
 * **可见性**与**课时统计**：`PayrollController` 按 `trial_teacher` 计留资数）。
 *
 * 所以这是一条**一次性的数据修补**命令，不是业务逻辑的一部分：
 * 修复点在前端（写入侧），这里只负责把历史脏值抹掉。**不要在 `Lead` 模型或
 * `LeadController` 里加"读时清洗"**——那会把脏值永久留在库里，且每个读方各洗一遍必然分叉。
 *
 * ## 口径（改之前先读）
 *
 * 1. **只认六个卡片字段**：`date / time / topic / teacher / couponName / voucherCode`。
 *    `session / attended / noShow / cancelled / redeem / total / raw` 等键一律不碰 ——
 *    它们不是字符串展示位，乱改会把上课结果/核销金额洗坏。
 * 2. **脏 token 集合**：`undefined / null / NaN / [object Object]`（大小写不敏感，
 *    比对前 `trim`）。这是 JS 把异常值 `String()` 化之后落库的全部形态。
 * 3. **两种清洗精度，故意的差别**：
 *    - `date` / `time` 是**结构化取值**，混进脏 token 一定是缺陷 → 把 token 从字符串中间
 *      压掉并合并多余空格（`"2026-11-03 undefined"` → `"2026-11-03"`）；
 *    - `topic` / `teacher` / `couponName` / `voucherCode` 是**自由文本**，只做「整串等于
 *      token 才置空串」的判定，**不做子串替换** —— 人名/课程名里出现 `NaN` 这类子串
 *      不该被裁掉，那是真数据。
 * 4. **JSON null 不算脏值**：本命令清的是**字符串 token**（`"null"` 这几个字符），
 *    JSON 的 `null` 与空串对前端等价，且不是这次要修的那类缺陷。把 null 也改成 `''`
 *    会让命令去重写一大批无关行（`updated_at` 全被刷新），而业务上一无所获。
 * 5. **保持键存在**：清洗后的值写空串而不是删键 —— 前端与 `Lead` 的 `trialCards`
 *    访问器都按「有键、值可能为空」处理，删键会让卡片形状分叉。
 *
 * ## 写库方式
 *
 * - 走 Eloquent：读原始 JSON 后**只改目标字段**，再 `$lead->trial_cards = $cards`
 *   赋值（由 `$casts` 的 `array` 完成 JSON 编码）+ `saveQuietly()`。
 *   **不写裸 SQL 拼 JSON**：手拼字符串在引号/中文/转义上必然翻车，而 cast 是既有的、
 *   被全站写入路径验证过的一层。
 * - 读的是 `getRawOriginal()` 而不是 `$lead->trial_cards`：模型上的 `trialCards`
 *   访问器会在**读**时补 `session`（见该类注释），用访问器读再写回去会顺带把 session
 *   落库 —— 那超出本命令「只清脏值」的职责，不是这次要动的键。
 * - `saveQuietly()`：不触发模型事件、不发通知、不写审计。这是数据修补，不是业务动作。
 * - 只对被清洗过的行 `save()`；没命中的行完全不碰（`updated_at` 不动）。
 *
 * ## 为什么不顺手清 `trial_teacher_user_id`（重要）
 *
 * `trial_teacher` 被清成空串后，`trial_teacher_user_id` 可能仍指向某位老师。本命令**只统计、
 * 不修改**它，原因有三条：
 *
 * 1. **它参与可见性**：`scopeLeadsForUser()` 用 `trial_teacher_user_id`（配套 `trial_teacher`
 *    姓名）判断「这条客资是不是我上过体验课的」。贸然置空，会让某位老师**忽然看不到**这批
 *    客资 —— 而这类「权限莫名消失」的故障，往往要等老师来问才发现。
 * 2. **该不该清取决于历史成因**：`LeadController::withStaffIds()` 若当初就是把同一个脏名
 *    `"undefined"` 解析成了某个 id（理论上不该命中账号，但历史数据的路径不保证），
 *    那这个 id 本身就是脏的、该清；若 id 是更早一次正确写入留下的（老师确实上过这节课），
 *    那它是对的、不该清。**命令分不清，只有人看了样例才能判断**。
 * 3. 因此输出里单独给「脏名 + 残留归属 id」的行数与样例（id + 清洗后的 teacher 值 + 残留 id，
 *    不含手机号姓名），并在 `--apply` 后仍不为 0 时给一句人工处理提示，附带已存在的
 *    `staff:mapping --clear-stale --repair` 作为**可选**手段说明（只覆盖「指向已删除账号」的
 *    悬挂 id，不覆盖「指向仍存在账号」的残留 id）。本命令永远不会调用它。
 *
 * ## 幂等
 *
 * 清洗结果里不再含任何脏 token，因此 `--apply` 之后再跑一次（含 dry-run）命中 0 行。
 * 这一点有测试锁定（第二次 apply 不产生任何写入）。
 *
 * 用法：
 *   php artisan yimai:leads-clean-trial-time              # 预演：只统计 + 打印样例，不写库
 *   php artisan yimai:leads-clean-trial-time --apply      # 真正写库
 *   php artisan yimai:leads-clean-trial-time --samples=0  # 不看样例
 *   php artisan yimai:leads-clean-trial-time --json       # 给脚本用（不含样文明细）
 */
class LeadsCleanTrialTime extends Command
{
    protected $signature = 'yimai:leads-clean-trial-time
        {--apply : 真正写库（缺省只统计与打印样例，一行都不改）}
        {--samples=3 : 打印多少条 before/after 样例}
        {--chunk=500 : 每批读取的行数}
        {--json : 以 JSON 输出统计结果（不含样文明细）}';

    protected $description = '清理 leads.trial_cards / trial_teacher 里历史写入的 undefined/null/NaN/[object Object] 脏值（默认 dry-run）';

    /** 允许清洗的卡片字段（其它键一律不动） */
    private const CARD_FIELDS = ['date', 'time', 'topic', 'teacher', 'couponName', 'voucherCode'];

    /** 结构化字段：允许把出现在字符串中间的脏 token 压掉（日期时间混进 token 一定是缺陷） */
    private const STRUCTURED_FIELDS = ['date', 'time'];

    /** 脏 token，全部小写（比对时统一 mb_strtolower + trim） */
    private const DIRTY_TOKENS = ['undefined', 'null', 'nan', '[object object]'];

    /** 压掉脏 token 的正则：`[object Object]` 中间的空格可能是任意空白 */
    private const STRIP_PATTERN = '/(?:undefined|\[object\s+object\]|nan|null)/i';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $sampleLimit = max(0, (int) $this->option('samples'));
        $chunk = max(1, (int) $this->option('chunk'));

        $scanned = 0;
        $hitRows = 0;
        $cardHitRows = 0;
        $teacherHitRows = 0;
        $written = 0;
        $fieldHits = array_fill_keys(self::CARD_FIELDS, 0);
        $samples = [];
        // 「脏名 + 残留归属 id」：只读统计，本轮不动（见 handle 上方注释与类注释「为什么不顺手清 id」）
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

                    // 原始 JSON（不经 trialCards 访问器，避免把补出来的 session 一起写回库）
                    $rawCards = $lead->getRawOriginal('trial_cards');
                    $cards = is_array($rawCards) ? $rawCards : (json_decode((string) $rawCards, true) ?: []);

                    $cleanCards = [];
                    $changedFields = [];   // 样例用：「cards[0].time」=> [before, after]
                    $cardHitRowsInLead = 0;
                    foreach ($cards as $index => $card) {
                        if (! is_array($card)) {
                            // 非数组条目原样保留：不猜、不删（删掉等于替用户丢数据）
                            $cleanCards[] = $card;

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

                    /*
                     * 「脏名 + 残留归属 id」只读统计（馆主 2026-11 要求：本轮不清，但要可见）。
                     *
                     * 统计面比「命中行」更宽：`trial_teacher` **本来就是空串**但 `trial_teacher_user_id`
                     * 仍 > 0 的行也算 —— 那正是「名字没了、归属还挂着」的形态。
                     * 这些 id 参与 `scopeLeadsForUser()` 的可见性判断，不能由命令替人拍。
                     */
                    $teacherEmptyAfter = $teacherDirty || trim((string) $rawTeacher) === '';
                    $staleOwnerId = (int) ($lead->getRawOriginal('trial_teacher_user_id') ?? 0);
                    if ($teacherEmptyAfter && $staleOwnerId > 0) {
                        $staleOwnerRows++;
                        if (count($staleOwnerSamples) < min(3, max(1, $sampleLimit))) {
                            $staleOwnerSamples[] = [
                                'id' => (int) $lead->id,
                                'trialTeacher' => '',   // 清洗后的值（脏 token 会被清成空串）
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

                    // 只赋值真正需要改的列，再由 cast 编码；saveQuietly 不触发事件/通知/审计
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

        $stats = [
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

        if ((bool) $this->option('json')) {
            // JSON 模式不带样文明细里的自由文本？—— 样例就是给人看的，脚本只要计数，故剔除
            unset($stats['samples']);
            $stats['staleOwnerRows'] = $staleOwnerRows;
            unset($stats['staleOwnerSamples']);
            $this->line((string) json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderReport($stats, $apply);

        return self::SUCCESS;
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

            // 只有结构化字段做子串压缩；自由文本只做上面的整串判定（见类注释 3.）
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

    /**
     * 整串是否等于脏 token（大小写不敏感）。
     *
     * 只认**字符串**：JSON `null` 与空串对前端等价，且不是本次要清的形态，
     * 把它也算脏值会让命令去重写一大批无关行（见类注释 4.）。数组/数字同理不猜。
     */
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

    /**
     * 人读报告。样例只给 lead id + 卡片字段的前后值（**不打印手机号/姓名等 PII**）。
     *
     * @param  array<string,mixed>  $stats
     */
    private function renderReport(array $stats, bool $apply): void
    {
        $this->info('yimai:leads-clean-trial-time —— 清理 trial_cards / trial_teacher 的 undefined 类脏值');
        $this->line($apply
            ? '模式：写入（--apply）；只更新被清洗的行，逐条 saveQuietly，不写审计、不发通知'
            : '模式：预演（dry-run，不写库）；确认数字无误后加 --apply 才真正写入');

        $this->line('扫描 leads：'.$stats['scannedRows'].' 行');
        $this->line(sprintf(
            '命中行数：%d 行（trial_cards：%d 行；trial_teacher：%d 行）',
            $stats['hitRows'],
            $stats['cardHitRows'],
            $stats['teacherHitRows']
        ));

        $parts = [];
        foreach ($stats['fieldHits'] as $field => $count) {
            if ($count > 0) {
                $parts[] = $field.'='.$count;
            }
        }
        $this->line('字段命中次数：'.($parts === [] ? '无' : implode('、', $parts)));

        // 「脏名 + 残留归属 id」：只读统计，永不由本命令修改（见类注释「为什么不顺手清 id」）
        if ($stats['staleOwnerRows'] > 0) {
            $this->line(sprintf(
                '脏名 + 残留归属 id：%d 行（trial_teacher 为空或为脏 token，但 trial_teacher_user_id 仍 > 0）',
                $stats['staleOwnerRows']
            ));
            foreach ($stats['staleOwnerSamples'] as $stale) {
                $this->line(sprintf(
                    '  id=%d  trial_teacher="%s"（清洗后）  trial_teacher_user_id=%d',
                    $stale['id'],
                    $stale['trialTeacher'],
                    $stale['trialTeacherUserId']
                ));
            }
        } else {
            $this->line('脏名 + 残留归属 id：0 行');
        }

        if ($stats['samples'] !== []) {
            $this->line('样例（前 '.count($stats['samples']).' 条，只含卡片字段前后值）：');
            foreach ($stats['samples'] as $sample) {
                foreach ($sample['changed'] as $key => [$before, $after]) {
                    $this->line(sprintf(
                        '  id=%d  %s：%s → %s',
                        $sample['id'],
                        $key,
                        $this->preview($before),
                        $this->preview($after)
                    ));
                }
            }
        }

        if ($apply) {
            $this->info('实际写入行数：'.$stats['writtenRows'].' 行');
            $this->line('复验：再跑一次 php artisan yimai:leads-clean-trial-time，命中行数应为 0（幂等）');
            $this->renderStaleOwnerHint($stats['staleOwnerRows']);

            return;
        }

        $this->warn('预演结束，未写库。加 --apply 执行清洗。');
        $this->renderStaleOwnerHint($stats['staleOwnerRows']);
    }

    /**
     * 残留归属 id 的人工处理提示（只有真的存在才打印）。
     *
     * 为什么给提示而不是直接清：`trial_teacher_user_id` 参与 `scopeLeadsForUser()` 的可见性，
     * 贸然置空会让某位老师忽然看不到这批客资；而「该不该清」取决于当初这个 id 是不是
     * `withStaffIds()` 用同一份**脏名**解析出来的 —— 这要人看一眼样例再决定。
     * `staff:mapping --clear-stale --repair` 只处理「指向已删除账号」的悬挂 id 并回填，
     * 指向仍存在账号的那部分不在它的覆盖范围，所以这里只作可选项说明，绝不自动执行。
     */
    private function renderStaleOwnerHint(int $rows): void
    {
        if ($rows <= 0) {
            return;
        }

        $this->warn(sprintf(
            '注意：以上 %d 行的 trial_teacher_user_id 仍是旧值，本命令不会改动它们 —— 请人工核对样例后单独处理。',
            $rows
        ));
        $this->line('     可选的辅助手段：php artisan staff:mapping --clear-stale --repair（只清「指向已删除账号」的悬挂 id 并回填；');
        $this->line('     指向仍存在账号的残留 id 不在它的覆盖范围，需按业务确认后手工处理）。本命令不会调用它。');
    }

    /** 样例值预览：截断长串，避免一行挤爆终端 */
    private function preview(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (! is_scalar($value)) {
            return is_array($value) ? '[...]' : gettype($value);
        }
        $text = (string) $value;
        $text = mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text;

        return '"'.$text.'"';
    }
}
