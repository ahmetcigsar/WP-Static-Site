<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class Archive_Manager
{
    public static function archives(): array
    {
        $archive_directory = Plugin::storage_directory() . '/archives';
        if (! is_dir($archive_directory)) {
            return [];
        }

        $paths = glob($archive_directory . '/*.zip');
        if (! is_array($paths)) {
            return [];
        }

        $archives = [];
        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $job_id = pathinfo($path, PATHINFO_FILENAME);
            $manifest = self::manifest_from_archive($path);
            $created_at = self::created_timestamp($path, $manifest);

            $archives[] = [
                'id' => $job_id,
                'job_id' => is_string($manifest['job_id'] ?? null) && $manifest['job_id'] !== ''
                    ? $manifest['job_id']
                    : $job_id,
                'url_count' => isset($manifest['url_count']) ? absint($manifest['url_count']) : null,
                'created_at' => $created_at,
                'path' => $path,
                'build_job_id' => $job_id,
            ];
        }

        usort($archives, static function (array $left, array $right): int {
            $timestamp_order = $right['created_at'] <=> $left['created_at'];
            return $timestamp_order !== 0 ? $timestamp_order : strcmp($right['job_id'], $left['job_id']);
        });

        return $archives;
    }

    public static function latest(): ?array
    {
        $archives = self::archives();
        return $archives[0] ?? null;
    }

    public static function find(string $id): ?array
    {
        foreach (self::archives() as $archive) {
            if (hash_equals((string) $archive['id'], $id)) {
                return $archive;
            }
        }

        return null;
    }

    public static function selected(array $ids): array
    {
        $requested = array_fill_keys(array_map('strval', $ids), true);
        return array_values(array_filter(
            self::archives(),
            static fn (array $archive): bool => isset($requested[(string) $archive['id']])
        ));
    }

    public static function prune(int $keep): array
    {
        $keep = max(1, $keep);
        $archives = self::archives();
        return self::delete(array_column(array_slice($archives, $keep), 'id'));
    }

    public static function delete(array $ids): array
    {
        $deleted = 0;
        $failed = 0;

        foreach (self::selected($ids) as $archive) {
            if (! unlink((string) $archive['path'])) {
                ++$failed;
                continue;
            }

            ++$deleted;
            self::delete_build_directory((string) $archive['build_job_id']);
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    public static function delete_old_archives(): array
    {
        return self::prune(1);
    }

    public static function create_bundle(array $ids): string
    {
        $archives = self::selected($ids);
        if ($archives === []) {
            throw new RuntimeException('İndirilecek ZIP dosyası bulunamadı.');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive eklentisi kurulu değil.');
        }

        $bundle_path = tempnam(get_temp_dir(), 'ragstat-');
        if (! is_string($bundle_path) || $bundle_path === '') {
            throw new RuntimeException('Geçici indirme paketi oluşturulamadı.');
        }

        $zip = new ZipArchive();
        if ($zip->open($bundle_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            unlink($bundle_path);
            throw new RuntimeException('Toplu indirme paketi oluşturulamadı.');
        }

        foreach ($archives as $archive) {
            $zip->addFile((string) $archive['path'], basename((string) $archive['path']));
        }
        $zip->close();

        return $bundle_path;
    }

    private static function manifest_from_archive(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $contents = $zip->getFromName('ragnus-static-manifest.json');
        $zip->close();
        if (! is_string($contents)) {
            return [];
        }

        $manifest = json_decode($contents, true);
        return is_array($manifest) ? $manifest : [];
    }

    private static function created_timestamp(string $path, array $manifest): int
    {
        foreach (['finished_at', 'started_at'] as $field) {
            if (! is_string($manifest[$field] ?? null)) {
                continue;
            }

            $timestamp = strtotime($manifest[$field]);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $modified_at = filemtime($path);
        return $modified_at === false ? 0 : $modified_at;
    }

    private static function delete_build_directory(string $job_id): void
    {
        $safe_job_id = sanitize_file_name($job_id);
        if ($safe_job_id === '' || $safe_job_id !== $job_id) {
            return;
        }

        $directory = Plugin::storage_directory() . '/builds/' . $safe_job_id;
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
