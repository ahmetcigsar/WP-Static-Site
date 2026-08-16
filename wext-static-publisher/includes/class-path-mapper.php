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

        if ($is_html) {
            if ($relative === '') {
                return 'index.html';
            }

            if (str_ends_with($path, '/') || pathinfo($relative, PATHINFO_EXTENSION) === '') {
                return $relative . '/index.html';
            }
        }

        return $relative !== '' ? $relative : 'index.html';
    }
}
