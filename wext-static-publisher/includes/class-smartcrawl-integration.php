<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use SplQueue;

final class SmartCrawl_Integration
{
    private array $settings;
    private string $origin;
    private string $target;

    public function __construct(string $origin, string $target, ?array $settings = null)
    {
        $this->origin = untrailingslashit($origin);
        $this->target = untrailingslashit($target);
        $this->settings = wp_parse_args($settings ?? Plugin::seo_plugin_settings(), Plugin::seo_plugin_defaults());
    }

    public function enabled(): bool
    {
        return defined('SMARTCRAWL_VERSION') && (string) $this->settings['smartcrawl_enabled'] === '1';
    }

    public function process_html(string $html, bool $static_search_enabled, string $static_search_path, string $metadata_group = 'archives'): string
    {
        if (! defined('SMARTCRAWL_VERSION')) {
            return $html;
        }
        if (! $this->enabled()) {
            return $this->remove_schema($this->remove_metadata($html));
        }
        if (! $this->metadata_enabled($metadata_group)) {
            $html = $this->remove_metadata($html);
        }
        if ((string) $this->settings['smartcrawl_schema'] !== '1') {
            return $this->remove_schema($html);
        }

        return preg_replace_callback(
            '#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            function (array $match) use ($static_search_enabled, $static_search_path): string {
                $json = json_decode(html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (! is_array($json)) {
                    return $match[0];
                }
                $json = $this->rewrite_schema_value($json, $static_search_enabled, $static_search_path);
                $encoded = wp_json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
                return is_string($encoded) ? str_replace($match[1], $encoded, $match[0]) : $match[0];
            },
            $html
        ) ?? $html;
    }

    public function export_root_files(callable $write_file, callable $log): array
    {
        $result = ['sitemaps' => 0, 'robots' => false];
        if (! $this->enabled()) {
            return $result;
        }
        if ((string) $this->settings['smartcrawl_sitemaps'] === '1') {
            $result['sitemaps'] = $this->export_sitemaps($write_file, $log);
        }
        if ((string) $this->settings['smartcrawl_robots'] === '1') {
            $result['robots'] = $this->export_robots($write_file, $log);
        }
        return $result;
    }

    public function manifest_data(): array
    {
        return [
            'active' => defined('SMARTCRAWL_VERSION'),
            'version' => defined('SMARTCRAWL_VERSION') ? (string) SMARTCRAWL_VERSION : '',
            'settings' => array_intersect_key($this->settings, array_flip([
                'smartcrawl_enabled',
                'smartcrawl_metadata_pages',
                'smartcrawl_metadata_posts',
                'smartcrawl_metadata_custom_post_types',
                'smartcrawl_metadata_archives',
                'smartcrawl_schema',
                'smartcrawl_sitemaps',
                'smartcrawl_robots',
            ])),
        ];
    }

    private function metadata_enabled(string $group): bool
    {
        return (string) ($this->settings['smartcrawl_metadata_' . $group] ?? '0') === '1';
    }

    private function remove_metadata(string $html): string
    {
        return preg_replace_callback(
            '#<(?:meta|link)\b[^>]*>#i',
            static function (array $match): string {
                $tag = $match[0];
                if (preg_match('#\b(?:name|property)\s*=\s*["\'](?:description|robots|googlebot|bingbot|keywords|google-site-verification|msvalidate.01|p:domain_verify|fb:app_id|twitter:[^"\']+|og:[^"\']+|article:[^"\']+|product:[^"\']+|profile:[^"\']+)["\']#i', $tag)) {
                    return '';
                }
                if (preg_match('#\brel\s*=\s*["\'](?:canonical|prev|next)["\']#i', $tag)) {
                    return '';
                }
                return $tag;
            },
            $html
        ) ?? $html;
    }

    private function remove_schema(string $html): string
    {
        return preg_replace('#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>.*?</script>#is', '', $html) ?? $html;
    }

    private function rewrite_schema_value(mixed $value, bool $static_search_enabled, string $static_search_path): mixed
    {
        if (is_array($value)) {
            $is_list = array_is_list($value);
            $type = $value['@type'] ?? null;
            $is_search_action = $type === 'SearchAction' || (is_array($type) && in_array('SearchAction', $type, true));
            if ($is_search_action) {
                if (! $static_search_enabled) {
                    return null;
                }
                $search_url = $this->target . '/' . trim($static_search_path, '/') . '/?q={search_term_string}';
                if (isset($value['target']) && is_array($value['target'])) {
                    $value['target']['urlTemplate'] = $search_url;
                } else {
                    $value['target'] = $search_url;
                }
            }
            foreach ($value as $key => $item) {
                $rewritten = $this->rewrite_schema_value($item, $static_search_enabled, $static_search_path);
                if ($rewritten === null && ($key === 'potentialAction' || (is_int($key) && is_array($item)))) {
                    unset($value[$key]);
                    continue;
                }
                $value[$key] = $rewritten;
            }
            return $is_list ? array_values($value) : $value;
        }
        return is_string($value) ? $this->rewrite_url_text($value) : $value;
    }

