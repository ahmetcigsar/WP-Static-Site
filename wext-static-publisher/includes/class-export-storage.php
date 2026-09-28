<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use RuntimeException;

/** Storage for export artifacts only; no PHP or web-server configuration from crawled pages is accepted. */
final class Export_Storage
{
    public static function protect(string $directory): void
    {
        if (is_link($directory) || ! wp_mkdir_p($directory)) {
            throw new RuntimeException(esc_html__('The export storage directory is unavailable.', 'wext-static-publisher'));
        }
        // Fixed access-control files for our own uploads directory, never copied from the source site.
        $files = [
            'index.html' => '',
            '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
        ];
        foreach ($files as $name => $contents) {
            $path = $directory . '/' . $name;
            if (is_link($path)) {
                throw new RuntimeException(esc_html__('Export storage must not contain symbolic links.', 'wext-static-publisher'));
            }
            if (! file_exists($path) && file_put_contents($path, $contents, LOCK_EX) === false) {
                throw new RuntimeException(esc_html__('Failed to protect the export storage directory.', 'wext-static-publisher'));
            }
        }
    }

    public static function write(string $directory, string $relative_path, string $contents): void
    {
        if (! Path_Mapper::is_safe_static_path($relative_path) || ! is_dir($directory) || is_link($directory)) {
            throw new RuntimeException(esc_html__('Unsafe export destination.', 'wext-static-publisher'));
        }
        $path = $directory;
        foreach (explode('/', $relative_path) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException(esc_html__('Export storage must not contain symbolic links.', 'wext-static-publisher'));
            }
        }
        if (! wp_mkdir_p(dirname($path)) || file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException(esc_html__('Failed to write export file.', 'wext-static-publisher'));
        }
    }
}
