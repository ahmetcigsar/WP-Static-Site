<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

final class Hide_Replacements
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = wp_parse_args($settings, Plugin::hide_defaults());
    }

    public function map_relative_path(string $relative_path): string
    {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        $segments = explode('/', $relative_path);
        $original_segments = $segments;

        if (($original_segments[0] ?? '') === 'wp-content') {
            $segments[0] = $this->settings['wp_content_directory'];
            if (($original_segments[1] ?? '') === 'uploads') {
                $segments[1] = $this->settings['uploads_directory'];
            } elseif (($original_segments[1] ?? '') === 'plugins') {
                $segments[1] = $this->settings['plugins_directory'];
            } elseif (($original_segments[1] ?? '') === 'themes') {
                $segments[1] = $this->settings['themes_directory'];
                $last_index = count($segments) - 1;
                if (($original_segments[$last_index] ?? '') === 'style.css') {
                    $segments[$last_index] = $this->settings['theme_style_name'] . '.css';
                }
            }
        } elseif (($original_segments[0] ?? '') === 'wp-includes') {
            $segments[0] = $this->settings['wp_includes_directory'];
        } elseif (($original_segments[0] ?? '') === 'author') {
            $segments[0] = $this->settings['author_url'];
        }

        return implode('/', $segments);
    }

    public function rewrite_content(string $content): string
    {
        $theme_path = '/' . $this->settings['wp_content_directory'] . '/' . $this->settings['themes_directory'];
        $style_name = $this->settings['theme_style_name'] . '.css';
        $content = preg_replace_callback(
            '#(?<![A-Za-z0-9])/wp-content/themes/([^/"\'\s?\#]+)/style\.css#i',
            static fn (array $matches): string => $theme_path . '/' . $matches[1] . '/' . $style_name,
            $content
        ) ?? $content;
        $escaped_theme_path = str_replace('/', '\\/', $theme_path);
        $content = preg_replace_callback(
            '#(?<![A-Za-z0-9])\\\\/wp-content\\\\/themes\\\\/([^\\\\/"\'\s?\#]+)\\\\/style\.css#i',
            static fn (array $matches): string => $escaped_theme_path . '\\/' . $matches[1] . '\\/' . $style_name,
            $content
        ) ?? $content;

        $replacements = [
            '/wp-content/uploads/' => '/' . $this->settings['wp_content_directory'] . '/' . $this->settings['uploads_directory'] . '/',
            '/wp-content/plugins/' => '/' . $this->settings['wp_content_directory'] . '/' . $this->settings['plugins_directory'] . '/',
            '/wp-content/themes/' => '/' . $this->settings['wp_content_directory'] . '/' . $this->settings['themes_directory'] . '/',
            '/wp-content/' => '/' . $this->settings['wp_content_directory'] . '/',
            '/wp-includes/' => '/' . $this->settings['wp_includes_directory'] . '/',
            '/author/' => '/' . $this->settings['author_url'] . '/',
        ];
        foreach ($replacements as $source => $replacement) {
            $content = $this->replace_root_path($content, $source, $replacement);
        }

        return $content;
    }

    public function rewrite_html(string $html): string
    {
        if ($this->enabled('hide_wordpress_version')) {
            $html = $this->remove_wordpress_version($html);
        }
        if ($this->enabled('hide_generator_meta')) {
            $html = $this->remove_void_tags($html, 'meta', static fn (string $tag): bool =>
                preg_match('/\bname\s*=\s*(["\']?)generator\1/i', $tag) === 1
            );
        }
        if ($this->enabled('hide_wordpress_dns_prefetch')) {
            $html = $this->remove_void_tags($html, 'link', static fn (string $tag): bool =>
                stripos($tag, 'dns-prefetch') !== false
                && preg_match('#(?:s\.w\.org|wordpress\.org)#i', $tag) === 1
            );
        }
        if ($this->enabled('hide_rsd_header')) {
            $html = $this->remove_void_tags($html, 'link', static fn (string $tag): bool =>
                stripos($tag, 'EditURI') !== false || stripos($tag, 'application/rsd+xml') !== false
            );
        }
        if ($this->enabled('disable_xml_rpc')) {
            $html = $this->remove_void_tags($html, 'link', static fn (string $tag): bool =>
                stripos($tag, 'xmlrpc.php') !== false || stripos($tag, 'pingback') !== false
            );
        }
        if ($this->enabled('disable_wlw_manifest')) {
            $html = $this->remove_void_tags($html, 'link', static fn (string $tag): bool =>
                stripos($tag, 'wlwmanifest') !== false
            );
        }
        if ($this->enabled('disable_embed_scripts')) {
            $html = $this->remove_container_tags($html, 'script', static fn (string $tag): bool =>
                stripos($tag, 'wp-embed') !== false
            );
        }
        if ($this->enabled('disable_emojis')) {
            $is_emoji = static fn (string $tag): bool =>
                stripos($tag, 'wp-emoji') !== false
                || stripos($tag, 'wpemoji') !== false
                || stripos($tag, 'emoji-settings') !== false;
            $html = $this->remove_container_tags($html, 'script', $is_emoji);
            $html = $this->remove_container_tags($html, 'style', $is_emoji);
            $html = $this->remove_void_tags($html, 'link', $is_emoji);
        }

        return $html;
    }

    public function uploads_public_path(): string
    {
        return '/' . $this->settings['wp_content_directory'] . '/' . $this->settings['uploads_directory'] . '/';
    }

    public function settings(): array
    {
        return $this->settings;
    }

    private function replace_root_path(string $content, string $source, string $replacement): string
    {
        foreach ([$source, str_replace('/', '\\/', $source)] as $source_variant) {
            $replacement_variant = str_contains($source_variant, '\\/')
                ? str_replace('/', '\\/', $replacement)
                : $replacement;
            $content = preg_replace(
                '#(?<![A-Za-z0-9])' . preg_quote($source_variant, '#') . '#',
                $replacement_variant,
                $content
            ) ?? $content;
        }
        return $content;
    }

    private function enabled(string $key): bool
    {
        return ($this->settings[$key] ?? '0') === '1';
    }

    private function remove_wordpress_version(string $html): string
    {
        $version = preg_quote((string) get_bloginfo('version'), '#');
        return preg_replace_callback(
            '#(?<prefix>\?|&(?:amp;)?)ver=' . $version . '(?<suffix>&(?:amp;)?|(?=["\'\s<\#]))#i',
            static function (array $matches): string {
                $prefix = (string) ($matches['prefix'] ?? '');
                $suffix = (string) ($matches['suffix'] ?? '');
                if ($prefix === '?' && $suffix !== '') {
                    return '?';
                }
                return $suffix;
            },
            $html
        ) ?? $html;
    }

    private function remove_void_tags(string $html, string $tag_name, callable $matches): string
    {
        return preg_replace_callback(
            '#<' . preg_quote($tag_name, '#') . '\b[^>]*>#is',
            static fn (array $tag): string => $matches($tag[0]) ? '' : $tag[0],
            $html
        ) ?? $html;
    }

    private function remove_container_tags(string $html, string $tag_name, callable $matches): string
    {
        return preg_replace_callback(
            '#<' . preg_quote($tag_name, '#') . '\b[^>]*>.*?</' . preg_quote($tag_name, '#') . '\s*>#is',
            static fn (array $tag): string => $matches($tag[0]) ? '' : $tag[0],
            $html
        ) ?? $html;
    }
}
