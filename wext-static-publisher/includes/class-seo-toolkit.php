<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use DOMDocument;
use DOMElement;

final class SEO_Toolkit
{
    private string $origin;
    private string $target;
    private array $settings;
    private array $pages = [];
    private array $issues = [];
    private array $performance = [];
    private array $report = [];

    public function __construct(string $origin, string $target, ?array $settings = null)
    {
        $this->origin = untrailingslashit($origin);
        $this->target = untrailingslashit($target);
        $this->settings = wp_parse_args($settings ?? Plugin::seo_settings(), Plugin::seo_defaults());
    }

    public function settings(): array
    {
        $safe = $this->settings;
        if ((string) ($safe['indexnow_key'] ?? '') !== '') {
            $safe['indexnow_key'] = '[configured]';
        }
        return $safe;
    }

    public function process_html(string $html, string $source_url, string $relative_path): string
    {
        $public_url = $this->public_url($relative_path);
        $public_path = (string) wp_parse_url($public_url, PHP_URL_PATH);
        if ((string) ($this->settings['site_noindex'] ?? '0') === '1'
            || $this->matches_lines($public_path, (string) ($this->settings['noindex_paths'] ?? ''))) {
            $html = $this->upsert_robots_noindex($html);
        }
        if ((string) ($this->settings['canonical_fallback'] ?? '0') === '1'
            && preg_match('#<link\b[^>]*\brel\s*=\s*(["\'])canonical\1[^>]*>#i', $html) !== 1) {
            $html = $this->inject_into_head($html, '<link rel="canonical" href="' . esc_url($public_url) . '">');
        }

        $page = $this->inspect_html($html, $source_url, $public_url, $relative_path);
        $this->pages[$public_url] = $page;
        return $html;
    }

