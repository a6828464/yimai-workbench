<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\SyncArtifact;
use App\Models\SyncJob;
use App\Services\SyncArtifactStorage;
use App\Services\SyncArtifactWriter;
use Illuminate\Http\Request;
use RuntimeException;

final class SyncJobController extends Controller
{
    /** GET /sync-jobs */
    public function index(Request $r)
    {
        requireSuper($r);
        $q = SyncJob::query();
        if ($status = $r->query('status')) {
            $q->where('status', $status);
        }
        if ($type = $r->query('dataType')) {
            $q->where('data_type', 'like', "%{$type}%");
        }
        $current = max(1, (int) $r->query('current', 1));
        $size = min(5000, max(1, (int) $r->query('size', 20)));
        $total = (clone $q)->count();
        $pageRows = $q->orderByDesc('id')->forPage($current, $size)->get();
        // 会籍顾问回填（显示层）：当前页内顾问为空的会员，按手机号取留资服务老师补齐。
        // 一次索引查询取代前端 size:5000 全量拉留资再匹配；不影响上方 teacher 范围 SQL（与 canAccessCustomer 同口径）。
        $phonesToFill = $pageRows
            ->filter(fn ($c) => trim((string) $c->consultant) === '' && (string) $c->phone !== '')
            ->pluck('phone')->unique()->values();
        if ($phonesToFill->isNotEmpty()) {
            $teacherByPhone = Lead::whereIn('phone', $phonesToFill)
                ->where('service_teacher', '!=', '')
                ->orderByDesc('id')
                ->get(['phone', 'service_teacher'])
                ->groupBy('phone')
                ->map(fn ($g) => $g->first()->service_teacher);
            $pageRows = $pageRows->map(function ($c) use ($teacherByPhone) {
                if (trim((string) $c->consultant) === '' && $teacherByPhone->has($c->phone)) {
                    $c->consultant = $teacherByPhone->get($c->phone);
                }

                return $c;
            });
        }
        $rows = $pageRows->map(fn ($x) => camel($x));

        return ok(['records' => $rows, 'total' => $total, 'current' => $current, 'size' => $size]);
    }

    /** GET /sync-jobs/{job} */
    public function show(Request $r, SyncJob $job)
    {
        requireSuper($r);
        $job->loadCount('artifacts');

        return ok(syncJobPayload($job));
    }

    /** GET /sync-jobs/{job}/artifacts */
    public function artifacts(Request $r, SyncJob $job)
    {
        requireSuper($r);

        return ok($job->artifacts()->orderBy('id')->get()->map(fn (SyncArtifact $artifact) => syncArtifactPayload($artifact)));
    }

    /** GET /sync-artifacts/{artifact}/download */
    public function downloadArtifact(Request $r, SyncArtifact $artifact)
    {
        requireSuper($r);
        abort_unless($artifact->disk === 'local' && SyncArtifactStorage::exists($artifact->path), 404, '历史导入表格不存在');
        audit($r, '下载', 'KeepYoga同步', $artifact->id, $artifact->display_name, $artifact->syncJob?->venue ?? '双店', '下载历史导入快照');

        if (str_ends_with($artifact->path, '.gz')) {
            $absolutePath = SyncArtifactStorage::path($artifact->path);
            $meta = SyncArtifactWriter::gzipContentMeta($absolutePath);
            abort_unless($meta['size'] === $artifact->size && hash_equals($artifact->sha256, $meta['sha256']), 409, '历史导入表格校验失败');
            $stream = gzopen($absolutePath, 'rb');
            abort_unless($stream !== false, 500, '历史导入表格读取失败');

            return response()->streamDownload(function () use ($stream) {
                try {
                    while (! gzeof($stream)) {
                        $chunk = gzread($stream, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException('历史导入表格解压失败');
                        }
                        echo $chunk;
                    }
                } finally {
                    gzclose($stream);
                }
            }, $artifact->display_name, [
                'Content-Type' => $artifact->mime,
                'Content-Length' => (string) $artifact->size,
            ]);
        }

        return response()->download(SyncArtifactStorage::path($artifact->path), $artifact->display_name, ['Content-Type' => $artifact->mime]);
    }
}
