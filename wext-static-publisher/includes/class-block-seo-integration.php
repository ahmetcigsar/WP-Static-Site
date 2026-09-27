<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use SplQueue;

final class Block_SEO_Integration
{
    private array $settings;
    private string $origin;
    private string $target;
    private string $prefix;
    private string $constant;
    private string $label;
    private string $block_pattern;
    private string $sitemap_path;

    public function __construct(string $origin, string $target, array $config, ?array $settings = null)
    {
        $this->origin = untrailingslashit($origin);
        $this->target = untrailingslashit($target);
        $this->prefix = (string) $config['prefix'];
        $this->constant = (string) $config['constant'];
        $this->label = (string) $config['label'];
        $this->block_pattern = (string) $config['block_pattern'];
        $this->sitemap_path = '/' . ltrim((string) $config['sitemap_path'], '/');
        $this->settings = wp_parse_args($settings ?? Plugin::seo_plugin_settings(), Plugin::seo_plugin_defaults());
    }

    public function enabled(): bool
    {
        return defined($this->constant) && (string) ($this->settings[$this->prefix . '_enabled'] ?? '0') === '1';
    }

    public function process_html(string $html, bool $static_search_enabled, string $static_search_path, string $metadata_group = 'archives'): string
    {
        if (! defined($this->constant)) {
            return $html;
        }
        if (! $this->enabled()) {
            return preg_replace($this->block_pattern, "\n", $html) ?? $html;
        }

        return preg_replace_callback(
            $this->block_pattern,
            function (array $match) use ($static_search_enabled, $static_search_path, $metadata_group): string {
                $block = $match[0];
                if (! $this->metadata_enabled($metadata_group)) {
                    $block = preg_replace('#<(?:meta|link)\b[^>]*>#i', '', $block) ?? $block;
                }
                if ((string) ($this->settings[$this->prefix . '_schema'] ?? '0') !== '1') {
                    return preg_replace($this->schema_pattern(), '', $block) ?? $block;
                }
                return preg_replace_callback(
                    $this->schema_pattern(),
                    function (array $schema_match) use ($static_search_enabled, $static_search_path): string {
                        $json = json_decode(html_entity_decode(trim($schema_match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                        if (! is_array($json)) {
                            return $schema_match[0];
                        }
                        $json = $this->rewrite_schema_value($json, $static_search_enabled, $static_search_path);
                        $encoded = wp_json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        return is_string($encoded) ? str_replace($schema_match[1], $encoded, $schema_match[0]) : $schema_match[0];
                    },
                    $block
                ) ?? $block;
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
        if ((string) ($this->settings[$this->prefix . '_sitemaps'] ?? '0') === '1') {
            $result['sitemaps'] = $this->export_sitemaps($write_file, $log);
        }
        if ((string) ($this->settings[$this->prefix . '_robots'] ?? '0') === '1') {
            $result['robots'] = $this->export_robots($write_file, $log);
        }
        return $result;
    }

    public function manifest_data(): array
    {
        $keys = [$this->prefix . '_enabled'];
        foreach (['pages', 'posts', 'custom_post_types', 'archives'] as $group) {
            $keys[] = $this->prefix . '_metadata_' . $group;
        }
        $keys[] = $this->prefix . '_schema';
        $keys[] = $this->prefix . '_sitemaps';
        $keys[] = $this->prefix . '_robots';
        return [
            'active' => defined($this->constant),
            'version' => defined($this->constant) ? (string) constant($this->constant) : '',
            'settings' => array_intersect_key($this->settings, array_flip($keys)),
        ];
    }

    private function metadata_enabled(string $group): bool
    {
        return (string) ($this->settings[$this->prefix . '_metadata_' . $group] ?? '0') === '1';
    }

    private function schema_pattern(): string
    {
        return '#<script\b(?=[^>]*\btype\s*=\s*["\']application/ld\+json["\'])[^>]*>(.*?)</script>#is';
    }

    private function rewrite_schema_value(mixed $value, bool $static_search_enabled, string $static_search_path): mixed
    {
        if (is_array($value)) {
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
            return array_is_list($value) ? array_values($value) : $value;
        }
        return is_string($value) ? $this->rewrite_url_text($value) : $value;
    }

    private function export_sitemaps(callable $write_file, callable $log): int
    {
        $queue = new SplQueue();
        $queue->enqueue($this->origin . $this->sitemap_path);
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
                $log('warning', sprintf(__('%s sitemap could not be exported.', 'wext-static-publisher'), $this->label), $url);
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
            $log('warning', sprintf(__('%s robots.txt could not be exported.', 'wext-static-publisher'), $this->label), $url);
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
            'user-agent' => 'WPStaticPublisher/' . WEXTSTAT_VERSION,
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
        return preg_match('#^/[a-z0-9/_-]*sitemaps?[a-z0-9/_-]*\.(?:xml|xsl)$#i', (string) wp_parse_url($url, PHP_URL_PATH)) === 1;
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
