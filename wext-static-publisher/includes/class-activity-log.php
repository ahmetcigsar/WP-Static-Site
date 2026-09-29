<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

final class Activity_Log
{
    private const PAGE_SIZE = 50;

    public static function reset(string $job_id): void
    {
        Export_Storage::delete_directory(self::directory());
        Export_Storage::write(self::directory(), 'latest-meta.json', (string) wp_json_encode([
            'job_id' => sanitize_file_name($job_id),
            'started_at' => gmdate('c'),
        ]));
    }

    public static function append(
        string $job_id,
        string $level,
        string $message,
        string $source_url = '',
        string $static_path = '',
        int $status_code = 0
    ): void
    {
        $entry = wp_json_encode([
            'job_id' => sanitize_file_name($job_id),
            'time' => gmdate('c'),
            'level' => sanitize_key($level),
            'message' => $message,
            'source_url' => esc_url_raw($source_url),
            'static_path' => ltrim($static_path, '/'),
            'status_code' => $status_code >= 100 && $status_code <= 599 ? $status_code : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_string($entry)) {
            Export_Storage::write(self::directory() . '/entries', sprintf('%020.0f', microtime(true) * 1000000) . '-' . wp_generate_uuid4() . '.json', $entry);
        }
    }

    public static function page(int $page, string $search = ''): array
    {
        $entries = [];
        $job_total = 0;
        $search = trim($search);
        $paths = array_keys(Export_Storage::files(self::directory() . '/entries'));
        foreach (array_reverse($paths) as $path) {
            $entry = json_decode(Export_Storage::read(self::directory() . '/entries/' . $path), true);
            if (is_array($entry)) {
                $job_total++;
                $entry = self::normalise_entry($entry);
                if ($search === '' || self::matches_search($entry, $search)) {
                    $entries[] = $entry;
                }
            }
        }

        $total = count($entries);
        $total_pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $current_page = max(1, min($total_pages, $page));

        return [
            'entries' => array_slice($entries, ($current_page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'job_id' => self::latest_job_id(),
            'page' => $current_page,
            'page_size' => self::PAGE_SIZE,
            'total' => $total,
            'job_total' => $job_total,
            'total_pages' => $total_pages,
            'search' => $search,
        ];
    }

    private static function matches_search(array $entry, string $search): bool
    {
        $haystack = implode("\n", [
            (string) ($entry['source_url'] ?? ''),
            (string) ($entry['static_path'] ?? ''),
            (string) ($entry['message'] ?? ''),
            (string) ($entry['level'] ?? ''),
            (string) ($entry['time'] ?? ''),
            (string) ($entry['job_id'] ?? ''),
            (string) ($entry['status_code'] ?? ''),
        ]);

        return function_exists('mb_stripos')
            ? mb_stripos($haystack, $search, 0, 'UTF-8') !== false
            : stripos($haystack, $search) !== false;
    }

    private static function normalise_entry(array $entry): array
    {
        $entry['source_url'] = is_string($entry['source_url'] ?? null) ? $entry['source_url'] : '';
        $entry['static_path'] = is_string($entry['static_path'] ?? null) ? ltrim($entry['static_path'], '/') : '';
        $entry['status_code'] = is_numeric($entry['status_code'] ?? null)
            && (int) $entry['status_code'] >= 100
            && (int) $entry['status_code'] <= 599
                ? (int) $entry['status_code']
                : null;

        if ($entry['source_url'] === '' && $entry['static_path'] === '' && is_string($entry['message'] ?? null)) {
            $parts = explode(' -> ', $entry['message'], 2);
            if (count($parts) === 2 && filter_var($parts[0], FILTER_VALIDATE_URL)) {
                $entry['source_url'] = $parts[0];
                $entry['static_path'] = ltrim($parts[1], '/');
            }
        }

        return $entry;
    }

    private static function latest_job_id(): string
    {
        $path = self::meta_path();
        if (! Export_Storage::exists($path)) {
            return '';
        }

        $meta = json_decode(Export_Storage::read($path), true);
        return is_array($meta) && is_string($meta['job_id'] ?? null) ? $meta['job_id'] : '';
    }

    private static function meta_path(): string
    {
        return self::directory() . '/latest-meta.json';
    }

    private static function directory(): string
    {
        return Plugin::storage_directory() . '/activity';
    }

}
