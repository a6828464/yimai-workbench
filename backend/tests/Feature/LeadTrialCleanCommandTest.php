<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `yimai:leads-clean-trial-time` 的回归测试（t8）。
 *
 * 被清的是历史脏值：小程序旧 picker 把 `"undefined"` 拼进了体验课卡片的时间
 * （`"2026-11-03 undefined"`），并且经由 `LeadController::withStaffIds()` 的镜像
 * 污染了顶层 `trial_teacher`（进而影响可见性与课时统计）。前端已修，这份数据要单独清。
 *
 * 本文件守四件事：
 *  ① 默认预演：只统计、打印样例，**一个字段都不改**；
 *  ② `--apply`：`time` 压成 `"2026-11-03"`、纯脏 token 的字段置空串、`trial_teacher` 置空；
 *     同时**其它键（session/attended/total/platform）与自由文本里的子串一律不动**；
 *  ③ 幂等：再 `--apply` 写入 0 行、`updated_at` 不再变化，预演也命中 0 行；
 *  ④ 没命中的行完全不碰（不是「全表刷新一遍 updated_at」）。
 */
class LeadTrialCleanCommandTest extends TestCase
{
    use RefreshDatabase;

    /** ① 预演：统计准确，但库里一个字节都不变。 */
    public function test_dry_run_reports_hits_without_writing(): void
    {
        $lead = $this->dirtyLead();
        $updatedBefore = $this->rawUpdatedAt($lead->id);

        $this->artisan('yimai:leads-clean-trial-time')
            ->expectsOutputToContain('预演（dry-run，不写库）')
            ->expectsOutputToContain('命中行数：1 行（trial_cards：1 行；trial_teacher：1 行）')
            ->expectsOutputToContain('字段命中次数：date=1、time=2、teacher=1、couponName=1、voucherCode=1')
            ->expectsOutputToContain('预演结束，未写库')
            ->assertSuccessful();

        $fresh = $lead->fresh();
        $this->assertSame('2026-11-03 undefined', $fresh->trial_cards[0]['time'], '预演不得改库');
        $this->assertSame('undefined', $fresh->trial_cards[0]['teacher']);
        $this->assertSame('undefined', $fresh->trial_teacher);
        $this->assertSame($updatedBefore, $this->rawUpdatedAt($lead->id), '预演不应刷新 updated_at');
    }

    /** ② 写入：脏 token 被清、混在时间里的 token 被压掉，其它键与自由文本一字不动。 */
    public function test_apply_cleans_dirty_tokens_and_keeps_other_keys(): void
    {
        $lead = $this->dirtyLead();

        $this->artisan('yimai:leads-clean-trial-time', ['--apply' => true])
            ->expectsOutputToContain('实际写入行数：1 行')
            ->assertSuccessful();

        $cards = $lead->fresh()->trial_cards;

        // 第 1 节：六个字段里五处脏值
        $this->assertSame('2026-11-03', $cards[0]['time'], '混在时间里的 undefined 必须压掉');
        $this->assertSame('2026-11-03', $cards[0]['date']);
        $this->assertSame('', $cards[0]['teacher'], '整串是脏 token → 空串（保持键存在）');
        $this->assertSame('', $cards[0]['couponName']);
        $this->assertSame('', $cards[0]['voucherCode']);
        $this->assertSame('体验课第一节', $cards[0]['topic'], '干净字段不动');
        $this->assertArrayHasKey('teacher', $cards[0], '键必须保留，不能删');

        // 其它键：上课结果 / 节次 / 总次数 / 平台，一个都不许动
        $this->assertSame(1, $cards[0]['session']);
        $this->assertTrue((bool) $cards[0]['attended']);
        $this->assertSame(10, $cards[0]['total']);
        $this->assertSame('美团', $cards[0]['platform']);

        // 第 2 节：本来就干净，一字不动
        $this->assertSame('2026-11-05 10:00', $cards[1]['time']);
        $this->assertSame('小王', $cards[1]['teacher']);
        $this->assertArrayNotHasKey('couponName', $cards[1], '键本来就没有 → 不补键');

        // 第 3 节：结构化字段开头的 token 也要压掉（含多余空格合并）
        $this->assertSame('2026-11-06 09:30', $cards[2]['time']);
        // 自由文本里的 token 子串不当脏值（人名/课程名里出现 NaN 是真数据）
        $this->assertSame('NaN 风格课', $cards[2]['topic'], 'topic 不做子串替换');

        // 顶层镜像列
        $this->assertSame('', $lead->fresh()->trial_teacher, 'trial_teacher 是第一节 teacher 的镜像，脏值要一起清');
    }

    /** ③ 幂等：再 apply 写入 0 行、updated_at 不再变；预演命中 0 行。 */
    public function test_apply_is_idempotent(): void
    {
        $lead = $this->dirtyLead();

        $this->artisan('yimai:leads-clean-trial-time', ['--apply' => true])->assertSuccessful();
        $updatedAfterFirst = $this->rawUpdatedAt($lead->id);

        $this->artisan('yimai:leads-clean-trial-time', ['--apply' => true])
            ->expectsOutputToContain('命中行数：0 行')
            ->expectsOutputToContain('实际写入行数：0 行')
            ->assertSuccessful();

        $this->assertSame($updatedAfterFirst, $this->rawUpdatedAt($lead->id), '幂等：第二次不该再写');

        $this->artisan('yimai:leads-clean-trial-time')
            ->expectsOutputToContain('命中行数：0 行')
            ->assertSuccessful();
    }

