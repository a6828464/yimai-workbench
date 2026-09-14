<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Lead;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\KyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

/**
 * 同步留痕：手动同步与系统定时同步都要在「系统日志 → 运行日志」留下记录。
 * 定时任务此前只在失败时写 error 日志，跳过与成功都不留痕，导致「定时同步到底跑没跑」无法从后台判断。
 */
class SyncRunLogTest extends TestCase
{
    use RefreshDatabase;

    /** 把 runtime 频道替换成内存 handler，再读回写入的记录（logSyncRun 固定写 runtime 频道） */
    private function captureRuntimeLog(): TestHandler
    {
        $handler = new TestHandler;
        config(['logging.channels.runtime' => [
            'driver' => 'custom',
            'via' => fn () => new Logger('runtime', [$handler]),
        ]]);
        Log::forgetChannel('runtime');

        return $handler;
    }

    public function test_autosync_logs_skip_reason_when_today_already_synced(): void
    {
        config(['services.ky.phone' => '13800000000', 'services.ky.password' => 'secret']);
        // 当天已同步（例如白天手动跑过）：定时任务幂等跳过，但必须在日志里说清是「跳过」而不是「没执行」
        AppSetting::create(['sync_meta' => ['绿地店' => now()->toDateString(), '东部店' => now()->toDateString()]]);

        $handler = $this->captureRuntimeLog();
        $this->artisan('ky:autosync')->assertSuccessful();

        $records = collect($handler->getRecords());
        $this->assertTrue(
            $records->contains(fn ($r) => $r->message === 'KeepYoga 定时同步跳过'),
            '定时同步跳过时应在运行日志留痕'
        );
        $skip = $records->first(fn ($r) => $r->message === 'KeepYoga 定时同步跳过');
        $this->assertSame('系统定时同步', $skip->context['trigger']);
        $this->assertStringContainsString('当天已同步', $skip->context['reason']);
    }

    public function test_autosync_logs_when_credentials_missing(): void
    {
        config(['services.ky.phone' => '', 'services.ky.password' => '']);
        $this->assertFalse(KyClient::configured());

        $handler = $this->captureRuntimeLog();
        $this->artisan('ky:autosync')->assertSuccessful();

        $records = collect($handler->getRecords());
        $this->assertTrue(
            $records->contains(fn ($r) => $r->message === 'KeepYoga 定时同步跳过'),
            '未配置凭据导致定时同步不执行时也应留痕'
        );
    }

    public function test_manual_import_writes_sync_run_log_with_trigger_and_venue(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'sync-log', 'name' => '店长', 'role' => 'R_SUPER']));

        $handler = $this->captureRuntimeLog();

        // 直接驱动留痕函数，验证手动同步写入的字段（同步本体由既有同步测试覆盖）
        $job = SyncJob::create([
            'batch_no' => 'IMP-TEST', 'run_key' => 'IMP-TEST',
            'display_name' => '2026-09-14 10:00 绿地店 增量同步',
            'data_type' => '会员/卡项/出勤多表', 'venue' => '绿地店',
            'status' => '成功', 'operator' => '店长', 'started_at' => now(), 'finished_at' => now(),
            'detail' => '导入落库：新增 1 · 更新 2',
        ]);
        logSyncRun($job, '手动增量同步');

        $record = collect($handler->getRecords())->first(fn ($r) => $r->message === 'KeepYoga 同步执行');
        $this->assertNotNull($record, '手动同步应写入运行日志');
        $this->assertSame('手动增量同步', $record->context['trigger']);
        $this->assertSame('绿地店', $record->context['venue']);
        $this->assertSame('成功', $record->context['status']);
        $this->assertSame('IMP-TEST', $record->context['batch']);
    }

    public function test_sync_run_log_marks_failure_level(): void
    {
        $handler = $this->captureRuntimeLog();
        $job = SyncJob::create([
            'batch_no' => 'AUTO-TEST', 'run_key' => 'AUTO-TEST',
            'display_name' => '2026-09-14 05:30 东部店 定时同步',
            'data_type' => '会员/卡项/出勤多表', 'venue' => '东部店',
            'status' => '失败', 'operator' => '系统定时', 'started_at' => now(), 'finished_at' => now(),
            'error_message' => '未读取到会员基础表',
        ]);
        logSyncRun($job, '系统定时同步');

        $record = collect($handler->getRecords())->first(fn ($r) => $r->message === 'KeepYoga 同步执行');
        $this->assertNotNull($record);
        $this->assertSame('系统定时同步', $record->context['trigger']);
        $this->assertStringContainsString('未读取到会员基础表', $record->context['error']);
        $this->assertTrue($record->level->getName() === 'WARNING' || $record->level->getName() === 'Warning');
    }

    public function test_online_scope_helpers_are_used_by_lead_registration(): void
    {
        // 留资登记（手动）与线上口径判定共用同一规则，避免两端各写一套正则
        $online = Lead::create(['lead_date' => '2026-09-14', 'name' => '线上', 'venue' => '绿地店', 'source' => '美团', 'status' => '新留资']);
        $offline = Lead::create(['lead_date' => '2026-09-14', 'name' => '自然', 'venue' => '绿地店', 'source' => '自然到店', 'status' => '新留资']);

        $this->assertTrue(isOnlineLead($online));
        $this->assertFalse(isOnlineLead($offline));
    }
}