    private function export_sitemaps(callable $write_file, callable $log): int
    {
        $queue = new SplQueue();
        $queue->enqueue($this->origin . '/sitemap.xml');
        $visited = [];
        $written = 0;
        while (! $queue->isEmpty() && count($visited) < 100) {
            $url = (string) $queue->dequeue();
            if (isset($visited[$url]) || ! $this->is_allowed_sitemap_url($url)) {
                continue;
            }
            $visited[$url] = true;
            $response = wp_remote_get($url, $this->request_args());
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                $log('warning', __('SmartCrawl sitemap could not be exported.', 'wext-static-publisher'), $url);
                continue;
            }
            $body = (string) wp_remote_retrieve_body($response);
            if ($body === '' || preg_match('#<(?:sitemapindex|urlset|xsl:stylesheet)\b#i', $body) !== 1) {
                continue;
            }
            foreach ($this->discover_sitemap_urls($body, $url) as $discovered) {
                $queue->enqueue($discovered);
            }
            $path = ltrim((string) wp_parse_url($url, PHP_URL_PATH), '/');
            $body = $this->export_stylesheets($body, $write_file, $log);
            $write_file($path, $this->rewrite_url_text($body));
            $written++;
        }
        return $written;
    }

    private function export_robots(callable $write_file, callable $log): bool
    {
        $url = $this->origin . '/robots.txt';
        $response = wp_remote_get($url, $this->request_args());
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            $log('warning', __('SmartCrawl robots.txt could not be exported.', 'wext-static-publisher'), $url);
            return false;
        }
        $body = (string) wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return false;
        }
        $write_file('robots.txt', $this->rewrite_url_text($body));
        return true;
    }

    private function request_args(): array
    {
        return [
            'timeout' => 25,
            'redirection' => 0,
            'user-agent' => 'Wext\StaticPublisher/' . WEXTSTAT_VERSION,
            'headers' => ['X-Wext-Static-Export' => '1'],
        ];
    }

    private function discover_sitemap_urls(string $body, string $base_url): array
    {
        preg_match_all('#(?:<loc>\s*|href\s*=\s*["\'])([^<"\']+\.(?:xml|xsl)(?:\?[^<"\']*)?)#i', $body, $matches);
        $urls = [];
        foreach ($matches[1] ?? [] as $candidate) {
            $candidate = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (str_starts_with($candidate, '//')) {
                $candidate = (string) wp_parse_url($this->origin, PHP_URL_SCHEME) . ':' . $candidate;
            } elseif (str_starts_with($candidate, '/')) {
                $candidate = $this->origin . $candidate;
            } elseif (! preg_match('#^https?://#i', $candidate)) {
                $candidate = $this->origin . '/' . ltrim(dirname((string) wp_parse_url($base_url, PHP_URL_PATH)) . '/' . $candidate, '/');
            }
            if ($this->is_allowed_sitemap_url($candidate)) {
                $urls[] = $candidate;
            }
        }
        return array_values(array_unique($urls));
    }

    private function is_allowed_sitemap_url(string $url): bool
    {
        if (! $this->same_origin($url) || wp_parse_url($url, PHP_URL_QUERY) || wp_parse_url($url, PHP_URL_FRAGMENT)) {
            return false;
        }
        return preg_match('#^/[a-z0-9/_-]*sitemaps?[a-z0-9/_-]*\.(?:xml|xsl)$#i', (string) wp_parse_url($url, PHP_URL_PATH)) === 1;
    }

    private function same_origin(string $url): bool
    {
        foreach ([PHP_URL_SCHEME, PHP_URL_HOST, PHP_URL_PORT] as $part) {
            if (wp_parse_url($url, $part) !== wp_parse_url($this->origin, $part)) {
                return false;
            }
        }
        return ! wp_parse_url($url, PHP_URL_USER) && ! wp_parse_url($url, PHP_URL_PASS);
    }

    /** SmartCrawl serves its XSL through a WordPress query, which needs a static filename. */
    private function export_stylesheets(string $body, callable $write_file, callable $log): string
    {
        return preg_replace_callback(
            '#<\?xml-stylesheet\b.*?\?>#is',
            function (array $instruction) use ($write_file, $log): string {
                if (! preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#is', $instruction[0], $href)) {
                    return $instruction[0];
                }
                $url = html_entity_decode($href[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (str_starts_with($url, '//')) {
                    $url = (string) wp_parse_url($this->origin, PHP_URL_SCHEME) . ':' . $url;
                } elseif (str_starts_with($url, '/')) {
                    $url = $this->origin . $url;
                }
                parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
                if (! $this->same_origin($url) || ($query['wds_sitemap_styling'] ?? '') !== '1') {
                    return $instruction[0];
                }
                $template = $query['template'] ?? '';
                if (! in_array($template, ['sitemapIndexBody', 'sitemapBody', 'newsSitemapBody'], true)) {
                    return '';
                }
                $path = 'smartcrawl-' . $template . (! empty($query['whitelabel']) ? '-whitelabel' : '') . '.xsl';
                $response = wp_remote_get($url, $this->request_args());
                $stylesheet = (string) wp_remote_retrieve_body($response);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200
                    || ! str_contains($stylesheet, '<xsl:stylesheet')) {
                    $log('warning', __('SmartCrawl sitemap stylesheet could not be exported.', 'wext-static-publisher'), $url);
                    return '';
                }
                $write_file($path, $this->rewrite_url_text($stylesheet));
                return str_replace($href[2], esc_url($this->target . '/' . $path), $instruction[0]);
            },
            $body
        ) ?? $body;
    }

    private function rewrite_url_text(string $content): string
    {
        $origin_host = (string) wp_parse_url($this->origin, PHP_URL_HOST);
        $origin_port = wp_parse_url($this->origin, PHP_URL_PORT);
        $host_with_port = $origin_host . (is_int($origin_port) ? ':' . $origin_port : '');
        return str_replace(
            [$this->origin, str_replace('/', '\\/', $this->origin), '//' . $host_with_port, '\\/\\/' . str_replace('/', '\\/', $host_with_port)],
            [$this->target, str_replace('/', '\\/', $this->target), '//' . ltrim((string) preg_replace('#^https?:#', '', $this->target), '/'), '\\/\\/' . str_replace('/', '\\/', ltrim((string) preg_replace('#^https?:#', '', $this->target), '/'))],
            $content
        );
    }
}