    /** ④ 干净的行不碰：apply 后写入 0 行，updated_at 不变。 */
    public function test_clean_rows_are_not_rewritten(): void
    {
        $lead = Lead::create([
            'lead_date' => now()->toDateString(), 'name' => '干净客户', 'phone' => '13900009001',
            'source' => '大众点评', 'venue' => '绿地店', 'service_teacher' => '', 'status' => '已约体验',
            'trial_cards' => [
                ['session' => 1, 'time' => now()->format('Y-m-d 10:00'), 'topic' => '体验课', 'teacher' => '老师A'],
            ],
        ]);
        $updatedBefore = $this->rawUpdatedAt($lead->id);

        $this->artisan('yimai:leads-clean-trial-time', ['--apply' => true])
            ->expectsOutputToContain('命中行数：0 行')
            ->expectsOutputToContain('实际写入行数：0 行')
            ->assertSuccessful();

        $this->assertSame($updatedBefore, $this->rawUpdatedAt($lead->id));
    }

    /**
     * `--json` 给脚本用：输出必须是可解析的 JSON，且只有统计、不含样文明细。
     *
     * 这里刻意不用 `expectsOutputToContain` 逐条断言：`line()` 一次写出的多行 JSON
     * 在 PendingCommand 眼里是**同一行**，一条输出只能满足一条子串期望，
     * 于是第二条断言必然失败（踩过）。直接解析输出更贴近脚本的真实用法。
     */
    public function test_json_output_carries_counts_only(): void
    {
        $this->dirtyLead();

        // 走 Artisan 门面而不是 $this->artisan()：后者的输出走 PendingCommand 自己的缓冲，
        // Artisan::output() 读不到（会得到空串）。门面这条正是脚本调用的真实路径。
        $exitCode = Artisan::call('yimai:leads-clean-trial-time', ['--json' => true]);
        $this->assertSame(0, $exitCode);

        $json = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($json, '--json 必须输出可解析的 JSON');
        $this->assertSame('dry-run', $json['mode']);
        $this->assertSame(1, $json['hitRows']);
        $this->assertSame(1, $json['cardHitRows']);
        $this->assertSame(1, $json['teacherHitRows']);
        $this->assertSame(0, $json['writtenRows'], '预演不得写库');
        $this->assertSame(
            ['date' => 1, 'time' => 2, 'topic' => 0, 'teacher' => 1, 'couponName' => 1, 'voucherCode' => 1],
            $json['fieldHits']
        );
        $this->assertArrayNotHasKey('samples', $json, 'JSON 模式不带样文明细（含自由文本，脚本不需要）');
    }

    /**
     * ⑤「脏名 + 残留归属 id」只读统计（t9 附加项，馆主口述要求）。
     *
     * `trial_teacher` 被清成空串后，`trial_teacher_user_id` 可能仍指向某位老师。它参与
     * `scopeLeadsForUser()` 的可见性，本轮**只报不改** —— 命令不能替人判断「这个 id 当初是
     * 脏名解析出来的，还是老师真上过课留下的」。
     */
    public function test_reports_stale_owner_id_without_touching_it(): void
    {
        $lead = $this->dirtyLead();
        // 模拟「脏名 + 残留归属 id」：名字是 undefined，归属 id 仍指向某位老师
        $lead->forceFill(['trial_teacher_user_id' => 7])->saveQuietly();

        $this->artisan('yimai:leads-clean-trial-time')
            ->expectsOutputToContain('脏名 + 残留归属 id：1 行')
            ->expectsOutputToContain('trial_teacher_user_id=7')
            ->expectsOutputToContain('本命令不会改动它们')
            ->assertSuccessful();

        $this->assertSame(7, (int) $lead->fresh()->trial_teacher_user_id, '预演不得动 id');
        $this->assertSame('undefined', $lead->fresh()->trial_teacher, '预演也不得动名字');

        $this->artisan('yimai:leads-clean-trial-time', ['--apply' => true])->assertSuccessful();

        $fresh = $lead->fresh();
        $this->assertSame('', $fresh->trial_teacher, '脏名被清成空串');
        $this->assertSame(7, (int) $fresh->trial_teacher_user_id, '归属 id 本轮不动（需人工核对样例后单独处理）');
    }

    // ------------------------------------------------------------- 数据准备

    /**
     * 三条卡片：① 全脏 ② 干净 ③ 只有结构化字段混着 token + 自由文本带 token 子串。
     * 顶层 `trial_teacher` 是被镜像污染的 `"undefined"`。
     */
    private function dirtyLead(): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => '脏值客户',
            'phone' => '13900009000',
            'source' => '大众点评',
            'venue' => '绿地店',
            'service_teacher' => '',
            'status' => '已约体验',
            'trial_teacher' => 'undefined',
            'trial_cards' => [
                [
                    'session' => 1,
                    'date' => '2026-11-03 undefined',
                    'time' => '2026-11-03 undefined',
                    'topic' => '体验课第一节',
                    'teacher' => 'undefined',
                    'couponName' => 'NaN',
                    'voucherCode' => '[object Object]',
                    'attended' => true,
                    'total' => 10,
                    'remaining' => 7,
                    'platform' => '美团',
                ],
                [
                    'session' => 2,
                    'time' => '2026-11-05 10:00',
                    'topic' => '体验课第二节',
                    'teacher' => '小王',
                ],
                [
                    'session' => 3,
                    'time' => 'undefined 2026-11-06 09:30',
                    'topic' => 'NaN 风格课',
                    'teacher' => '老师C',
                ],
            ],
        ]);
    }

    /** 直接读库里的 updated_at 原文（绕过模型，能精确看出「有没有被写过」） */
    private function rawUpdatedAt(int $id): ?string
    {
        return DB::table('leads')->where('id', $id)->value('updated_at');
    }
}
