<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class Static_Search
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = wp_parse_args($settings, Plugin::search_defaults());
    }

    public function enabled(): bool
    {
        return ($this->settings['enabled'] ?? '0') === '1';
    }

    public function extract_document(string $html, string $source_url, string $static_path): ?array
    {
        if (! $this->enabled() || $this->is_excluded($source_url) || ! class_exists(DOMDocument::class)) {
            return null;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $title = $this->extract_selector($xpath, (string) $this->settings['title_selector']);
        $content = $this->extract_selector($xpath, (string) $this->settings['content_selector']);
        $excerpt = $this->extract_selector($xpath, (string) $this->settings['excerpt_selector']);
        if ($title === '') {
            $title = $this->extract_selector($xpath, 'title');
        }
        if ($content === '') {
            $content = $this->extract_selector($xpath, 'body');
        }
        if ($excerpt === '') {
            $excerpt = wp_html_excerpt($content, 240, '…');
        }
        if ($title === '' && $content === '') {
            return null;
        }

        $content = mb_substr($content, 0, (int) $this->settings['content_limit'], 'UTF-8');
        $post_id = url_to_postid($source_url);
        $taxonomies = [];
        if ($post_id > 0) {
            $terms = wp_get_post_terms($post_id, get_object_taxonomies((string) get_post_type($post_id)), ['fields' => 'names']);
            if (! is_wp_error($terms)) {
                $taxonomies = array_values(array_unique(array_map('strval', $terms)));
            }
        }

        $document = [
            'title' => $title,
            'url' => $this->public_path($static_path),
            'excerpt' => mb_substr($excerpt, 0, 500, 'UTF-8'),
            'content' => ($this->settings['index_content'] ?? '0') === '1' ? $content : '',
            'taxonomies' => ($this->settings['index_taxonomies'] ?? '0') === '1' ? $taxonomies : [],
            'type' => $post_id > 0 ? (string) get_post_type($post_id) : 'page',
        ];
        $normalised_parts = [];
        if (($this->settings['index_title'] ?? '0') === '1') {
            $normalised_parts[] = $title;
        }
        if (($this->settings['index_excerpt'] ?? '0') === '1') {
            $normalised_parts[] = $excerpt;
        }
        if (($this->settings['index_taxonomies'] ?? '0') === '1') {
            $normalised_parts[] = implode(' ', $taxonomies);
        }
        $document['normalized'] = $this->normalise_turkish(implode(' ', $normalised_parts));

        return $document;
    }

    public function inject_search_bridge(string $html): string
    {
        if (! $this->enabled() || str_contains($html, 'wext-static-search-bridge.js')) {
            return $html;
        }
        $script = Export_Assets::scripts('wextstat-search-bridge', '/wext-static-search-assets/wext-static-search-bridge.js', true);
        return stripos($html, '</body>') !== false
            ? (preg_replace('/<\/body>/i', $script . '</body>', $html, 1) ?? $html)
            : $html . $script;
    }

    public function build_files(array $documents): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $config = [
            'pagePath' => '/' . trim((string) $this->settings['page_path'], '/') . '/',
            'resultLimit' => (int) $this->settings['result_limit'],
            'minChars' => (int) $this->settings['min_chars'],
            'threshold' => (float) $this->settings['threshold'],
            'tokenMatch' => (string) $this->settings['token_match'],
            'keys' => $this->search_keys(),
            'i18n' => [
                'noResults' => __('No results matched your search.', 'wext-static-publisher'),
                /* translators: %d is the number of search results. */
                'resultsShown' => __('%d results shown.', 'wext-static-publisher'),
                /* translators: %d is the minimum number of characters required to search. */
                'minimumCharacters' => __('Enter at least %d characters to search.', 'wext-static-publisher'),
                'loadError' => __('The search index could not be loaded. Please try again later.', 'wext-static-publisher'),
            ],
        ];
        $page_path = trim((string) $this->settings['page_path'], '/');

        return [
            'wext-static-search-assets/wext-static-search.js' => (string) file_get_contents(WEXTSTAT_DIR . 'assets/search/wext-static-search.js'),
            'wext-static-search-assets/wext-static-search-bridge.js' => $this->bridge_script($config['pagePath']),
            'wext-static-search-assets/wext-static-search.css' => (string) file_get_contents(WEXTSTAT_DIR . 'assets/search/wext-static-search.css'),
            'wext-static-search-index.json' => (string) wp_json_encode(array_values($documents), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'wext-static-search-config.json' => (string) wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $page_path . '/index.html' => $this->search_page(),
        ];
    }

    public function settings(): array
    {
        return $this->settings;
    }

    private function extract_selector(DOMXPath $xpath, string $selectors): string
    {
        foreach (array_filter(array_map('trim', explode(',', $selectors))) as $selector) {
            $query = $this->selector_xpath($selector);
            if ($query === '') {
                continue;
            }
            $nodes = $xpath->query($query);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }
            $parts = [];
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement && strtolower($node->tagName) === 'meta') {
                    $parts[] = $node->getAttribute('content');
                } else {
                    $parts[] = (string) $node->textContent;
                }
            }
            $text = $this->clean_text(implode(' ', $parts));
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private function selector_xpath(string $selector): string
    {
        if (preg_match('/^meta\[([a-zA-Z0-9_-]+)=["\']?([a-zA-Z0-9_:-]+)["\']?\]$/', $selector, $match)) {
            return sprintf('//meta[@%s="%s"]', $match[1], $match[2]);
        }
        if (preg_match('/^#([a-zA-Z0-9_-]+)$/', $selector, $match)) {
            return '//*[@id="' . $match[1] . '"]';
        }
        if (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $selector, $match)) {
            return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $match[1] . ' ")]';
        }
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)\.([a-zA-Z0-9_-]+)$/', $selector, $match)) {
            return '//' . strtolower($match[1]) . '[contains(concat(" ", normalize-space(@class), " "), " ' . $match[2] . ' ")]';
        }
        return preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $selector) ? '//' . strtolower($selector) : '';
    }

    private function clean_text(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function is_excluded(string $url): bool
    {
        $patterns = preg_split('/\r\n|\r|\n/', (string) $this->settings['exclude_urls']) ?: [];
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '' && stripos($url, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    private function public_path(string $static_path): string
    {
        $path = ltrim(str_replace('\\', '/', $static_path), '/');
        if ($path === 'index.html') {
            return '/';
        }
        if (str_ends_with($path, '/index.html')) {
            return '/' . rtrim(substr($path, 0, -10), '/') . '/';
        }
        return '/' . $path;
    }

    private function normalise_turkish(string $text): string
    {
        return strtolower(strtr($text, [
            'Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'I' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U',
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        ]));
    }

    private function search_keys(): array
    {
        $keys = [];
        foreach ([
            'title' => 'title_weight',
            'excerpt' => 'excerpt_weight',
            'content' => 'content_weight',
            'taxonomies' => 'taxonomy_weight',
        ] as $field => $weight_key) {
            if (($this->settings['index_' . $field] ?? '0') === '1') {
                $keys[] = ['name' => $field, 'weight' => (float) $this->settings[$weight_key]];
            }
        }
        $keys[] = ['name' => 'normalized', 'weight' => 0.5];
        return $keys;
    }

    private function bridge_script(string $page_path): string
    {
        return "document.addEventListener('submit',function(event){const form=event.target;if(!(form instanceof HTMLFormElement))return;const input=form.querySelector('input[name=\"s\"],input[type=\"search\"]');if(!input)return;event.preventDefault();location.href=" . wp_json_encode($page_path) . "+'?q='+encodeURIComponent(input.value.trim());});\n";
    }

    private function search_page(): string
    {
        $language = str_replace('_', '-', (string) get_bloginfo('language')) ?: 'en-US';
        return sprintf(
            '<!doctype html><html lang="%1$s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%2$s</title>%6$s</head><body><main class="wext-static-search"><a class="wext-static-search__back" href="/">← %3$s</a><h1>%4$s</h1><form id="wext-static-search-form" role="search"><label for="wext-static-search-input">%2$s</label><div class="wext-static-search__input"><input id="wext-static-search-input" type="search" name="q" autocomplete="off" placeholder="%5$s"><button type="submit">%2$s</button></div></form><p id="wext-static-search-status" role="status" aria-live="polite"></p><div id="wext-static-search-results"></div></main>%7$s</body></html>',
            esc_attr($language),
            esc_html__('Search', 'wext-static-publisher'),
            esc_html__('Home', 'wext-static-publisher'),
            esc_html__('Search This Site', 'wext-static-publisher'),
            esc_attr__('Enter a search term...', 'wext-static-publisher'),
            Export_Assets::styles('wextstat-search', '/wext-static-search-assets/wext-static-search.css'),
            Export_Assets::scripts('wextstat-search', '/wext-static-search-assets/wext-static-search.js', true)
        );
    }
}
