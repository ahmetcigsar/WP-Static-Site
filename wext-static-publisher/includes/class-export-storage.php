<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use RuntimeException;
use Generator;
use Throwable;

/** Private database objects. Keys are identifiers, never filesystem paths or URLs. */
final class Export_Storage
{
    public const ROOT = 'wext-db';
    private const CHUNK_SIZE = 262144;

    public static function install(): void
    {
        if (get_option('wext_static_storage_schema') === '1') {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'wext_static_objects';
        $collation = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            object_key char(64) NOT NULL,
            part int(11) NOT NULL,
            path text NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY  (object_key,part),
            KEY object_path (path(191),part)
        ) $collation;");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify the plugin-owned table before marking the schema installed.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new RuntimeException(esc_html__('Could not create export database storage.', 'wext-static-publisher'));
        }
        update_option('wext_static_storage_schema', '1', false);
    }

    /** Import earlier local ZIPs without extracting any files to disk. Originals remain as backups. */
    public static function import_legacy(): void
    {
        if (get_option('wext_static_database_migration') === '1') {
            return;
        }
        $uploads = wp_upload_dir(null, false);
        if (! empty($uploads['error']) || empty($uploads['basedir'])) {
            return;
        }
        foreach (['wext-static-publisher', 'wext-static', 'ragnus-static'] as $folder) {
            $root = $uploads['basedir'] . '/' . $folder;
            $directory = $root . '/archives';
            if (is_link($root) || is_link($directory) || ! is_dir($directory)) {
                continue;
            }
            foreach (glob($directory . '/*.zip') ?: [] as $path) {
                if (is_link($path) || ! is_file($path)) {
                    continue;
                }
                $job = pathinfo($path, PATHINFO_FILENAME);
                if ($job === '' || sanitize_file_name($job) !== $job) {
                    continue;
                }
                $archive = self::ROOT . '/archives/' . $job . '.zip';
                if (self::exists($archive)) {
                    continue;
                }
                if (! class_exists(\ZipArchive::class)) {
                    throw new RuntimeException(esc_html__('ZipArchive is required to import existing exports.', 'wext-static-publisher'));
                }
                $zip = new \ZipArchive();
                if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
                    throw new RuntimeException(esc_html__('An existing export ZIP could not be imported. The original has been preserved.', 'wext-static-publisher'));
                }
                $build = self::ROOT . '/builds/' . $job;
                try {
                    if (! self::delete_directory($build)) {
                        throw new RuntimeException(esc_html__('Could not prepare imported export storage.', 'wext-static-publisher'));
                    }
                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $name = $zip->getNameIndex($index);
                        if (! is_string($name) || str_ends_with($name, '/')) {
                            continue;
                        }
                        if (! Path_Mapper::is_safe_static_path($name)) {
                            throw new RuntimeException(esc_html__('An existing export contains an unsafe path. The original has been preserved.', 'wext-static-publisher'));
                        }
                        $body = $zip->getFromIndex($index);
                        if (! is_string($body)) {
                            throw new RuntimeException(esc_html__('An existing export could not be read.', 'wext-static-publisher'));
                        }
                        self::write($build, $name, $body);
                    }
                    $objects = [];
                    foreach (self::files($build) as $name => $metadata) {
                        $objects[$name] = $build . '/' . $name;
                    }
                    self::store($archive, Export_Zip::pieces($objects));
                } catch (Throwable $error) {
                    self::delete_directory($build);
                    throw $error;
                } finally {
                    $zip->close();
                }
            }
        }
        update_option('wext_static_database_migration', '1', false);
    }

    private static function table(): string
    {
        self::install();
        global $wpdb;
        return $wpdb->prefix . 'wext_static_objects';
    }

    private static function validate(string $path): void
    {
        if (! str_starts_with($path, self::ROOT . '/') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f:%?#]/', $path)) {
            throw new RuntimeException(esc_html__('Invalid export storage identifier.', 'wext-static-publisher'));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException(esc_html__('Invalid export storage identifier.', 'wext-static-publisher'));
            }
        }
    }

    public static function write(string $directory, string $relative_path, string $contents): void
    {
        if (! Path_Mapper::is_safe_static_path($relative_path)) {
            throw new RuntimeException(esc_html__('Unsafe export destination.', 'wext-static-publisher'));
        }
        self::store($directory . '/' . $relative_path, [$contents]);
    }

    /** Write bounded database rows; publish metadata only after every chunk succeeds. */
    public static function store(string $path, iterable $pieces): void
    {
        self::validate($path);
        global $wpdb;
        $table = self::table();
        if (! self::delete($path)) {
            throw new RuntimeException(esc_html__('Could not replace export database data.', 'wext-static-publisher'));
        }
        $part = 0;
        $size = 0;
        $sha = hash_init('sha256');
        $crc = hash_init('crc32b');
        $buffer = '';
        try {
            foreach ($pieces as $piece) {
                $length = strlen($piece);
                $size += $length;
                hash_update($sha, $piece);
                hash_update($crc, $piece);
                for ($offset = 0; $offset < $length;) {
                    $take = min(self::CHUNK_SIZE - strlen($buffer), $length - $offset);
                    $buffer .= substr($piece, $offset, $take);
                    $offset += $take;
                    if (strlen($buffer) === self::CHUNK_SIZE) {
                        self::insert($table, $path, $part++, base64_encode($buffer));
                        $buffer = '';
                    }
                }
            }
            if ($buffer !== '') {
                self::insert($table, $path, $part++, base64_encode($buffer));
            }
            self::insert($table, $path, -1, (string) wp_json_encode([
                'size' => $size, 'parts' => $part, 'sha256' => hash_final($sha),
                'crc32' => hash_final($crc), 'modified' => time(),
            ]));
        } catch (Throwable $error) {
            self::delete($path);
            throw $error;
        }
    }

    private static function insert(string $table, string $path, int $part, string $payload): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned chunk storage; wpdb escapes all values.
        if ($wpdb->insert($table, ['object_key' => hash('sha256', $path), 'part' => $part, 'path' => $path, 'payload' => $payload], ['%s', '%d', '%s', '%s']) === false) {
            throw new RuntimeException(esc_html__('Could not write export data to the database.', 'wext-static-publisher'));
        }
    }

    public static function metadata(string $path): ?array
    {
        self::validate($path);
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Streaming objects are intentionally not stored in the object cache.
        $json = $wpdb->get_var($wpdb->prepare('SELECT payload FROM %i WHERE object_key = %s AND part = -1', self::table(), hash('sha256', $path)));
        if ($wpdb->last_error !== '') {
            throw new RuntimeException(esc_html__('Could not read export database storage.', 'wext-static-publisher'));
        }
        $meta = is_string($json) ? json_decode($json, true) : null;
        return is_array($meta) ? $meta : null;
    }

    public static function exists(string $path): bool
    {
        return self::metadata($path) !== null;
    }

    public static function chunks(string $path): Generator
    {
        $meta = self::metadata($path);
        if ($meta === null) {
            throw new RuntimeException(esc_html__('The export object was not found.', 'wext-static-publisher'));
        }
        global $wpdb;
        $table = self::table();
        for ($part = 0; $part < $meta['parts']; $part++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read one bounded chunk at a time without persistent caching.
            $encoded = $wpdb->get_var($wpdb->prepare('SELECT payload FROM %i WHERE object_key = %s AND part = %d', $table, hash('sha256', $path), $part));
            $chunk = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($chunk === false) {
                throw new RuntimeException(esc_html__('Export data is incomplete.', 'wext-static-publisher'));
            }
            yield $chunk;
        }
    }

    /** Bounded reader for phpseclib's SOURCE_CALLBACK mode. */
    public static function reader(string $path): callable
    {
        $chunks = self::chunks($path);
        $buffer = '';
        return static function (int $length) use ($chunks, &$buffer): ?string {
            if ($length < 1) {
                throw new RuntimeException(esc_html__('Invalid export read length.', 'wext-static-publisher'));
            }
            if ($buffer === '' && $chunks->valid()) {
                $buffer = $chunks->current();
                $chunks->next();
            }
            if ($buffer === '') {
                return null;
            }
            $result = substr($buffer, 0, $length);
            $buffer = substr($buffer, strlen($result));
            return $result;
        };
    }

    public static function read(string $path): string
    {
        $contents = '';
        foreach (self::chunks($path) as $chunk) {
            $contents .= $chunk;
        }
        return $contents;
    }

    public static function stream(string $path): void
    {
        foreach (self::chunks($path) as $chunk) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authorized application/zip response; escaping corrupts the archive.
            echo $chunk;
        }
    }

    /** Sorted object metadata, without loading asset bodies. */
    public static function files(string $directory): array
    {
        self::validate($directory . '/placeholder');
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Metadata listing in the plugin-owned table.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT path, payload FROM %i WHERE part = -1 AND path LIKE %s ORDER BY path', self::table(), $wpdb->esc_like($directory . '/') . '%'), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException(esc_html__('Could not list export database storage.', 'wext-static-publisher'));
        }
        $files = [];
        foreach ($rows as $row) {
            // Database collations may compare paths without regard to case.
            if (! str_starts_with($row['path'], $directory . '/')) {
                continue;
            }
            $files[substr($row['path'], strlen($directory) + 1)] = json_decode($row['payload'], true);
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function delete(string $path): bool
    {
        self::validate($path);
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Delete only the exact plugin object.
        return $wpdb->delete(self::table(), ['object_key' => hash('sha256', $path)], ['%s']) !== false;
    }

    public static function delete_directory(string $directory): bool
    {
        self::validate($directory . '/placeholder');
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Include incomplete objects when cleaning a build.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT DISTINCT object_key, path FROM %i WHERE path LIKE %s', self::table(), $wpdb->esc_like($directory . '/') . '%'), ARRAY_A);
        if ($wpdb->last_error !== '') {
            return false;
        }
        $success = true;
        foreach ($rows as $row) {
            if (str_starts_with($row['path'], $directory . '/')) {
                $success = self::delete($row['path']) && $success;
            }
        }
        return $success;
    }
}
