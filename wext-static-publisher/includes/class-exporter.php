<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use DOMDocument;
use DOMElement;
use RuntimeException;
use SplQueue;
use Throwable;
use ZipArchive;

final class Exporter
{
    private string $origin;
    private string $target;
    private int $maximum_urls;
    private array $excluded_prefixes;
    private array $visited = [];
    private string $job_id = '';
    private string $build_directory = '';
    private int $reported_progress = 0;
    private Hide_Replacements $hide_replacements;
    private Static_Search $static_search;
    private Language_Routing $language_routing;
    private Rank_Math_Integration $rank_math_integration;
    private AIOSEO_Integration $aioseo_integration;
    private SEOPress_Integration $seopress_integration;
    private Block_SEO_Integration $surerank_integration;
    private Block_SEO_Integration $seo_framework_integration;
    private Block_SEO_Integration $yoast_integration;
    private SEO_Toolkit $seo_toolkit;
    private array $seo_toolkit_manifest = [];
    private array $search_documents = [];

    public function __construct()
    {
        $settings = Plugin::settings();
        $this->origin = untrailingslashit(home_url());
        $this->target = untrailingslashit((string) ($settings['target_url'] ?: home_url()));
        $this->maximum_urls = max(10, min(20000, (int) $settings['maximum_urls']));
        $seo_plugin_settings = Plugin::seo_plugin_settings();
        $seo_settings = Plugin::seo_settings();
        $this->hide_replacements = new Hide_Replacements(Plugin::hide_settings());
        $this->static_search = new Static_Search(Plugin::search_settings());
        $this->language_routing = new Language_Routing();
        $this->rank_math_integration = new Rank_Math_Integration($this->origin, $this->target, $seo_plugin_settings);
        $this->aioseo_integration = new AIOSEO_Integration($this->origin, $this->target, $seo_plugin_settings);
        $this->seopress_integration = new SEOPress_Integration($this->origin, $this->target, $seo_plugin_settings);
        $this->surerank_integration = new Block_SEO_Integration($this->origin, $this->target, [
            'prefix' => 'surerank',
            'constant' => 'SURERANK_VERSION',
            'label' => 'SureRank SEO',
            'block_pattern' => '#<!--\s*SureRank Meta Data\s*-->.*?<!--\s*/SureRank Meta Data\s*-->#is',
            'sitemap_path' => '/sitemap_index.xml',
        ], $seo_plugin_settings);
        $this->seo_framework_integration = new Block_SEO_Integration($this->origin, $this->target, [
            'prefix' => 'seo_framework',
            'constant' => 'THE_SEO_FRAMEWORK_VERSION',
            'label' => 'The SEO Framework',
            'block_pattern' => '#<!--\s*The SEO Framework\b.*?-->.*?<!--\s*/\s*The SEO Framework\b.*?-->#is',
            'sitemap_path' => '/sitemap.xml',
        ], $seo_plugin_settings);
        $this->yoast_integration = new Block_SEO_Integration($this->origin, $this->target, [
            'prefix' => 'yoast',
            'constant' => 'WPSEO_VERSION',
            'label' => 'Yoast SEO',
            'block_pattern' => '#<!--\s*This site is optimized with the .*?Yoast SEO.*?-->.*?<!--\s*/\s*Yoast SEO.*?-->#is',
            'sitemap_path' => '/sitemap_index.xml',
        ], $seo_plugin_settings);
        $this->seo_toolkit = new SEO_Toolkit($this->origin, $this->target, $seo_settings);
        $this->excluded_prefixes = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', (string) $settings['excluded_paths']) ?: []
        )));
    }

    public function run(string $job_id): array
    {
        if (get_transient(Plugin::LOCK_KEY)) {
            throw new RuntimeException(__('Another export process is still running.', 'wext-static-publisher'));
        }

        set_transient(Plugin::LOCK_KEY, $job_id, 30 * MINUTE_IN_SECONDS);
        $this->job_id = $job_id;
        Activity_Log::reset($job_id);
        $started_at = gmdate('c');

        try {
            $this->prepare_build_directory($job_id);
            Plugin::set_status($job_id, 'running', 2, [
                'started_at' => $started_at,
                'phase' => 'preparing',
                'status_message' => __('Export folder is being prepared.', 'wext-static-publisher'),
                'current_url' => '',
                'url_count' => 0,
                'error' => '',
            ]);
            $this->reported_progress = 2;
            $this->crawl();
            $this->rank_math_integration->export_root_files(
                fn (string $path, string $contents) => $this->write_file($path, $contents),
                fn (string $level, string $message, string $url = '') => $this->add_log($level, $message, $url)
            );
            $this->aioseo_integration->export_root_files(
                fn (string $path, string $contents) => $this->write_file($path, $contents),
                fn (string $level, string $message, string $url = '') => $this->add_log($level, $message, $url)
            );
            $this->seopress_integration->export_root_files(
                fn (string $path, string $contents) => $this->write_file($path, $contents),
                fn (string $level, string $message, string $url = '') => $this->add_log($level, $message, $url)
            );
            foreach ([$this->surerank_integration, $this->seo_framework_integration, $this->yoast_integration] as $integration) {
                $integration->export_root_files(
                    fn (string $path, string $contents) => $this->write_file($path, $contents),
                    fn (string $level, string $message, string $url = '') => $this->add_log($level, $message, $url)
                );
            }
            $this->validate_language_outputs();
            if ($this->static_search->enabled()) {
                Plugin::set_status($job_id, 'running', 86, [
                    'phase' => 'search-index',
                    'status_message' => __('Fuse.js search index is being prepared.', 'wext-static-publisher'),
                    'current_url' => '',
                ]);
                $this->write_search_files();
            }
            Plugin::set_status($job_id, 'running', 87, [
                'phase' => 'seo-audit',
                'status_message' => __('SEO reports and advanced sitemap are being prepared.', 'wext-static-publisher'),
                'current_url' => '',
            ]);
            $this->seo_toolkit_manifest = $this->seo_toolkit->write_outputs(
                fn (string $path, string $contents) => $this->write_file($path, $contents),
                $this->build_directory
            );
            Plugin::set_status($job_id, 'running', 88, [
                'phase' => 'cloudflare-files',
                'status_message' => __('Cloudflare configuration files are being prepared.', 'wext-static-publisher'),
                'current_url' => '',
            ]);
            $this->write_cloudflare_files();
            Plugin::set_status($job_id, 'running', 92, [
                'phase' => 'manifest',
                'status_message' => __('Export manifest is being prepared.', 'wext-static-publisher'),
            ]);
            $manifest = $this->write_manifest($job_id, $started_at);
            Plugin::set_status($job_id, 'running', 96, [
                'phase' => 'archive',
                'status_message' => __('ZIP archive is being created.', 'wext-static-publisher'),
            ]);
            $archive = $this->create_archive($job_id);
            $finished_at = gmdate('c');

            Plugin::set_status($job_id, 'completed', 100, [
                'finished_at' => $finished_at,
                'last_completed_at' => $finished_at,
                'archive' => $archive,
                'manifest' => $manifest,
                'url_count' => count($this->visited),
                'phase' => 'completed',
                'status_message' => __('The static site has been created successfully.', 'wext-static-publisher'),
                'current_url' => '',
                'error' => '',
            ]);

            do_action('wext_static_export_completed', $job_id, $archive, $manifest);

            return Plugin::status();
        } catch (Throwable $error) {
            $this->add_log('error', $error->getMessage());
            Plugin::set_status($job_id, 'failed', 100, [
                'finished_at' => gmdate('c'),
                'error' => $error->getMessage(),
                'phase' => 'failed',
                'status_message' => __('Failed to create static site.', 'wext-static-publisher'),
                'current_url' => '',
            ]);
            throw $error;
        } finally {
            delete_transient(Plugin::LOCK_KEY);
        }
    }

    private function prepare_build_directory(string $job_id): void
    {
        $base = Plugin::storage_directory();
        wp_mkdir_p($base . '/builds');
        wp_mkdir_p($base . '/archives');
        $this->protect_storage_directory($base);
        $this->build_directory = $base . '/builds/' . sanitize_file_name($job_id);

        if (! wp_mkdir_p($this->build_directory)) {
            throw new RuntimeException(__('Failed to create export folder.', 'wext-static-publisher'));
        }
    }

    private function protect_storage_directory(string $base): void
    {
        $files = [
            $base . '/index.php' => "<?php\n// Silence is golden.\n",
            $base . '/.htaccess' => "Require all denied\nDeny from all\n",
            $base . '/web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>",
        ];

        foreach ($files as $path => $contents) {
            if (! file_exists($path)) {
                file_put_contents($path, $contents);
            }
        }
    }

    private function crawl(): void
    {
        $queue = new SplQueue();
        $queue->enqueue($this->origin . '/');

        foreach ($this->seed_content_urls() as $url) {
            $queue->enqueue($url);
        }
        foreach ($this->language_routing->seed_urls($this->origin) as $url) {
            $queue->enqueue($url);
        }

        while (! $queue->isEmpty() && count($this->visited) < $this->maximum_urls) {
            $url = $this->normalise_url((string) $queue->dequeue());
            if ($url === null || isset($this->visited[$url]) || $this->is_excluded($url)) {
                continue;
            }

            $this->visited[$url] = true;
            $request_args = apply_filters('wext_static_request_args', [
                'timeout' => 25,
                'redirection' => 5,
                'user-agent' => 'WPStaticPublisher/' . WEXTSTAT_VERSION,
                'headers' => ['X-Wext-Static-Export' => '1'],
            ], $url);
            $response = wp_remote_get($url, $request_args);

            if (is_wp_error($response)) {
                $this->add_log('warning', $response->get_error_message(), $url);
                $this->update_crawl_progress($queue, $url);
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $source_status_code = $this->source_status_code($response);
            if ($status_code < 200 || $status_code >= 400) {
                $this->add_log('warning', sprintf('HTTP %d', $status_code), $url, '', $source_status_code);
                $this->update_crawl_progress($queue, $url);
                continue;
            }

            $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
            $body = (string) wp_remote_retrieve_body($response);
            $discovered = [];
            $is_html = str_contains(strtolower($content_type), 'text/html');

            if ($is_html) {
                [$body, $discovered] = $this->process_html($body, $url);
                $body = $this->hide_replacements->rewrite_html($body);
            } elseif (str_contains(strtolower($content_type), 'text/css')) {
                $discovered = $this->extract_css_urls($body, $url);
                $body = $this->rewrite_asset_origin($body);
            } elseif ($this->is_text_content($content_type)) {
                $body = $this->rewrite_asset_origin($body);
            }
            $body = $this->hide_replacements->rewrite_content($body);

            $relative_path = Path_Mapper::url_to_relative_path($url, $content_type);
            if ($relative_path === null) {
                $this->add_log('warning', __('Unsafe file path skipped.', 'wext-static-publisher'), $url, '', $source_status_code);
                $this->update_crawl_progress($queue, $url);
                continue;
            }
            $relative_path = $this->hide_replacements->map_relative_path($relative_path);

            if ($is_html && $this->static_search->enabled()) {
                $document = $this->static_search->extract_document($body, $url, $relative_path);
                if ($document !== null) {
                    $this->search_documents[] = $document;
                }
                $body = $this->static_search->inject_search_bridge($body);
            }
            if ($is_html) {
                $body = $this->language_routing->inject_x_default($body, $this->target);
                $body = $this->language_routing->inject_preference_script($body);
                $body = $this->seo_toolkit->process_html($body, $url, $relative_path);
            }

            $this->write_file($relative_path, $body);
            $this->add_log('info', __('The source has been converted to static file.', 'wext-static-publisher'), $url, $relative_path, $source_status_code);

            foreach ($discovered as $discovered_url) {
                $queue->enqueue($discovered_url);
            }

            $this->update_crawl_progress($queue, $url);
        }

        $this->reported_progress = 85;
        Plugin::set_status($this->job_id, 'running', 85, [
            'phase' => 'crawl-completed',
            'status_message' => __('Site scan completed.', 'wext-static-publisher'),
            'url_count' => count($this->visited),
            'current_url' => '',
        ]);

        if (! $queue->isEmpty()) {
            $this->add_log('warning', __('URL limit reached; Some resources were excluded from export.', 'wext-static-publisher'));
        }
    }

    private function update_crawl_progress(SplQueue $queue, string $url): void
    {
        $processed = count($this->visited);
        $estimated_total = max(1, $processed + $queue->count());
        $estimated_progress = 5 + (int) floor(($processed / $estimated_total) * 80);
        $this->reported_progress = min(85, max($this->reported_progress, $estimated_progress));

        Plugin::set_status($this->job_id, 'running', $this->reported_progress, [
            'phase' => 'crawling',
            'status_message' => __('Site addresses are converted to static files.', 'wext-static-publisher'),
            'url_count' => $processed,
            'current_url' => $url,
        ]);
    }

    private function seed_content_urls(): array
    {
        $urls = [];
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);

        $query = new \WP_Query([
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'posts_per_page' => $this->maximum_urls,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ($query->posts as $post_id) {
            $permalink = get_permalink((int) $post_id);
            if (is_string($permalink)) {
                $urls[] = $permalink;
            }
        }

        return apply_filters('wext_static_seed_urls', array_values(array_unique($urls)));
    }

    private function process_html(string $html, string $base_url): array
    {
        $search_settings = $this->static_search->settings();
        $metadata_group = $this->metadata_group_for_url($base_url);
        $html = $this->rank_math_integration->process_html(
            $html,
            $this->static_search->enabled(),
            (string) ($search_settings['page_path'] ?? 'arama'),
            $metadata_group
        );
        $html = $this->aioseo_integration->process_html(
            $html,
            $this->static_search->enabled(),
            (string) ($search_settings['page_path'] ?? 'arama'),
            $metadata_group
        );
        $html = $this->seopress_integration->process_html(
            $html,
            $this->static_search->enabled(),
            (string) ($search_settings['page_path'] ?? 'arama'),
            $metadata_group
        );
        foreach ([$this->surerank_integration, $this->seo_framework_integration, $this->yoast_integration] as $integration) {
            $html = $integration->process_html(
                $html,
                $this->static_search->enabled(),
                (string) ($search_settings['page_path'] ?? 'arama'),
                $metadata_group
            );
        }
        return $this->discover_and_rewrite_html($html, $base_url);
    }

    private function discover_and_rewrite_html(string $html, string $base_url): array
    {
        if (! class_exists(DOMDocument::class)) {
            return [$this->rewrite_html_for_output($html), []];
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [$this->rewrite_html_for_output($html), []];
        }

        $attributes = ['href', 'src', 'poster', 'data-src', 'data-bg'];
        $discovered = [];

        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach ($attributes as $attribute) {
                if (! $element->hasAttribute($attribute)) {
                    continue;
                }

                $absolute = $this->absolute_url($element->getAttribute($attribute), $base_url);
                if ($absolute !== null && $this->is_same_origin($absolute)) {
                    $discovered[] = $absolute;
                    $element->setAttribute($attribute, $this->to_public_url($absolute));
                }
            }

            if ($element->hasAttribute('srcset')) {
                $element->setAttribute(
                    'srcset',
                    preg_replace_callback('/(^|,\s*)([^\s,]+)(\s+[^,]+)?/', function (array $match) use ($base_url, &$discovered): string {
                        $absolute = $this->absolute_url($match[2], $base_url);
                        if ($absolute === null || ! $this->is_same_origin($absolute)) {
                            return $match[0];
                        }
                        $discovered[] = $absolute;
                        return $match[1] . $this->to_public_url($absolute) . ($match[3] ?? '');
                    }, $element->getAttribute('srcset')) ?: $element->getAttribute('srcset')
                );
            }
        }

        // DOMDocument yalnızca keşif için kullanılır. Orijinal HTML'yi yeniden serialize
        // etmek tema işaretlemesini değiştirebildiğinden çıktı üzerinde sadece origin
        // dönüşümü uygulanır.
        return [$this->rewrite_html_for_output($html), array_values(array_unique($discovered))];
    }

    private function metadata_group_for_url(string $url): string
    {
        $request_url = untrailingslashit((string) preg_replace('/[?#].*$/', '', $url));
        $home_url = untrailingslashit(home_url('/'));
        if (get_option('show_on_front') === 'page' && (int) get_option('page_on_front') > 0 && $request_url === $home_url) {
            return 'pages';
        }
        $post_id = url_to_postid($url);
        if ($post_id <= 0) {
            return 'archives';
        }
        if ((int) get_option('page_for_posts') === $post_id) {
            return 'archives';
        }
        $post_type = get_post_type($post_id);
        if ($post_type === 'page') {
            return 'pages';
        }
        if ($post_type === 'post') {
            return 'posts';
        }
        return 'custom_post_types';
    }

    private function extract_css_urls(string $css, string $base_url): array
    {
        preg_match_all('/(?:url\(\s*["\']?([^"\')]+)|@import\s+["\']([^"\']+))/i', $css, $matches, PREG_SET_ORDER);
        $urls = [];

        foreach ($matches as $match) {
            $candidate = $match[1] ?: ($match[2] ?? '');
            $absolute = $this->absolute_url($candidate, $base_url);
            if ($absolute !== null && $this->is_same_origin($absolute)) {
                $urls[] = $absolute;
            }
        }

        return array_values(array_unique($urls));
    }

    private function absolute_url(string $candidate, string $base_url): ?string
    {
        $candidate = html_entity_decode(trim($candidate));
        if ($candidate === '' || str_starts_with($candidate, '#') || preg_match('#^(?:mailto|tel|javascript|data):#i', $candidate)) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            return (string) wp_parse_url($this->origin, PHP_URL_SCHEME) . ':' . $candidate;
        }

        if (preg_match('#^https?://#i', $candidate)) {
            return $candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return $this->origin . $candidate;
        }

        $base_path = (string) wp_parse_url($base_url, PHP_URL_PATH);
        $directory = trailingslashit(dirname($base_path));
        return $this->origin . $this->normalise_path('/' . ltrim($directory . $candidate, '/'));
    }

    private function normalise_path(string $path): string
    {
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $query] = explode('?', $path, 2);
            $query = '?' . $query;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments) . $query;
    }

    private function normalise_url(string $url): ?string
    {
        if (! $this->is_same_origin($url)) {
            return null;
        }

        $parts = wp_parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if (isset($parts['query']) && $this->looks_like_asset($path)) {
            return $this->origin . $path;
        }

        if (isset($parts['query'])) {
            return null;
        }

        return $this->origin . ($path ?: '/');
    }

    private function is_same_origin(string $url): bool
    {
        return strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === strtolower((string) wp_parse_url($this->origin, PHP_URL_HOST));
    }

    private function is_excluded(string $url): bool
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        foreach ($this->excluded_prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($path, '/' . ltrim($prefix, '/'))) {
                return true;
            }
        }
        return false;
    }

    private function looks_like_asset(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) !== '';
    }

    private function is_text_content(string $content_type): bool
    {
        return str_starts_with(strtolower($content_type), 'text/') || str_contains(strtolower($content_type), 'json');
    }

    private function rewrite_origin(string $content): string
    {
        return str_replace(
            [$this->origin, str_replace('/', '\\/', $this->origin)],
            [$this->target, str_replace('/', '\\/', $this->target)],
            $content
        );
    }

    private function rewrite_asset_origin(string $content): string
    {
        return str_replace(
            [$this->origin, str_replace('/', '\\/', $this->origin)],
            ['', ''],
            $content
        );
    }

    private function rewrite_html_for_output(string $html): string
    {
        // Önce tüm iç adresleri domain bağımsız kök yollara çeviririz. Böylece
        // HTML, inline CSS/JS, srcset ve page-builder verileri pages.dev üzerinde
        // de özel domain bağlandıktan sonra da aynı şekilde çalışır.
        $html = $this->rewrite_asset_origin($html);

        // Canonical bağlantılar mutlak canlı domaini göstermelidir.
        $html = preg_replace_callback(
            '#<link\b[^>]*\brel\s*=\s*(["\'])canonical\1[^>]*>#i',
            function (array $match): string {
                return preg_replace(
                    '#(\bhref\s*=\s*["\'])(/[^"\']*|/)(["\'])#i',
                    '$1' . $this->target . '$2$3',
                    $match[0]
                ) ?? $match[0];
            },
            $html
        ) ?? $html;

        // hreflang alternatifleri de arama motorları için tam canlı adres olmalıdır.
        $html = preg_replace_callback(
            '#<link\b(?=[^>]*\bhreflang\s*=)[^>]*>#i',
            function (array $match): string {
                return preg_replace(
                    '#(\bhref\s*=\s*["\'])(/[^"\']*|/)(["\'])#i',
                    '$1' . $this->target . '$2$3',
                    $match[0]
                ) ?? $match[0];
            },
            $html
        ) ?? $html;

        // Sosyal paylaşım metadata adresleri de mutlak canlı domaini göstermelidir.
        $html = preg_replace_callback(
            '#<meta\b[^>]*\b(?:property|name)\s*=\s*(["\'])(?:og:url|og:image|twitter:image|twitter:url)\1[^>]*>#i',
            function (array $match): string {
                return preg_replace(
                    '#(\bcontent\s*=\s*["\'])(/[^"\']*|/)(["\'])#i',
                    '$1' . $this->target . '$2$3',
                    $match[0]
                ) ?? $match[0];
            },
            $html
        ) ?? $html;

        return $html;
    }

    private function to_public_url(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = wp_parse_url($url, PHP_URL_QUERY);
        $mapped_path = $this->hide_replacements->map_relative_path(ltrim($path ?: '/', '/'));
        return $this->target . '/' . $mapped_path . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    private function write_file(string $relative_path, string $contents): void
    {
        $destination = $this->build_directory . '/' . ltrim($relative_path, '/');
        if (! wp_mkdir_p(dirname($destination)) || file_put_contents($destination, $contents) === false) {
            throw new RuntimeException(__('Failed to write file:', 'wext-static-publisher') . $relative_path);
        }
    }

    private function validate_language_outputs(): void
    {
        if (! $this->language_routing->enabled()) {
            return;
        }

        foreach ($this->language_routing->languages() as $language) {
            $path = $this->build_directory . '/' . $language . '/index.html';
            if (! is_readable($path)) {
                throw new RuntimeException(sprintf(__('Could not export language root: /%s/', 'wext-static-publisher'), $language));
            }
        }
    }

    private function write_cloudflare_files(): void
    {
        $headers = "/*\n  X-Content-Type-Options: nosniff\n  Referrer-Policy: strict-origin-when-cross-origin\n  X-Frame-Options: SAMEORIGIN\n\n/wext-language-config.json\n  Cache-Control: no-store\n\n/wext-seo-report.json\n  X-Robots-Tag: noindex, nofollow\n  Cache-Control: no-store\n\n/wext-seo-report.html\n  X-Robots-Tag: noindex, nofollow\n  Cache-Control: no-store\n\n/wext-performance-report.json\n  X-Robots-Tag: noindex, nofollow\n  Cache-Control: no-store\n\n" . $this->hide_replacements->uploads_public_path() . "*\n  Cache-Control: public, max-age=31536000, immutable\n";
        $seo_headers = $this->seo_toolkit->headers_rules();
        if ($seo_headers !== '') {
            $headers .= "\n" . $seo_headers . "\n";
        }
        $this->write_file('_headers', $headers);
        $this->write_file('_redirects', implode("\n", $this->seo_toolkit->redirect_rules()) . "\n");
        $robots_path = $this->build_directory . '/robots.txt';
        $robots = is_readable($robots_path) ? (string) file_get_contents($robots_path) : "User-agent: *\nAllow: /\n";
        if ((string) ($this->seo_toolkit->manifest_data()['sitemap'] ?? '') !== ''
            && ! str_contains($robots, $this->seo_toolkit->sitemap_url())) {
            $robots = rtrim($robots) . "\nSitemap: " . $this->seo_toolkit->sitemap_url() . "\n";
        }
        foreach (['wext-video-sitemap.xml', 'wext-news-sitemap.xml'] as $optional_sitemap) {
            $optional_path = $this->build_directory . '/' . $optional_sitemap;
            $optional_url = $this->target . '/' . $optional_sitemap;
            if (is_readable($optional_path) && ! str_contains($robots, $optional_url)) {
                $robots = rtrim($robots) . "\nSitemap: " . $optional_url . "\n";
            }
        }
        $this->write_file('robots.txt', $robots);
        $this->write_file('wext-language-config.json', $this->language_routing->config_json());
        if ($this->language_routing->enabled()) {
            $this->write_file('wext-language-preference.js', $this->language_routing->preference_script());
        }

        if (! file_exists($this->build_directory . '/404.html')) {
            $language = str_replace('_', '-', (string) get_bloginfo('language')) ?: 'en-US';
            $this->write_file('404.html', sprintf(
                '<!doctype html><html lang="%1$s"><meta charset="utf-8"><title>%2$s</title><h1>404</h1><p>%3$s</p></html>',
                esc_attr($language),
                esc_html__('Page Not Found', 'wext-static-publisher'),
                esc_html__('The page you requested could not be found.', 'wext-static-publisher')
            ));
        }
    }

    private function write_search_files(): void
    {
        foreach ($this->static_search->build_files($this->search_documents) as $path => $contents) {
            $this->write_file($path, $contents);
        }
    }

    private function create_archive(string $job_id): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('PHP ZipArchive extension is not installed.', 'wext-static-publisher'));
        }

        $archive_path = Plugin::storage_directory() . '/archives/' . sanitize_file_name($job_id) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('Could not create ZIP archive.', 'wext-static-publisher'));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->build_directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($this->build_directory) + 1));
            }
        }
        $zip->close();

        return $archive_path;
    }

    private function write_manifest(string $job_id, string $started_at): array
    {
        $file_hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->build_directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($this->build_directory) + 1);
                $file_hashes[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($file_hashes);

        $manifest = [
            'schema_version' => 1,
            'plugin_version' => WEXTSTAT_VERSION,
            'job_id' => $job_id,
            'origin' => $this->origin,
            'target' => $this->target,
            'started_at' => $started_at,
            'finished_at' => gmdate('c'),
            'url_count' => count($this->visited),
            'hide_replacements' => $this->hide_replacements->settings(),
            'static_search' => [
                'enabled' => $this->static_search->enabled(),
                'document_count' => count($this->search_documents),
                'settings' => $this->static_search->settings(),
            ],
            'language_routing' => json_decode($this->language_routing->config_json(), true),
            'rank_math' => $this->rank_math_integration->manifest_data(),
            'aioseo' => $this->aioseo_integration->manifest_data(),
            'seopress' => $this->seopress_integration->manifest_data(),
            'surerank' => $this->surerank_integration->manifest_data(),
            'seo_framework' => $this->seo_framework_integration->manifest_data(),
            'yoast' => $this->yoast_integration->manifest_data(),
            'seo_toolkit' => $this->seo_toolkit_manifest,
            'build_sha256' => hash('sha256', (string) wp_json_encode($file_hashes, JSON_UNESCAPED_SLASHES)),
        ];
        $this->write_file('wext-static-manifest.json', (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $manifest;
    }

    private function source_status_code(array $response): int
    {
        $status_code = (int) wp_remote_retrieve_response_code($response);
        $http_response = $response['http_response'] ?? null;
        if (! is_object($http_response) || ! method_exists($http_response, 'get_response_object')) {
            return $status_code;
        }

        $response_object = $http_response->get_response_object();
        if (! is_object($response_object) || ! isset($response_object->history) || ! is_array($response_object->history) || $response_object->history === []) {
            return $status_code;
        }

        $history = $response_object->history;
        $source_response = end($history);
        return is_object($source_response) && isset($source_response->status_code)
            ? absint($source_response->status_code)
            : $status_code;
    }

    private function add_log(string $level, string $message, string $source_url = '', string $static_path = '', int $status_code = 0): void
    {
        Activity_Log::append($this->job_id, $level, $message, $source_url, $static_path, $status_code);
    }
}
