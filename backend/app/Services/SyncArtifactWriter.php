<?php

namespace App\Services;

use App\Models\SyncArtifact;
use App\Models\SyncJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SyncArtifactWriter
{
    private const DISK = 'local';

    private array $artifacts = [];

    private bool $finalized = false;

    private readonly ?SyncJob $syncJob;

    private readonly string $runKey;

    private readonly string $runDate;

    private readonly string $baseDisplayName;

    private readonly array $metadata;

    /**
     * Context keys: sync_job, run_key, display_name, metadata.
     */
    public function __construct(SyncJob|array $context, string $venue)
    {
        $values = $context instanceof SyncJob ? ['sync_job' => $context] : $context;
        $job = $values['sync_job'] ?? null;
        if ($job !== null && ! $job instanceof SyncJob) {
            throw new RuntimeException('sync_job artifact context must be a SyncJob model');
        }

        $this->syncJob = $job;
        $this->runDate = now()->toDateString();
        $candidateKey = (string) ($values['run_key'] ?? $job?->run_key ?? $job?->batch_no ?? '');
        $this->runKey = $this->safeSegment($candidateKey) ?: Str::lower((string) Str::uuid());
        $displayName = trim((string) ($values['display_name'] ?? $job?->display_name ?? $venue));
        $this->baseDisplayName = trim((string) preg_replace('~[\\/:*?"<>|]+~u', '-', $displayName), '. ');
        $this->metadata = (array) ($values['metadata'] ?? []);

        if ($job) {
            $job->fill([
                'run_key' => $job->run_key ?: $this->runKey,
                'display_name' => $job->display_name ?: "{$this->runDate}_{$venue}_KeepYoga同步",
                'started_at' => $job->started_at ?: now(),
                'metadata' => array_replace((array) $job->metadata, $this->metadata),
            ])->save();
        }
    }

    public static function from(SyncJob|array|null $context, string $venue): ?self
    {
        return $context === null ? null : new self($context, $venue);
    }

    public function runKey(): string
    {
        return $this->runKey;
    }

    public function setDateRange(?string $dateFrom, ?string $dateTo, bool $isFull): void
    {
        if (! $this->syncJob) {
            return;
        }

        $this->syncJob->update([
            'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
            'metadata' => array_replace((array) $this->syncJob->metadata, [
                'sync_mode' => $isFull ? 'full' : 'incremental',
            ]),
        ]);
    }

    public function start(
        string $type,
        string $label,
        ?string $dateFrom,
        ?string $dateTo,
        bool $isFull,
        array $metadata = []
    ): void {
        if (isset($this->artifacts[$type])) {
            return;
        }

        $safeType = $this->safeSegment($type);
        if ($safeType === '') {
            throw new RuntimeException('Artifact type must contain an ASCII-safe character');
        }

        $directory = "sync-artifacts/.staging/{$this->runKey}";
        Storage::disk(self::DISK)->makeDirectory($directory);
        $spoolPath = "{$directory}/{$safeType}.jsonl";
        $handle = fopen(Storage::disk(self::DISK)->path($spoolPath), 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to create artifact staging file: {$spoolPath}");
        }

        $this->artifacts[$type] = [
            'safe_type' => $safeType,
            'label' => $label,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'is_full' => $isFull,
            'metadata' => $metadata,
            'spool_path' => $spoolPath,
            'handle' => $handle,
            'columns' => [],
            'row_count' => 0,
        ];
    }

    public function append(string $type, iterable $rows): void
    {
        if (! isset($this->artifacts[$type])) {
            throw new RuntimeException("Artifact must be started before appending: {$type}");
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $column) {
                $this->artifacts[$type]['columns'][(string) $column] = true;
            }
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false || fwrite($this->artifacts[$type]['handle'], $json."\n") === false) {
                throw new RuntimeException("Unable to stage artifact row: {$type}");
            }
            $this->artifacts[$type]['row_count']++;
        }
    }

    /** @return array<int, SyncArtifact> */
    public function finalize(): array
    {
        if ($this->finalized) {
            return [];
        }
        $this->finalized = true;
        $saved = [];

        foreach ($this->artifacts as $artifact) {
            fclose($artifact['handle']);
            $datePath = str_replace('-', '/', $this->runDate);
            $path = "sync-artifacts/{$datePath}/{$this->runKey}/{$artifact['safe_type']}.csv";
            Storage::disk(self::DISK)->makeDirectory(dirname($path));
            $output = fopen(Storage::disk(self::DISK)->path($path), 'wb');
            $input = fopen(Storage::disk(self::DISK)->path($artifact['spool_path']), 'rb');
            if ($output === false || $input === false) {
                throw new RuntimeException("Unable to finalize artifact: {$path}");
            }

            fwrite($output, "\xEF\xBB\xBF");
            $columns = array_keys($artifact['columns']);
            if ($columns !== []) {
                fputcsv($output, $columns, ',', '"', '');
            }
            while (($line = fgets($input)) !== false) {
                $row = json_decode($line, true);
                if (! is_array($row)) {
                    continue;
                }
                fputcsv(
                    $output,
                    array_map(fn (string $column) => $this->csvValue($row[$column] ?? null), $columns),
                    ',',
                    '"',
                    ''
                );
            }
            fclose($input);
            fclose($output);
            Storage::disk(self::DISK)->delete($artifact['spool_path']);

            $absolutePath = Storage::disk(self::DISK)->path($path);
            $range = $artifact['date_from'] && $artifact['date_to']
                ? "_{$artifact['date_from']}_至_{$artifact['date_to']}"
                : '';
            $mode = $artifact['is_full'] ? '全量' : '增量';
            $displayName = "{$this->runDate}_{$this->baseDisplayName}_{$artifact['label']}{$range}_{$mode}.csv";

            $saved[] = SyncArtifact::create([
                'sync_job_id' => $this->syncJob?->getKey(),
                'artifact_type' => $artifact['safe_type'],
                'display_name' => $displayName,
                'disk' => self::DISK,
                'path' => $path,
                'mime' => 'text/csv; charset=UTF-8',
                'row_count' => $artifact['row_count'],
                'size' => filesize($absolutePath),
                'sha256' => hash_file('sha256', $absolutePath),
                'date_from' => $artifact['date_from'],
                'date_to' => $artifact['date_to'],
                'is_full' => $artifact['is_full'],
                'metadata' => array_replace($this->metadata, $artifact['metadata']),
            ]);
        }

        Storage::disk(self::DISK)->deleteDirectory("sync-artifacts/.staging/{$this->runKey}");

        return $saved;
    }

    public function __destruct()
    {
        foreach ($this->artifacts as $artifact) {
            if (is_resource($artifact['handle'])) {
                fclose($artifact['handle']);
            }
        }
        Storage::disk(self::DISK)->deleteDirectory("sync-artifacts/.staging/{$this->runKey}");
    }

    private function safeSegment(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-_');
    }

    private function csvValue(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        // Prevent spreadsheet applications from evaluating upstream text as formulas.
        return preg_match('/^[=+\-@]/', $text) ? "'{$text}" : $text;
    }
}
