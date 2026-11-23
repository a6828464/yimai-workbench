<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /system/clean-trial-time`（t12 / v3.20.0）。
 *
 * 馆主没有 SSH 通道，所以「你直接去执行」必须变成后台能点的按钮。本接口与
 * `yimai:leads-clean-trial-time` **共用同一个 service**（`LeadTrialTimeCleaner`），
 * 本文件只需守住三件事：
 *  ① 超管专用（其它角色一律 403）；
 *  ② 缺省即 dry-run（**漏传必须是安全方向**，绝不能默认写库）；
 *  ③ 真正写库时留审计、可重复执行（幂等）。
 */
class SystemCleanTrialTimeTest extends TestCase
{
    use RefreshDatabase;

    /** ① 缺省 dry-run：能统计出命中行，但库一个字节都不动。 */
    public function test_default_is_dry_run_and_writes_nothing(): void
    {
        $lead = $this->dirtyLead();
        Sanctum::actingAs($this->super());

        $data = $this->postJson('/api/system/clean-trial-time')
            ->assertOk()
            ->json('data');

        $this->assertSame('dry-run', $data['mode'], '漏传 dryRun 必须按 dry-run 处理');
        $this->assertSame(1, $data['hitRows']);
        $this->assertSame(0, $data['writtenRows']);
        $this->assertSame(1, $data['fieldHits']['time']);
        $this->assertNotEmpty($data['samples'], 'dry-run 要给样例，否则员工不敢点');

        $fresh = $lead->fresh();
        $this->assertSame('2026-11-03 undefined', $fresh->trial_cards[0]['time'], 'dry-run 不得写库');
        $this->assertSame('undefined', $fresh->trial_teacher);
    }

    /** ② 超管专用：店长/老师一律 403，未登录 401。 */
    public function test_only_super_can_run_it(): void
    {
        $this->dirtyLead();

        Sanctum::actingAs($this->user('绿地店长', 'mgr-clean', 'R_MANAGER', '绿地店'));
        $this->postJson('/api/system/clean-trial-time', ['dryRun' => true])->assertForbidden();

        Sanctum::actingAs($this->user('王教练', 'coach-clean', 'R_TEACHER', '绿地店'));
        $this->postJson('/api/system/clean-trial-time', ['dryRun' => true])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/system/clean-trial-time', ['dryRun' => true])->assertUnauthorized();
    }

    /** ③ 真正落库：返回写入行数、清掉脏值、留审计；再跑一次 0 行（幂等）。 */
    public function test_apply_writes_and_is_idempotent(): void
    {
        $lead = $this->dirtyLead();
        Sanctum::actingAs($this->super());

        $first = $this->postJson('/api/system/clean-trial-time', ['dryRun' => false])
            ->assertOk()->json('data');
        $this->assertSame('apply', $first['mode']);
        $this->assertSame(1, $first['writtenRows']);

        $fresh = $lead->fresh();
        $this->assertSame('2026-11-03', $fresh->trial_cards[0]['time'], '混在时间里的脏 token 被压掉');
        $this->assertSame('', $fresh->trial_cards[0]['teacher']);
        $this->assertSame('', $fresh->trial_teacher, 'trial_teacher 镜像列同批清洗');
        // 其它键不动
        $this->assertSame(1, $fresh->trial_cards[0]['session']);
        $this->assertSame(10, $fresh->trial_cards[0]['total']);

        // 写库要留痕：谁在什么时候清了多少行
        $log = AuditLog::where('action', '数据清理')->where('module', '系统')->first();
        $this->assertNotNull($log, 'apply 必须写审计');
        $this->assertStringContainsString('写入 1 行', (string) $log->detail);

        // 幂等：再点一次 0 行
        $second = $this->postJson('/api/system/clean-trial-time', ['dryRun' => false])
            ->assertOk()->json('data');
        $this->assertSame(0, $second['hitRows']);
        $this->assertSame(0, $second['writtenRows']);
    }

    /** ④ dryRun 必须是布尔：脏参数不猜。 */
    public function test_dry_run_flag_is_validated(): void
    {
        Sanctum::actingAs($this->super());

        $this->postJson('/api/system/clean-trial-time', ['dryRun' => 'maybe'])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------- 数据准备

    private function dirtyLead(): Lead
    {
        return Lead::create([
            'lead_date' => now()->toDateString(),
            'name' => '脏值客户',
            'phone' => '13900009999',
            'source' => '大众点评',
            'venue' => '绿地店',
            'service_teacher' => '',
            'status' => '已约体验',
            'trial_teacher' => 'undefined',
            'trial_cards' => [[
                'session' => 1,
                'time' => '2026-11-03 undefined',
                'teacher' => 'undefined',
                'topic' => '体验课',
                'total' => 10,
            ]],
        ]);
    }

    private function super(): User
    {
        return $this->user('超管', 'sup-clean', 'R_SUPER', null);
    }

    private function user(string $name, string $username, string $role, ?string $venue): User
    {
        return User::factory()->create([
            'name' => $name, 'username' => $username, 'role' => $role, 'roles' => [$role],
            'venue' => $venue, 'venues' => $venue === null ? [] : [$venue], 'status' => '启用',
        ]);
    }
}
