<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

final class Path_Mapper
{
    public static function url_to_relative_path(string $url, string $content_type = ''): ?string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $path = rawurldecode($path ?: '/');
        $segments = array_values(array_filter(explode('/', $path), static fn ($segment) => $segment !== ''));

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                return null;
            }
        }

        $relative = implode('/', array_map(
            static fn (string $segment): string => preg_replace('/[\x00-\x1F\x7F]/u', '', $segment) ?? '',
            $segments
        ));
        $is_html = str_contains(strtolower($content_type), 'text/html');

        if ($relative !== '' && ! self::safe_segments($relative)) {
            return null;
        }

        if ($is_html) {
            if ($relative === '') {
                return 'index.html';
            }

            if (str_ends_with($path, '/') || pathinfo($relative, PATHINFO_EXTENSION) === '') {
                return $relative . '/index.html';
            }
        }

        $relative = $relative !== '' ? $relative : 'index.html';
        return self::is_safe_static_path($relative) ? $relative : null;
    }

    public static function is_safe_static_path(string $path): bool
    {
        if (! self::safe_segments($path)) {
            return false;
        }
        if (in_array($path, ['_headers', '_redirects'], true)) {
            return true;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, [
            'html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'xml', 'xsl', 'txt', 'csv',
            'webmanifest', 'wasm', 'pdf', 'zip', 'gz', 'br',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'svgz', 'ico', 'bmp', 'tif', 'tiff',
            'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp3', 'mp4', 'm4a', 'ogg', 'oga', 'ogv', 'wav', 'webm',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'vtt', 'srt',
        ], true);
    }

    private static function safe_segments(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/[\\\\\x00-\x1f\x7f:%?#]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || (str_starts_with($segment, '.') && $segment !== '.well-known')
                || str_ends_with($segment, '.') || str_ends_with($segment, ' ')
                || strcasecmp($segment, 'web.config') === 0
                || preg_match('/\.(?:php[0-9]*|phtml|pht|phar|phps|cgi|fcgi|pl|py|rb|sh|bash|asp|aspx|jsp|jspx|shtml|shtm)(?:\.|$)/i', $segment)) {
                return false;
            }
        }
        return true;
    }
}