    public function write_outputs(callable $write_file, string $build_directory): array
    {
        if ((string) ($this->settings['audit_enabled'] ?? '0') === '1') {
            $this->finalize_audit($build_directory);
            $write_file('wext-seo-report.json', (string) wp_json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ((string) ($this->settings['audit_html_report'] ?? '0') === '1') {
                $write_file('wext-seo-report.html', $this->report_html());
            }
        }

        if ((string) ($this->settings['advanced_sitemap'] ?? '0') === '1') {
            $write_file('wext-sitemap.xml', $this->sitemap_xml());
            if ((string) ($this->settings['sitemap_video'] ?? '0') === '1') {
                $video_sitemap = $this->video_sitemap_xml();
                if ($video_sitemap !== '') {
                    $write_file('wext-video-sitemap.xml', $video_sitemap);
                }
            }
            if ((string) ($this->settings['sitemap_news'] ?? '0') === '1') {
                $news_sitemap = $this->news_sitemap_xml();
                if ($news_sitemap !== '') {
                    $write_file('wext-news-sitemap.xml', $news_sitemap);
                }
            }
        }
        if ((string) ($this->settings['performance_audit'] ?? '0') === '1') {
            $write_file('wext-performance-report.json', (string) wp_json_encode($this->performance_report($build_directory), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $key = (string) ($this->settings['indexnow_key'] ?? '');
        if ((string) ($this->settings['indexnow_enabled'] ?? '0') === '1'
            && preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) === 1) {
            $write_file($key . '.txt', $key);
        }

        return $this->manifest_data();
    }

    public function redirect_rules(): array
    {
        $rules = [
            '/wp-admin/* ' . $this->origin . '/wp-admin/:splat 302',
            '/wp-login.php ' . $this->origin . '/wp-login.php 302',
        ];
        foreach ($this->custom_redirect_rules() as $rule) {
            $rules[] = $rule['source'] . ' ' . $rule['target'] . ' ' . $rule['status'];
        }
        if ((string) ($this->settings['redirect_old_slugs'] ?? '0') === '1') {
            foreach ($this->old_slug_redirects() as $rule) {
                $rules[] = $rule;
            }
        }
        if ((string) ($this->settings['redirect_import_plugins'] ?? '0') === '1') {
            foreach ($this->plugin_redirects() as $rule) {
                $rules[] = $rule;
            }
        }
        return array_values(array_unique($rules));
    }

    public function headers_rules(): string
    {
        $blocks = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($this->settings['x_robots_rules'] ?? '')) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $pattern = str_starts_with($parts[0], '/') ? $parts[0] : '/*.' . ltrim($parts[0], '*.');
            $directives = sanitize_text_field($parts[1]);
            if (preg_match('/^[a-z0-9_*,.: -]+$/i', $directives) !== 1) {
                continue;
            }
            $blocks[] = $pattern . "\n  X-Robots-Tag: " . $directives;
        }
        if ((string) ($this->settings['site_noindex'] ?? '0') === '1') {
            array_unshift($blocks, "/*\n  X-Robots-Tag: noindex, nofollow");
        }
        return implode("\n\n", $blocks);
    }

    public function sitemap_url(): string
    {
        return $this->target . '/wext-sitemap.xml';
    }

    public function manifest_data(): array
    {
        $page_hashes = [];
        foreach ($this->pages as $url => $page) {
            if ($page['indexable']) {
                $page_hashes[$url] = (string) $page['hash'];
            }
        }
        ksort($page_hashes);
        return [
            'settings' => $this->settings(),
            'page_count' => count($this->pages),
            'indexable_page_count' => count($page_hashes),
            'audit_summary' => (array) ($this->report['summary'] ?? []),
            'sitemap' => (string) ($this->settings['advanced_sitemap'] ?? '0') === '1' ? $this->sitemap_url() : '',
            'page_hashes' => $page_hashes,
        ];
    }

    private function inspect_html(string $html, string $source_url, string $public_url, string $relative_path): array
    {
        $page = [
            'source_url' => $source_url,
            'url' => $public_url,
            'relative_path' => $relative_path,
            'title' => '',
            'description' => '',
            'canonical' => '',
            'robots' => '',
            'noindex' => false,
            'hreflang' => [],
            'links' => [],
            'images' => [],
            'preferred_image' => '',
            'schema_types' => [],
            'videos' => [],
            'indexable' => false,
            'lastmod' => $this->last_modified($source_url),
            'hash' => hash('sha256', $html),
            'html_bytes' => strlen($html),
            'render_blocking' => 0,
        ];
        if (! class_exists(DOMDocument::class)) {
            return $page;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            $this->issue('error', 'invalid_html', __('HTML could not be parsed.', 'wext-static-publisher'), $public_url);
            return $page;
        }

        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $page['title'] = trim((string) $titles->item(0)?->textContent);
        }
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }
            $name = strtolower($meta->getAttribute('name'));
            $property = strtolower($meta->getAttribute('property'));
            $content = trim($meta->getAttribute('content'));
            if ($name === 'description' && $page['description'] === '') {
                $page['description'] = $content;
            }
            if (in_array($name, ['robots', 'googlebot'], true)) {
                $page['robots'] .= ($page['robots'] === '' ? '' : ',') . strtolower($content);
            }
            if ($property === 'og:image' || $name === 'twitter:image') {
                $page['preferred_image'] = $content;
            }
        }
        $page['noindex'] = str_contains($page['robots'], 'noindex');

        foreach ($dom->getElementsByTagName('link') as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $rel = strtolower($link->getAttribute('rel'));
            if ($rel === 'canonical') {
                $page['canonical'] = trim($link->getAttribute('href'));
            }
            if (str_contains($rel, 'alternate') && $link->hasAttribute('hreflang')) {
                $page['hreflang'][strtolower($link->getAttribute('hreflang'))] = trim($link->getAttribute('href'));
            }
            if ($rel === 'stylesheet' && ! $link->hasAttribute('media') && ! $link->hasAttribute('disabled')) {
                $page['render_blocking']++;
            }
        }
        foreach ($dom->getElementsByTagName('a') as $link) {
            if ($link instanceof DOMElement) {
                $href = $this->absolute_public_url($link->getAttribute('href'), $public_url);
                if ($href !== null) {
                    $page['links'][] = $href;
                }
            }
        }
        foreach ($dom->getElementsByTagName('img') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }
            $src = $this->absolute_public_url($image->getAttribute('src'), $public_url);
            if ($src === null) {
                continue;
            }
            $page['images'][] = [
                'src' => $src,
                'alt' => $image->hasAttribute('alt') ? trim($image->getAttribute('alt')) : null,
                'width' => $image->getAttribute('width'),
                'height' => $image->getAttribute('height'),
            ];
        }
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (! $script instanceof DOMElement) {
                continue;
            }
            if (strtolower($script->getAttribute('type')) === 'application/ld+json') {
                $decoded = json_decode(trim((string) $script->textContent), true);
                if (! is_array($decoded)) {
                    if ((string) ($this->settings['schema_validation'] ?? '0') === '1') {
                        $this->issue('error', 'invalid_schema', __('Invalid JSON-LD structured data.', 'wext-static-publisher'), $public_url);
                    }
                } else {
                    $page['schema_types'] = array_merge($page['schema_types'], $this->schema_types($decoded));
                    if ((string) ($this->settings['schema_validation'] ?? '0') === '1') {
                        $this->validate_schema_nodes($decoded, $public_url);
                    }
                    $page['videos'] = array_merge($page['videos'], $this->video_schema_nodes($decoded));
                }
            } elseif ($script->hasAttribute('src') && ! $script->hasAttribute('async') && ! $script->hasAttribute('defer') && strtolower($script->getAttribute('type')) !== 'module') {
                $page['render_blocking']++;
            }
        }
        $page['links'] = array_values(array_unique($page['links']));
        $page['schema_types'] = array_values(array_unique($page['schema_types']));
        $page['indexable'] = ! $page['noindex'] && $this->same_url((string) $page['canonical'], $public_url);

        if ($page['title'] === '') {
            $this->issue('error', 'missing_title', __('Page title is missing.', 'wext-static-publisher'), $public_url);
        }
        if ($page['description'] === '') {
            $this->issue('warning', 'missing_description', __('Meta description is missing.', 'wext-static-publisher'), $public_url);
        }
        if ($page['canonical'] === '') {
            $this->issue('error', 'missing_canonical', __('Canonical URL is missing.', 'wext-static-publisher'), $public_url);
        } elseif (! str_starts_with($page['canonical'], $this->target)) {
            $this->issue('error', 'canonical_domain', __('Canonical URL does not use the static site domain.', 'wext-static-publisher'), $public_url);
        }
        if (str_contains($html, $this->origin) && $this->origin !== $this->target) {
            $this->issue('warning', 'origin_leak', __('The WordPress origin URL remains in static HTML.', 'wext-static-publisher'), $public_url);
        }
        if ((string) ($this->settings['image_audit'] ?? '0') === '1') {
            if ($page['images'] !== [] && $page['preferred_image'] === '') {
                $this->issue('warning', 'missing_preferred_image', __('Page has images but no Open Graph or Twitter preview image.', 'wext-static-publisher'), $public_url);
            }
            foreach ($page['images'] as $image) {
                if ($image['alt'] === null) {
                    $this->issue('warning', 'missing_image_alt', __('An image has no alt attribute.', 'wext-static-publisher'), $public_url, ['image' => $image['src']]);
                }
                if ($image['width'] === '' || $image['height'] === '') {
                    $this->issue('info', 'missing_image_dimensions', __('An image has no explicit width or height.', 'wext-static-publisher'), $public_url, ['image' => $image['src']]);
                }
            }
        }
        return $page;
    }

    private function finalize_audit(string $build_directory): void
    {
        $titles = [];
        $canonicals = [];
        foreach ($this->pages as $url => $page) {
            if ($page['title'] !== '') {
                $titles[$page['title']][] = $url;
            }
            if ($page['canonical'] !== '') {
                $canonicals[$page['canonical']][] = $url;
            }
            foreach ($page['links'] as $link) {
                if (! $this->public_url_exists($link, $build_directory)) {
                    $this->issue('warning', 'broken_internal_link', __('Internal link does not exist in the static output.', 'wext-static-publisher'), $url, ['target' => $link]);
                }
            }
        }
        foreach ($titles as $title => $urls) {
            if (count($urls) > 1) {
                foreach ($urls as $url) {
                    $this->issue('warning', 'duplicate_title', __('Page title is also used by another page.', 'wext-static-publisher'), $url, ['title' => $title]);
                }
            }
        }
        foreach ($canonicals as $canonical => $urls) {
            if (count($urls) > 1) {
                foreach ($urls as $url) {
                    $this->issue('error', 'duplicate_canonical', __('Canonical URL is shared by multiple pages.', 'wext-static-publisher'), $url, ['canonical' => $canonical]);
                }
            }
        }
        if ((string) ($this->settings['multilingual_validation'] ?? '0') === '1') {
            $this->audit_hreflang();
        }
        $this->audit_redirects();

        $summary = ['errors' => 0, 'warnings' => 0, 'info' => 0];
        foreach ($this->issues as $issue) {
            $key = $issue['severity'] === 'error' ? 'errors' : ($issue['severity'] === 'warning' ? 'warnings' : 'info');
            $summary[$key]++;
        }
        $summary['pages'] = count($this->pages);
        $summary['indexable_pages'] = count(array_filter($this->pages, static fn (array $page): bool => (bool) $page['indexable']));
        $this->report = [
            'schema_version' => 1,
            'generated_at' => gmdate('c'),
            'target' => $this->target,
            'summary' => $summary,
            'issues' => $this->issues,
            'pages' => array_values($this->pages),
        ];
    }

    private function audit_hreflang(): void
    {
        foreach ($this->pages as $url => $page) {
            foreach ($page['hreflang'] as $language => $alternate) {
                $alternate = strtok($alternate, '#') ?: $alternate;
                if (! isset($this->pages[$alternate])) {
                    $this->issue('warning', 'hreflang_target_missing', __('Hreflang target is missing from the static output.', 'wext-static-publisher'), $url, ['language' => $language, 'target' => $alternate]);
                    continue;
                }
                if (! in_array($url, $this->pages[$alternate]['hreflang'], true)) {
                    $this->issue('warning', 'hreflang_not_reciprocal', __('Hreflang relationship is not reciprocal.', 'wext-static-publisher'), $url, ['language' => $language, 'target' => $alternate]);
                }
            }
        }
    }

    private function audit_redirects(): void
    {
        $map = [];
        foreach ($this->custom_redirect_rules() as $rule) {
            $map[$rule['source']] = $rule['target'];
        }
        foreach ($map as $source => $target) {
            $target_path = (string) wp_parse_url($target, PHP_URL_PATH);
            if ($source === $target_path) {
                $this->issue('error', 'redirect_loop', __('Redirect source and target are identical.', 'wext-static-publisher'), $this->target . $source);
            } elseif (isset($map[$target_path])) {
                $this->issue('warning', 'redirect_chain', __('Redirect target points to another redirect.', 'wext-static-publisher'), $this->target . $source, ['target' => $target]);
            }
        }
    }

    private function sitemap_xml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach ($this->pages as $page) {
            if (! $page['indexable']) {
                continue;
            }
            $xml .= "  <url>\n    <loc>" . esc_xml($page['url']) . "</loc>\n";
            if ((string) ($this->settings['sitemap_lastmod'] ?? '0') === '1' && $page['lastmod'] !== '') {
                $xml .= '    <lastmod>' . esc_xml($page['lastmod']) . "</lastmod>\n";
            }
            if ((string) ($this->settings['sitemap_hreflang'] ?? '0') === '1') {
                foreach ($page['hreflang'] as $language => $alternate) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="' . esc_xml($language) . '" href="' . esc_xml($alternate) . '" />' . "\n";
                }
            }
            if ((string) ($this->settings['sitemap_images'] ?? '0') === '1') {
                foreach (array_slice($page['images'], 0, 1000) as $image) {
                    $xml .= "    <image:image><image:loc>" . esc_xml($image['src']) . "</image:loc></image:image>\n";
                }
            }
            $xml .= "  </url>\n";
        }
        return $xml . "</urlset>\n";
    }

    private function video_sitemap_xml(): string
    {
        $entries = '';
        foreach ($this->pages as $page) {
            if (! $page['indexable']) {
                continue;
            }
            foreach ($page['videos'] as $video) {
                if ($video['name'] === '' || $video['description'] === '' || $video['thumbnail'] === '' || $video['upload_date'] === '') {
                    continue;
                }
                $entries .= "  <url>\n    <loc>" . esc_xml($page['url']) . "</loc>\n    <video:video>\n";
                $entries .= '      <video:thumbnail_loc>' . esc_xml($video['thumbnail']) . "</video:thumbnail_loc>\n";
                $entries .= '      <video:title>' . esc_xml($video['name']) . "</video:title>\n";
                $entries .= '      <video:description>' . esc_xml($video['description']) . "</video:description>\n";
                $entries .= '      <video:upload_date>' . esc_xml($video['upload_date']) . "</video:upload_date>\n";
                if ($video['content_url'] !== '') {
                    $entries .= '      <video:content_loc>' . esc_xml($video['content_url']) . "</video:content_loc>\n";
                } elseif ($video['embed_url'] !== '') {
                    $entries .= '      <video:player_loc>' . esc_xml($video['embed_url']) . "</video:player_loc>\n";
                }
                $entries .= "    </video:video>\n  </url>\n";
            }
        }
        return $entries === '' ? '' : '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n" . $entries . "</urlset>\n";
    }

    private function news_sitemap_xml(): string
    {
        $entries = '';
        $cutoff = time() - (2 * DAY_IN_SECONDS);
        $site_name = (string) get_bloginfo('name');
        $language = str_replace('_', '-', (string) get_bloginfo('language')) ?: 'en';
        foreach ($this->pages as $page) {
            $post_id = url_to_postid($page['source_url']);
            if (! $page['indexable'] || $page['title'] === '' || $post_id <= 0 || get_post_type($post_id) !== 'post') {
                continue;
            }
            $published = get_post_time('c', true, $post_id);
            if (! is_string($published) || strtotime($published) < $cutoff) {
                continue;
            }
            $entries .= "  <url>\n    <loc>" . esc_xml($page['url']) . "</loc>\n    <news:news>\n";
            $entries .= '      <news:publication><news:name>' . esc_xml($site_name) . '</news:name><news:language>' . esc_xml($language) . "</news:language></news:publication>\n";
            $entries .= '      <news:publication_date>' . esc_xml($published) . "</news:publication_date>\n";
            $entries .= '      <news:title>' . esc_xml($page['title']) . "</news:title>\n";
            $entries .= "    </news:news>\n  </url>\n";
        }
        return $entries === '' ? '' : '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n" . $entries . "</urlset>\n";
    }

    private function performance_report(string $build_directory): array
    {
        $large_asset_bytes = max(1, (int) ($this->settings['large_asset_kb'] ?? 500)) * 1024;
        $files = [];
        $total_bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($build_directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $size = (int) $file->getSize();
            $total_bytes += $size;
            if ($size >= $large_asset_bytes) {
                $files[] = [
                    'path' => substr($file->getPathname(), strlen($build_directory) + 1),
                    'bytes' => $size,
                ];
            }
        }
        $large_html_bytes = max(1, (int) ($this->settings['large_html_kb'] ?? 200)) * 1024;
        return [
            'schema_version' => 1,
            'generated_at' => gmdate('c'),
            'total_bytes' => $total_bytes,
            'file_count' => iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($build_directory, \FilesystemIterator::SKIP_DOTS))),
            'large_files' => $files,
            'large_html_pages' => array_values(array_map(
                static fn (array $page): array => ['url' => $page['url'], 'bytes' => $page['html_bytes']],
                array_filter($this->pages, static fn (array $page): bool => $page['html_bytes'] >= $large_html_bytes)
            )),
            'render_blocking_resources' => array_values(array_map(
                static fn (array $page): array => ['url' => $page['url'], 'count' => $page['render_blocking']],
                array_filter($this->pages, static fn (array $page): bool => $page['render_blocking'] > 0)
            )),
        ];
    }

    private function report_html(): string
    {
        $summary = (array) ($this->report['summary'] ?? []);
        $rows = '';
        foreach ($this->issues as $issue) {
            $rows .= '<tr><td>' . esc_html(ucfirst($issue['severity'])) . '</td><td><code>' . esc_html($issue['code']) . '</code></td><td>' . esc_html($issue['message']) . '</td><td><a href="' . esc_url($issue['url']) . '">' . esc_html($issue['url']) . '</a></td></tr>';
        }
        return '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>WP Static Publisher SEO Report</title><style>body{font:15px system-ui;margin:32px;color:#172033}table{border-collapse:collapse;width:100%}th,td{border:1px solid #d8e0ea;padding:10px;text-align:left;vertical-align:top}th{background:#eef2f7}.summary{display:flex;gap:12px;margin:20px 0}.summary span{padding:12px 16px;border-radius:8px;background:#eef2ff}code{white-space:nowrap}</style><h1>WP Static Publisher SEO Report</h1><p>Target: ' . esc_html($this->target) . '</p><div class="summary"><span>Pages: ' . esc_html((string) ($summary['pages'] ?? 0)) . '</span><span>Errors: ' . esc_html((string) ($summary['errors'] ?? 0)) . '</span><span>Warnings: ' . esc_html((string) ($summary['warnings'] ?? 0)) . '</span><span>Info: ' . esc_html((string) ($summary['info'] ?? 0)) . '</span></div><table><thead><tr><th>Severity</th><th>Code</th><th>Message</th><th>URL</th></tr></thead><tbody>' . $rows . '</tbody></table></html>';
    }

    private function custom_redirect_rules(): array
    {
        $rules = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($this->settings['redirect_rules'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 2) {
                continue;
            }
            $source = '/' . ltrim((string) $parts[0], '/');
            $target = (string) $parts[1];
            $status = isset($parts[2]) && in_array((int) $parts[2], [301, 302, 303, 307, 308], true) ? (int) $parts[2] : 301;
            if (! str_starts_with($target, '/') && preg_match('#^https?://#i', $target) !== 1) {
                continue;
            }
            $rules[] = ['source' => $source, 'target' => $target, 'status' => $status];
        }
        return $rules;
    }

    private function old_slug_redirects(): array
    {
        $rules = [];
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => '_wp_old_slug',
            'fields' => 'all',
            'no_found_rows' => true,
        ]);
        foreach ($posts as $post) {
            $target = get_permalink($post);
            if (! is_string($target) || $target === '') {
                continue;
            }
            $target_path = (string) wp_parse_url(str_replace($this->origin, $this->target, $target), PHP_URL_PATH);
            foreach (get_post_meta((int) $post->ID, '_wp_old_slug', false) as $old_slug) {
                $old_slug = sanitize_title((string) $old_slug);
                if ($old_slug === '') {
                    continue;
                }
                $source = preg_replace('#/' . preg_quote((string) $post->post_name, '#') . '/?$#', '/' . $old_slug . '/', $target_path);
                if (is_string($source) && $source !== $target_path) {
                    $rules[] = $source . ' ' . $target_path . ' 301';
                }
            }
        }
        return array_values(array_unique($rules));
    }

    private function plugin_redirects(): array
    {
        global $wpdb;
        $rules = [];
        if (is_object($wpdb)) {
            $table = $wpdb->prefix . 'redirection_items';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                $items = $wpdb->get_results("SELECT url, action_data, action_code, regex FROM {$table} WHERE status = 'enabled' AND action_type = 'url'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                foreach ((array) $items as $item) {
                    if ((int) ($item['regex'] ?? 0) !== 0) {
                        continue;
                    }
                    $source = '/' . ltrim((string) ($item['url'] ?? ''), '/');
                    $target = (string) ($item['action_data'] ?? '');
                    $status = in_array((int) ($item['action_code'] ?? 301), [301, 302, 303, 307, 308], true) ? (int) $item['action_code'] : 301;
                    if ($source !== '/' && $target !== '' && (str_starts_with($target, '/') || preg_match('#^https?://#i', $target) === 1)) {
                        $rules[] = $source . ' ' . $target . ' ' . $status;
                    }
                }
            }
        }

        $rank_math_posts = get_posts(['post_type' => 'rank_math_redirection', 'post_status' => 'publish', 'posts_per_page' => -1]);
        foreach ($rank_math_posts as $redirection) {
            $target = (string) get_post_meta((int) $redirection->ID, 'rank_math_redirection_url', true);
            $status = (int) get_post_meta((int) $redirection->ID, 'rank_math_redirection_status_code', true);
            $status = in_array($status, [301, 302, 303, 307, 308], true) ? $status : 301;
            $sources = get_post_meta((int) $redirection->ID, 'rank_math_redirection_sources', true);
            foreach (is_array($sources) ? $sources : [] as $source) {
                if (($source['comparison'] ?? 'exact') !== 'exact' || ($source['ignore'] ?? '') !== '') {
                    continue;
                }
                $path = '/' . ltrim((string) ($source['pattern'] ?? ''), '/');
                if ($path !== '/' && $target !== '') {
                    $rules[] = $path . ' ' . $target . ' ' . $status;
                }
            }
        }
        return array_values(array_unique(apply_filters('wext_static_redirect_rules', $rules)));
    }

    private function public_url(string $relative_path): string
    {
        $path = '/' . ltrim($relative_path, '/');
        if ($path === '/index.html') {
            return $this->target . '/';
        }
        if (str_ends_with($path, '/index.html')) {
            return $this->target . substr($path, 0, -10);
        }
        return $this->target . $path;
    }

    private function absolute_public_url(string $candidate, string $base_url): ?string
    {
        $candidate = html_entity_decode(trim($candidate));
        if ($candidate === '' || str_starts_with($candidate, '#') || preg_match('#^(?:mailto|tel|javascript|data):#i', $candidate)) {
            return null;
        }
        if (str_starts_with($candidate, '//')) {
            $candidate = (string) wp_parse_url($this->target, PHP_URL_SCHEME) . ':' . $candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $candidate = $this->target . $candidate;
        } elseif (preg_match('#^https?://#i', $candidate) !== 1) {
            $candidate = rtrim(dirname($base_url), '/') . '/' . $candidate;
        }
        if (strtolower((string) wp_parse_url($candidate, PHP_URL_HOST)) !== strtolower((string) wp_parse_url($this->target, PHP_URL_HOST))) {
            return null;
        }
        return strtok($candidate, '#') ?: $candidate;
    }

    private function public_url_exists(string $url, string $build_directory): bool
    {
        $path = rawurldecode((string) wp_parse_url($url, PHP_URL_PATH));
        if ($path === '' || $path === '/') {
            $path = '/index.html';
        } elseif (str_ends_with($path, '/')) {
            $path .= 'index.html';
        }
        return is_file($build_directory . '/' . ltrim($path, '/'));
    }

    private function last_modified(string $source_url): string
    {
        $post_id = url_to_postid($source_url);
        if ($post_id <= 0) {
            return '';
        }
        $modified = get_post_modified_time('c', true, $post_id);
        return is_string($modified) ? $modified : '';
    }

    private function schema_types(array $value): array
    {
        $types = [];
        foreach ($value as $key => $item) {
            if ($key === '@type') {
                foreach ((array) $item as $type) {
                    if (is_string($type)) {
                        $types[] = $type;
                    }
                }
            } elseif (is_array($item)) {
                $types = array_merge($types, $this->schema_types($item));
            }
        }
        return $types;
    }

    private function validate_schema_nodes(array $value, string $url): void
    {
        $requirements = [
            'Article' => ['headline', 'image', 'datePublished', 'author'],
            'NewsArticle' => ['headline', 'image', 'datePublished', 'author'],
            'BlogPosting' => ['headline', 'image', 'datePublished', 'author'],
            'BreadcrumbList' => ['itemListElement'],
            'LocalBusiness' => ['name', 'address'],
            'Organization' => ['name', 'url'],
            'Product' => ['name', 'image'],
            'VideoObject' => ['name', 'description', 'thumbnailUrl', 'uploadDate'],
        ];
        foreach ((array) ($value['@type'] ?? []) as $schema_type) {
            if (! is_string($schema_type) || ! isset($requirements[$schema_type])) {
                continue;
            }
            foreach ($requirements[$schema_type] as $required) {
                if (! isset($value[$required]) || $value[$required] === '' || $value[$required] === []) {
                    $this->issue('warning', 'schema_missing_property', sprintf(__('Structured data type %1$s is missing property %2$s.', 'wext-static-publisher'), $schema_type, $required), $url, ['type' => $schema_type, 'property' => $required]);
                }
            }
            if ($schema_type === 'Product' && ! isset($value['offers']) && ! isset($value['review']) && ! isset($value['aggregateRating'])) {
                $this->issue('warning', 'schema_missing_product_result', __('Product structured data has no offers, review, or aggregate rating.', 'wext-static-publisher'), $url);
            }
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $this->validate_schema_nodes($item, $url);
            }
        }
    }

    private function video_schema_nodes(array $value): array
    {
        $videos = [];
        if (in_array('VideoObject', (array) ($value['@type'] ?? []), true)) {
            $thumbnail = $value['thumbnailUrl'] ?? '';
            $videos[] = [
                'name' => (string) ($value['name'] ?? ''),
                'description' => (string) ($value['description'] ?? ''),
                'thumbnail' => is_array($thumbnail) ? (string) reset($thumbnail) : (string) $thumbnail,
                'upload_date' => (string) ($value['uploadDate'] ?? ''),
                'content_url' => (string) ($value['contentUrl'] ?? ''),
                'embed_url' => (string) ($value['embedUrl'] ?? ''),
            ];
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $videos = array_merge($videos, $this->video_schema_nodes($item));
            }
        }
        return $videos;
    }

    private function same_url(string $left, string $right): bool
    {
        return untrailingslashit(strtolower($left)) === untrailingslashit(strtolower($right));
    }

    private function upsert_robots_noindex(string $html): string
    {
        if (preg_match('#<meta\b[^>]*\bname\s*=\s*(["\'])robots\1[^>]*>#i', $html) === 1) {
            return preg_replace_callback(
                '#<meta\b[^>]*\bname\s*=\s*(["\'])robots\1[^>]*>#i',
                static function (array $match): string {
                    if (preg_match('#\bcontent\s*=\s*(["\'])(.*?)\1#i', $match[0], $content) === 1) {
                        $directives = array_filter(array_map('trim', explode(',', strtolower($content[2]))));
                        $directives = array_values(array_diff($directives, ['index', 'follow']));
                        $directives = array_values(array_unique(array_merge($directives, ['noindex', 'nofollow'])));
                        return str_replace($content[0], 'content="' . esc_attr(implode(', ', $directives)) . '"', $match[0]);
                    }
                    return rtrim($match[0], '>') . ' content="noindex, nofollow">';
                },
                $html,
                1
            ) ?? $html;
        }
        return $this->inject_into_head($html, '<meta name="robots" content="noindex, nofollow">');
    }

    private function inject_into_head(string $html, string $markup): string
    {
        if (preg_match('/<\/head>/i', $html) === 1) {
            return preg_replace('/<\/head>/i', $markup . "\n</head>", $html, 1) ?? $html;
        }
        return $markup . "\n" . $html;
    }

    private function matches_lines(string $path, string $lines): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', $lines) ?: [] as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            $pattern = '/' . ltrim($pattern, '/');
            if (str_contains($pattern, '*') ? fnmatch($pattern, $path) : str_starts_with($path, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function issue(string $severity, string $code, string $message, string $url, array $context = []): void
    {
        $this->issues[] = compact('severity', 'code', 'message', 'url') + ['context' => $context];
    }
}
