<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

final class Activity_Log
{
    private const PAGE_SIZE = 50;

    public static function reset(string $job_id): void
    {
        self::prepare_directory();
        file_put_contents(self::log_path(), '', LOCK_EX);
        file_put_contents(self::meta_path(), (string) wp_json_encode([
            'job_id' => sanitize_file_name($job_id),
            'started_at' => gmdate('c'),
        ]), LOCK_EX);
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
        self::prepare_directory();
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
            file_put_contents(self::log_path(), $entry . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public static function page(int $page, string $search = ''): array
    {
        $entries = [];
        $search = trim($search);
        $path = self::log_path();
        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach (array_reverse($lines) as $line) {
                    $entry = json_decode($line, true);
                    if (is_array($entry)) {
                        $entry = self::normalise_entry($entry);
                        if ($search === '' || self::matches_search($entry, $search)) {
                            $entries[] = $entry;
                        }
                    }
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
        if (! is_readable($path)) {
            return '';
        }

        $meta = json_decode((string) file_get_contents($path), true);
        return is_array($meta) && is_string($meta['job_id'] ?? null) ? $meta['job_id'] : '';
    }

    private static function log_path(): string
    {
        return self::directory() . '/latest.jsonl';
    }

    private static function meta_path(): string
    {
        return self::directory() . '/latest-meta.json';
    }

    private static function directory(): string
    {
        return Plugin::storage_directory() . '/activity';
    }

    private static function prepare_directory(): void
    {
        $base = Plugin::storage_directory();
        $directory = self::directory();
        wp_mkdir_p($directory);

        $files = [
            $base . '/index.php' => "<?php\n// Silence is golden.\n",
            $base . '/.htaccess' => "Require all denied\nDeny from all\n",
            $base . '/web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>",
            $directory . '/index.php' => "<?php\n// Silence is golden.\n",
        ];

        foreach ($files as $path => $contents) {
            if (! file_exists($path)) {
                file_put_contents($path, $contents);
            }
        }
    }
}
