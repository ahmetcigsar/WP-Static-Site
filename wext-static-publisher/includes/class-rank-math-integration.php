<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use SplQueue;

final class Rank_Math_Integration
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
        return defined('RANK_MATH_VERSION') && (string) $this->settings['rank_math_enabled'] === '1';
    }

    public function process_html(string $html, bool $static_search_enabled, string $static_search_path, string $metadata_group = 'archives'): string
    {
        if (! $this->enabled()) {
            return $this->remove_rank_math_block($html);
        }

        if (! $this->metadata_enabled($metadata_group)) {
            $html = preg_replace_callback(
                '#<!-- Search Engine Optimization by Rank Math.*?<!-- /Rank Math WordPress SEO plugin -->#is',
                static function (array $match): string {
                    return preg_replace('#<(?:meta|link)\b[^>]*>#i', '', $match[0]) ?? $match[0];
                },
                $html
            ) ?? $html;
        }

        if ((string) $this->settings['rank_math_schema'] !== '1') {
            return preg_replace('#<script\b[^>]*class\s*=\s*["\'][^"\']*rank-math-schema[^"\']*["\'][^>]*>.*?</script>#is', '', $html) ?? $html;
        }

        return preg_replace_callback(
            '#<script\b[^>]*class\s*=\s*["\'][^"\']*rank-math-schema[^"\']*["\'][^>]*>(.*?)</script>#is',
            function (array $match) use ($static_search_enabled, $static_search_path): string {
                $json = json_decode(html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (! is_array($json)) {
                    return $match[0];
                }
                $json = $this->rewrite_schema_value($json, $static_search_enabled, $static_search_path);
                $encoded = wp_json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

        if ((string) $this->settings['rank_math_sitemaps'] === '1') {
            $result['sitemaps'] = $this->export_sitemaps($write_file, $log);
        }
        if ((string) $this->settings['rank_math_robots'] === '1') {
            $result['robots'] = $this->export_robots($write_file, $log);
        }
        return $result;
    }

    public function manifest_data(): array
    {
        return [
            'active' => defined('RANK_MATH_VERSION'),
            'version' => defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : '',
            'settings' => $this->settings,
        ];
    }

    private function metadata_enabled(string $group): bool
    {
        return (string) ($this->settings['rank_math_metadata_' . $group] ?? '0') === '1';
    }

    private function remove_rank_math_block(string $html): string
    {
        return preg_replace('#\s*<!-- Search Engine Optimization by Rank Math.*?<!-- /Rank Math WordPress SEO plugin -->\s*#is', "\n", $html) ?? $html;
    }

    private function rewrite_schema_value(mixed $value, bool $static_search_enabled, string $static_search_path): mixed
    {
        if (is_array($value)) {
            $type = $value['@type'] ?? null;
            $is_search_action = $type === 'SearchAction' || (is_array($type) && in_array('SearchAction', $type, true));
            if ($is_search_action && isset($value['target'])) {
                if ($static_search_enabled) {
                    $value['target'] = $this->target . '/' . trim($static_search_path, '/') . '/?q={search_term_string}';
                }
            }
            foreach ($value as $key => $item) {
                if (! $static_search_enabled && $key === 'potentialAction' && is_array($item)) {
                    $action_type = $item['@type'] ?? null;
                    if ($action_type === 'SearchAction' || (is_array($action_type) && in_array('SearchAction', $action_type, true))) {
                        unset($value[$key]);
                        continue;
                    }
                }
                $value[$key] = $this->rewrite_schema_value($item, $static_search_enabled, $static_search_path);
            }
            return $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        return $this->rewrite_url_text($value);
    }

    private function export_sitemaps(callable $write_file, callable $log): int
    {
        $queue = new SplQueue();
        $queue->enqueue($this->origin . '/sitemap_index.xml');
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
                $log('warning', __('Rank Math sitemap could not be exported.', 'wext-static-publisher'), $url);
                continue;
            }
            $body = (string) wp_remote_retrieve_body($response);
            if ($body === '') {
                continue;
            }
            foreach ($this->discover_sitemap_urls($body, $url) as $discovered) {
                $queue->enqueue($discovered);
            }
            $path = ltrim((string) wp_parse_url($url, PHP_URL_PATH), '/');
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
            $log('warning', __('Rank Math robots.txt could not be exported.', 'wext-static-publisher'), $url);
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
            'redirection' => 5,
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
        if (strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== strtolower((string) wp_parse_url($this->origin, PHP_URL_HOST))) {
            return false;
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return preg_match('#^/[a-z0-9/_-]*(?:sitemap[a-z0-9_-]*|[a-z0-9_-]+-sitemap(?:[0-9]+)?|main-sitemap)\.(?:xml|xsl)$#i', $path) === 1;
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
