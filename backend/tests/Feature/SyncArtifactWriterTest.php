<?php

namespace Tests\Feature;

use App\Models\SyncJob;
use App\Models\User;
use App\Services\SyncArtifactWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncArtifactWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_streams_raw_rows_to_a_private_csv_and_persists_metadata(): void
    {
        Storage::fake('local');
        $job = SyncJob::create([
            'batch_no' => 'IMP-20260908-001',
            'data_type' => '会员/卡项/出勤多表',
            'venue' => '绿地店',
        ]);

        $writer = new SyncArtifactWriter($job, '绿地店');
        $writer->setDateRange('2026-09-01', '2026-09-08', false);
        $writer->start('private-bookings', '私教预约表', '2026-09-01', '2026-09-08', false);
        $writer->append('private-bookings', [
            ['member_name' => '会员甲', 'course' => '普拉提'],
            ['member_name' => '会员乙', 'course' => '瑜伽', 'remark' => '首次到店'],
        ]);

        $artifact = $writer->finalize()[0];
        $job->refresh();

        $this->assertSame($job->id, $artifact->sync_job_id);
        $this->assertSame(2, $artifact->row_count);
        $this->assertFalse($artifact->is_full);
        $this->assertStringContainsString('绿地店_私教预约表', $artifact->display_name);
        $this->assertStringNotContainsString('绿地店', $artifact->path);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-08'], $job->date_range);
        $this->assertSame('incremental', $job->metadata['sync_mode']);
        $this->assertCount(1, $job->artifacts);
        Storage::disk('local')->assertExists($artifact->path);

        $compressed = Storage::disk('local')->get($artifact->path);
        $contents = gzdecode($compressed);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertStringContainsString('会员甲', $contents);
        $this->assertStringContainsString('remark', $contents);
        $this->assertStringEndsWith('.csv', $artifact->display_name);
        $this->assertStringEndsWith('.csv.gz', $artifact->path);
        $this->assertSame(strlen($contents), $artifact->size);
        $this->assertSame(hash('sha256', $contents), $artifact->sha256);

        Sanctum::actingAs(User::factory()->create(['role' => 'R_SUPER', 'status' => '启用']));
        $download = $this->get("/api/sync-artifacts/{$artifact->id}/download");
        $download->assertOk();
        $disposition = (string) $download->headers->get('content-disposition');
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertStringContainsString(rawurlencode($artifact->display_name), $disposition);
        $this->assertSame($contents, $download->streamedContent());
    }

    public function test_array_context_can_persist_an_unassociated_artifact(): void
    {
        Storage::fake('local');
        $writer = new SyncArtifactWriter([
            'run_key' => 'manual/run 001',
            'display_name' => '东部店手动同步',
            'metadata' => ['source' => 'test'],
        ], '东部店');
        $writer->start('members', '会员基础表', '2026-09-08', '2026-09-08', true);
        $writer->append('members', [['姓名' => '张三']]);

        $artifact = $writer->finalize()[0];

        $this->assertNull($artifact->sync_job_id);
        $this->assertStringContainsString('/manual-run-001/', $artifact->path);
        $this->assertTrue($artifact->is_full);
        $this->assertSame(['source' => 'test'], $artifact->metadata);
    }
}
