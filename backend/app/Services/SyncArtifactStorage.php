<?php

namespace App\Services;

use RuntimeException;

class SyncArtifactStorage
{
    public static function path(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        if (! str_starts_with($relativePath, 'sync-artifacts/') || str_contains($relativePath, '../')) {
            throw new RuntimeException('Invalid sync artifact path');
        }

        $root = app()->environment('testing')
            ? storage_path('framework/testing/disks/local')
            : storage_path('app/private');

        return $root.'/'.$relativePath;
    }

    public static function makeDirectory(string $relativePath): void
    {
        $path = self::path(rtrim($relativePath, '/').'/');
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create artifact directory: {$relativePath}");
        }
    }

    public static function exists(string $relativePath): bool
    {
        return is_file(self::path($relativePath));
    }

    public static function delete(string $relativePath): void
    {
        $path = self::path($relativePath);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException("Unable to delete artifact: {$relativePath}");
        }
    }

    public static function deleteDirectory(string $relativePath): void
    {
        $path = self::path(rtrim($relativePath, '/').'/');
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.'/'.$entry;
            is_dir($child) ? self::deleteDirectory($relativePath.'/'.$entry) : unlink($child);
        }
        rmdir($path);
    }
}
