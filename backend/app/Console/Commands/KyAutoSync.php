<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Services\KyClient;
use App\Services\KyMemberSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class KyAutoSync extends Command
{
    /** KeepYoga 门店与 venueId 映射（与 KyController::import 同源） */
    private const STORES = ['绿地店' => '1', '东部店' => '4250'];

    protected $signature = 'ky:autosync {--venue= : 仅同步指定门店（绿地店/东部店），缺省双店}';

    protected $description = 'KeepYoga 定时增量同步：双店会员/卡项/出勤，幂等（当天已同步的门店自动跳过）';

    public function handle(): int
    {
        if (! KyClient::configured()) {
            $this->info('KeepYoga 未配置凭据，跳过定时同步');

            return self::SUCCESS;
        }

        $only = (string) $this->option('venue');
        if ($only !== '' && ! isset(self::STORES[$only])) {
            $this->error("无效门店：{$only}");

            return self::FAILURE;
        }

        $results = [];
        foreach (self::STORES as $venue => $venueId) {
            if ($only !== '' && $venue !== $only) {
                continue;
            }
            $results[$venue] = $this->syncVenue($venue, $venueId);
        }

        foreach ($results as $venue => $line) {
            $this->line("[{$venue}] {$line}");
        }

        return collect($results)->contains(fn ($line) => str_starts_with($line, '同步失败'))
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** 单店同步：当天已同步则跳过；全局锁与手动导入互斥；任务留痕与手动导入同口径 */
    private function syncVenue(string $venue, string $venueId): string
    {
        // 幂等：sync_meta 记录了每店最近同步日期，当天已有成功同步（含手动）就不再跑
        $meta = (array) (AppSetting::oldest('id')->first()?->sync_meta ?? []);
        if (($meta[$venue] ?? '') === CarbonImmutable::today()->toDateString()) {
            return '今天已同步，跳过';
        }

        $lock = Cache::lock('ky:import:global', 7200);
        if (! $lock->get()) {
            return '跳过：正在执行其他门店同步（全局锁占用）';
        }

        $batch = 'AUTO-'.now()->format('Ymd-His').'-'.substr((string) mt_rand(1000, 9999), 0, 4);
        $job = SyncJob::create([
            'batch_no' => $batch,
            'run_key' => $batch,
            'display_name' => now()->format('Y-m-d H:i')." {$venue} 定时同步",
            'data_type' => '会员/卡项/出勤多表',
            'venue' => $venue,
            'status' => '进行中',
            'operator' => '系统定时',
            'started_at' => now(),
            'metadata' => ['venueId' => $venueId, 'trigger' => 'schedule'],
        ]);

        try {
            $result = KyMemberSyncService::sync($venue, $venueId, $job);
            $detail = KyMemberSyncService::resultDetail($result);
            $job->update([
                'total_count' => $result['total'],
                'success_count' => $result['created'] + $result['updated'] + $result['unchanged'],
                'fail_count' => $result['skipped'],
                'status' => $result['skipped'] > 0 ? '部分失败' : '成功',
                'finished_at' => now(), 'detail' => $detail, 'error_message' => null,
            ]);
            $this->audit($venue, '定时同步', $job->id, $detail);

            return $detail;
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $job->update(['status' => '失败', 'finished_at' => now(), 'error_message' => $message]);
            Log::channel('system_error')->error('KeepYoga autosync failed', [
                'batch' => $batch, 'venue' => $venue, 'job_id' => $job->id,
                'exception' => $e::class, 'error' => $message,
            ]);
            $this->audit($venue, '定时同步失败', $job->id, $message);

            return '同步失败：'.$message;
        } finally {
            $lock->release();
        }
    }

    /** 定时任务无登录态，直接落审计（operator_id 可空，与人员操作留痕同表） */
    private function audit(string $venue, string $action, int $jobId, string $detail): void
    {
        AuditLog::create([
            'operator_id' => null,
            'operator_name' => '系统定时',
            'operator_role' => '系统',
            'action' => $action,
            'module' => 'KeepYoga同步',
            'target_id' => (string) $jobId,
            'target_label' => '定时批次',
            'venue' => $venue,
            'detail' => mb_substr($detail, 0, 2000),
        ]);
    }
}
