<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'wext-static-publisher/wext-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

do_action('init');
update_option('wext_static_settings', [
    'target_url' => 'https://static.example.com',
    'maximum_urls' => 50,
    'excluded_paths' => "/wp-admin/\n/wp-login.php\n/wp-json/\n/feed/",
    'auto_export' => '0',
    'headless_enabled' => '1',
    'headless_frontend_behavior' => '404',
]);
update_option(Wext\StaticPublisher\Plugin::LANGUAGE_SETTINGS_KEY, [
    'enabled' => '1',
    'supported_languages' => "tr\nen",
    'default_language' => 'tr',
    'cookie_days' => 365,
]);
update_option(Wext\StaticPublisher\Plugin::HIDE_SETTINGS_KEY, [
    'wp_content_directory' => 'assets',
    'wp_includes_directory' => 'core',
    'uploads_directory' => 'media',
    'plugins_directory' => 'extensions',
    'themes_directory' => 'skins',
    'theme_style_name' => 'design',
    'author_url' => 'writers',
    'hide_wordpress_version' => '1',
    'hide_generator_meta' => '1',
    'hide_wordpress_dns_prefetch' => '1',
    'hide_rsd_header' => '1',
    'disable_xml_rpc' => '1',
    'disable_embed_scripts' => '1',
    'disable_db_debug' => '1',
    'disable_wlw_manifest' => '1',
    'disable_emojis' => '1',
]);
update_option(Wext\StaticPublisher\Plugin::SEARCH_SETTINGS_KEY, array_merge(
    Wext\StaticPublisher\Plugin::search_defaults(),
    [
        'enabled' => '1',
        'page_path' => 'arama',
        'title_selector' => 'title',
        'content_selector' => 'body',
        'excerpt_selector' => '.entry-content',
        'exclude_urls' => "not-found-test\nforbidden-test",
    ]
));
update_option(Wext\StaticPublisher\Plugin::SEO_SETTINGS_KEY, array_merge(
    Wext\StaticPublisher\Plugin::seo_defaults(),
    [
        'redirect_rules' => "/legacy/ /new-location/ 301\n/chain/ /legacy/ 301",
        'sitemap_news' => '1',
        'noindex_paths' => "/en/",
        'x_robots_rules' => "*.pdf|noindex, nofollow",
        'indexnow_enabled' => '1',
        'indexnow_key' => 'test-indexnow-key',
    ]
));

$post_id = wp_insert_post([
    'post_title' => 'Export testi',
    'post_name' => 'export-testi',
    'post_content' => '<p>Statik export entegrasyon testi.</p>',
    'post_status' => 'publish',
    'post_type' => 'post',
], true);
if (is_wp_error($post_id)) {
    throw new RuntimeException($post_id->get_error_message());
}

$job_id = 'integration-test';
add_filter('wext_static_seed_urls', static function (array $urls): array {
    $urls[] = home_url('/redirect-test/');
    $urls[] = home_url('/not-found-test/');
    $urls[] = home_url('/forbidden-test/');
    $urls[] = home_url('/wp-content/themes/test-theme/style.css');
    return $urls;
});
add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    if (strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))
        && ((string) ($args['headers']['X-Wext-Static-Export'] ?? '') !== '1'
            || empty($args['headers']['X-Wext-Static-Timestamp'])
            || empty($args['headers']['X-Wext-Static-Signature']))) {
        throw new RuntimeException('Headless export isteği geçerli imza başlıkları olmadan gönderildi: ' . $url);
    }
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if (! in_array($path, ['/tr/', '/en/', '/redirect-test/', '/not-found-test/', '/forbidden-test/', '/wp-content/themes/test-theme/style.css'], true)) {
        return $preempt;
    }

    if (in_array($path, ['/tr/', '/en/'], true)) {
        $language = trim($path, '/');
        return [
            'headers' => ['content-type' => 'text/html; charset=UTF-8'],
            'body' => '<!doctype html><html lang="' . $language . '"><head>'
                . '<link rel="alternate" hreflang="tr" href="' . home_url('/tr/') . '">'
                . '<link rel="alternate" hreflang="en" href="' . home_url('/en/') . '">'
                . '</head><body>Dil: ' . $language . '</body></html>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    if ($path === '/wp-content/themes/test-theme/style.css') {
        return [
            'headers' => ['content-type' => 'text/css; charset=UTF-8'],
            'body' => ".hero{background:url('/wp-content/uploads/test.png')}",
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    $status_code = $path === '/not-found-test/' ? 404 : ($path === '/forbidden-test/' ? 403 : 200);
    $response_object = (object) ['history' => []];
    if ($path === '/redirect-test/') {
        $response_object->history[] = (object) ['status_code' => 301];
    }
    $http_response = new class($response_object) {
        public function __construct(private object $response)
        {
        }

        public function get_response_object(): object
        {
            return $this->response;
        }
    };

    return [
        'headers' => ['content-type' => 'text/html; charset=UTF-8'],
        'body' => '<!doctype html><html><body>HTTP durum kodu testi</body></html>',
        'response' => ['code' => $status_code, 'message' => 'Test'],
        'cookies' => [],
        'filename' => null,
        'http_response' => $http_response,
    ];
}, 10, 3);
$reported_progress = [];
add_action('update_option_' . Wext\StaticPublisher\Plugin::STATUS_KEY, static function ($old_value, $new_value) use (&$reported_progress): void {
    if (($new_value['job_id'] ?? '') === 'integration-test' && ($new_value['state'] ?? '') !== '') {
        $reported_progress[] = (int) ($new_value['progress'] ?? 0);
    }
}, 10, 2);
Wext\StaticPublisher\Plugin::set_status($job_id, 'running', 0, ['source' => 'test']);
$status = (new Wext\StaticPublisher\Exporter())->run($job_id);

if (($status['state'] ?? '') !== 'completed') {
    throw new RuntimeException('Export tamamlanmadı.');
}
$required_progress = [2, 85, 88, 92, 96, 100];
if (array_diff($required_progress, $reported_progress) !== []) {
    throw new RuntimeException('Export aşamalarının ilerleme yüzdeleri eksik: ' . wp_json_encode($reported_progress));
}
for ($index = 1, $count = count($reported_progress); $index < $count; $index++) {
    if ($reported_progress[$index] < $reported_progress[$index - 1]) {
        throw new RuntimeException('Export ilerleme yüzdesi geriye gitti: ' . wp_json_encode($reported_progress));
    }
}
if (! is_readable((string) ($status['archive'] ?? ''))) {
    throw new RuntimeException('Export arşivi üretilemedi.');
}
if (($status['manifest']['target'] ?? '') !== 'https://static.example.com') {
    throw new RuntimeException('Hedef domain manifest içine doğru yazılmadı.');
}
$last_completed_at = (string) ($status['last_completed_at'] ?? '');
if ($last_completed_at === '' || strtotime($last_completed_at) === false) {
    throw new RuntimeException('Son statik oluşturma zamanı kaydedilmedi.');
}
if (array_key_exists('log', get_option(Wext\StaticPublisher\Plugin::STATUS_KEY, []))) {
    throw new RuntimeException('Activity Log veritabanındaki export durumuna yazıldı.');
}
$activity = Wext\StaticPublisher\Activity_Log::page(1);
if (($activity['job_id'] ?? '') !== $job_id || ($activity['total'] ?? 0) < 2) {
    throw new RuntimeException('Son export Activity Log dosyasına yazılmadı.');
}
foreach ($activity['entries'] as $entry) {
    if (($entry['job_id'] ?? '') !== $job_id) {
        throw new RuntimeException('Activity Log önceki bir static işlemine ait kayıt içeriyor.');
    }
}
$mapped_entries = array_values(array_filter($activity['entries'], static function (array $entry): bool {
    return ($entry['source_url'] ?? '') !== ''
        && ($entry['static_path'] ?? '') !== ''
        && is_int($entry['status_code'] ?? null);
}));
if ($mapped_entries === []) {
    throw new RuntimeException('Export Activity Log kaynağı ve statik adresi ayrı alanlarda saklamadı.');
}
$status_codes_by_source = [];
foreach ($activity['entries'] as $entry) {
    $status_codes_by_source[(string) ($entry['source_url'] ?? '')] = $entry['status_code'] ?? null;
}
if (($status_codes_by_source[home_url('/redirect-test/')] ?? null) !== 301
    || ($status_codes_by_source[home_url('/not-found-test/')] ?? null) !== 404
    || ($status_codes_by_source[home_url('/forbidden-test/')] ?? null) !== 403) {
    throw new RuntimeException('HTTP 301, 404 ve 403 kaynak kodları Activity Log içine doğru kaydedilmedi.');
}

$zip = new ZipArchive();
if ($zip->open((string) $status['archive']) !== true) {
    throw new RuntimeException('Export ZIP dosyası açılamadı.');
}
$home_html = (string) $zip->getFromName('index.html');
$hidden_theme_css = (string) $zip->getFromName('assets/skins/test-theme/design.css');
$headers_file = (string) $zip->getFromName('_headers');
$manifest_file = json_decode((string) $zip->getFromName('wext-static-manifest.json'), true);
$search_page = (string) $zip->getFromName('arama/index.html');
$language_config = json_decode((string) $zip->getFromName('wext-language-config.json'), true);
$language_script = (string) $zip->getFromName('wext-language-preference.js');
$turkish_home = (string) $zip->getFromName('tr/index.html');
$english_home = (string) $zip->getFromName('en/index.html');
$search_index = json_decode((string) $zip->getFromName('wext-static-search-index.json'), true);
$search_config = json_decode((string) $zip->getFromName('wext-static-search-config.json'), true);
$search_script = (string) $zip->getFromName('wext-static-search-assets/wext-static-search.js');
$seo_report = json_decode((string) $zip->getFromName('wext-seo-report.json'), true);
$seo_report_html = (string) $zip->getFromName('wext-seo-report.html');
$performance_report = json_decode((string) $zip->getFromName('wext-performance-report.json'), true);
$wext_sitemap = (string) $zip->getFromName('wext-sitemap.xml');
$news_sitemap = (string) $zip->getFromName('wext-news-sitemap.xml');
$robots_file = (string) $zip->getFromName('robots.txt');
$redirects_file = (string) $zip->getFromName('_redirects');
$indexnow_key_file = (string) $zip->getFromName('test-indexnow-key.txt');
$zip->close();
if (str_contains($home_html, 'https://static.example.com/wp-content/')) {
    preg_match_all('#https://static\.example\.com/wp-content/[^"\'\s<]+#', $home_html, $remaining_assets);
    throw new RuntimeException('Asset URL adresleri preview-safe kök yola dönüştürülmedi: ' . wp_json_encode(array_slice(array_unique($remaining_assets[0]), 0, 5)));
}
if (! str_contains($home_html, '/assets/') || str_contains($home_html, '/wp-content/')) {
    throw new RuntimeException('Ana sayfadaki wp-content yolları Hide ayarına göre dönüştürülmedi.');
}
if ($hidden_theme_css === '' || ! str_contains($hidden_theme_css, '/assets/media/test.png')) {
    throw new RuntimeException('Tema stil dosyası veya içindeki uploads yolu Hide ayarına göre dönüştürülmedi.');
}
if (! str_contains($headers_file, '/assets/media/*')) {
    throw new RuntimeException('_headers uploads yolu Hide ayarına göre dönüştürülmedi.');
}
if (! str_contains($headers_file, "/wext-seo-report.json\n  X-Robots-Tag: noindex, nofollow")
    || ! str_contains($headers_file, "/wext-seo-report.html\n  X-Robots-Tag: noindex, nofollow")
    || ! str_contains($headers_file, "/wext-performance-report.json\n  X-Robots-Tag: noindex, nofollow")) {
    throw new RuntimeException('SEO ve performans raporları _headers içinde indekslemeye kapatılmadı.');
}
if (($manifest_file['hide_replacements']['author_url'] ?? '') !== 'writers') {
    throw new RuntimeException('Hide ayarları export manifestine yazılmadı.');
}
if (! is_array($language_config)
    || ($language_config['enabled'] ?? null) !== true
    || ($language_config['default_language'] ?? '') !== 'tr'
    || ($language_config['supported_languages'] ?? []) !== ['tr', 'en']
    || ($manifest_file['language_routing']['schema_version'] ?? 0) !== 1) {
    throw new RuntimeException('Dil yönlendirme yapılandırması export ZIP veya manifest içine doğru yazılmadı.');
}
if ($language_script === ''
    || ! str_contains($turkish_home, 'lang="tr"')
    || ! str_contains($english_home, 'lang="en"')
    || ! str_contains($turkish_home, 'wext-language-preference.js')
    || ! str_contains($english_home, 'hreflang="x-default"')
    || ! str_contains($english_home, 'https://static.example.com/tr/')) {
    throw new RuntimeException('Çoklu dil kökleri, tercih betiği veya hreflang işaretleri doğru export edilmedi.');
}
if ($search_page === '' || ! str_contains($search_page, 'Search This Site')
    || ! is_array($search_index) || $search_index === []
    || ($search_config['tokenMatch'] ?? '') !== 'all'
    || ($search_config['i18n']['noResults'] ?? '') !== 'No results matched your search.'
    || ! is_array($search_config['keys'] ?? null)
    || $search_script === '' || ! str_contains($search_script, 'searchDocuments')) {
    throw new RuntimeException('Statik arama dosyaları export ZIP içine doğru üretilmedi.');
}
if (! str_contains($home_html, 'wext-static-search-bridge.js')) {
    throw new RuntimeException('WordPress arama formlarını yönlendiren statik arama köprüsü HTML içine eklenmedi.');
}
if (($manifest_file['static_search']['document_count'] ?? 0) !== count($search_index)) {
    throw new RuntimeException('Arama doküman sayısı manifest ile eşleşmiyor.');
}
if (! is_array($seo_report)
    || ($seo_report['summary']['pages'] ?? 0) < 2
    || $seo_report_html === ''
    || ! str_contains($seo_report_html, 'noindex,nofollow')
    || ! is_array($performance_report)
    || ($performance_report['file_count'] ?? 0) < 1) {
    throw new RuntimeException('SEO veya performans audit raporu export ZIP içine doğru yazılmadı.');
}
if (! str_contains($wext_sitemap, '<urlset')
    || ! str_contains($wext_sitemap, 'xmlns:image=')
    || str_contains($wext_sitemap, '<loc>https://static.example.com/en/</loc>')
    || ! str_contains($robots_file, 'Sitemap: https://static.example.com/wext-sitemap.xml')
    || ! str_contains($news_sitemap, 'xmlns:news=')
    || ! str_contains($robots_file, 'Sitemap: https://static.example.com/wext-news-sitemap.xml')) {
    throw new RuntimeException('Gelişmiş sitemap veya robots.txt bildirimi doğru üretilmedi: ' . wp_json_encode([
        'has_urlset' => str_contains($wext_sitemap, '<urlset'),
        'has_image_namespace' => str_contains($wext_sitemap, 'xmlns:image='),
        'has_noindex_en' => str_contains($wext_sitemap, '<loc>https://static.example.com/en/</loc>'),
        'has_robots_sitemap' => str_contains($robots_file, 'Sitemap: https://static.example.com/wext-sitemap.xml'),
        'sitemap' => substr($wext_sitemap, 0, 500),
        'robots' => $robots_file,
    ], JSON_UNESCAPED_SLASHES));
}
if (! str_contains($english_home, 'noindex, nofollow')
    || ! str_contains($headers_file, 'X-Robots-Tag: noindex, nofollow')
    || ! str_contains($redirects_file, '/legacy/ /new-location/ 301')
    || ! str_contains($redirects_file, '/chain/ /legacy/ 301')
    || $indexnow_key_file !== 'test-indexnow-key') {
    throw new RuntimeException('Indexing, redirect veya IndexNow doğrulama çıktıları doğru üretilmedi.');
}
if (($manifest_file['seo_toolkit']['page_count'] ?? 0) < 2
    || ! is_array($manifest_file['seo_toolkit']['page_hashes'] ?? null)
    || isset($manifest_file['seo_toolkit']['page_hashes']['https://static.example.com/en/'])) {
    throw new RuntimeException('SEO Toolkit özeti ve indexlenebilir sayfa hashleri manifeste doğru yazılmadı.');
}

update_option(Wext\StaticPublisher\Plugin::INDEXNOW_SNAPSHOT_KEY, [
    'https://static.example.com/deleted/' => 'old-hash',
]);
$indexnow_request = [];
$indexnow_filter = static function ($preempt, array $args, string $url) use (&$indexnow_request) {
    if ($url !== 'https://api.indexnow.org/indexnow') {
        return $preempt;
    }
    $indexnow_request = json_decode((string) ($args['body'] ?? ''), true);
    return [
        'headers' => [],
        'body' => '',
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
};
add_filter('pre_http_request', $indexnow_filter, 20, 3);
Wext\StaticPublisher\Plugin::notify_indexnow($job_id);
remove_filter('pre_http_request', $indexnow_filter, 20);
$indexnow_status = get_option(Wext\StaticPublisher\Plugin::INDEXNOW_STATUS_KEY, []);
if (($indexnow_request['host'] ?? '') !== 'static.example.com'
    || ($indexnow_request['key'] ?? '') !== 'test-indexnow-key'
    || ! in_array('https://static.example.com/deleted/', (array) ($indexnow_request['urlList'] ?? []), true)
    || ($indexnow_status['state'] ?? '') !== 'submitted') {
    throw new RuntimeException('IndexNow değişen ve silinen URL farkı doğru gönderilmedi.');
}

$hide_replacements = new Wext\StaticPublisher\Hide_Replacements(Wext\StaticPublisher\Plugin::hide_settings());
if ($hide_replacements->map_relative_path('wp-includes/js/test.js') !== 'core/js/test.js'
    || $hide_replacements->map_relative_path('author/example/index.html') !== 'writers/example/index.html') {
    throw new RuntimeException('WP-Includes veya yazar yolu Hide ayarına göre eşlenmedi.');
}
$rewritten_paths = $hide_replacements->rewrite_content('https://third.example/wp-content/file.css /wp-content/plugins/demo/app.js /author/example/');
if (! str_contains($rewritten_paths, 'https://third.example/wp-content/file.css')
    || ! str_contains($rewritten_paths, '/assets/extensions/demo/app.js')
    || ! str_contains($rewritten_paths, '/writers/example/')) {
    throw new RuntimeException('Hide içerik dönüşümü harici veya dahili yolları doğru işlemedi.');
}

$wordpress_version = get_bloginfo('version');
$privacy_fixture = '<meta name="generator" content="WordPress ' . $wordpress_version . '">'
    . '<link rel="dns-prefetch" href="//s.w.org">'
    . '<link rel="EditURI" type="application/rsd+xml" href="/xmlrpc.php?rsd">'
    . '<link rel="pingback" href="/xmlrpc.php">'
    . '<link rel="wlwmanifest" href="/wp-includes/wlwmanifest.xml">'
    . '<link rel="stylesheet" id="theme-css" href="/theme.css?ver=' . $wordpress_version . '">'
    . '<script id="wp-embed-js" src="/wp-includes/js/wp-embed.min.js"></script>'
    . '<script id="wp-emoji-settings" type="application/json">{}</script>'
    . '<style id="wp-emoji-styles-inline-css">img.emoji{display:inline}</style>'
    . '<p>Korunacak içerik</p>';
$privacy_output = $hide_replacements->rewrite_html($privacy_fixture);
foreach (['generator', 's.w.org', 'xmlrpc.php', 'wlwmanifest', '?ver=' . $wordpress_version, 'wp-embed', 'wp-emoji'] as $removed_trace) {
    if (stripos($privacy_output, $removed_trace) !== false) {
        throw new RuntimeException('Hide gizlilik seçeneği WordPress izini kaldıramadı: ' . $removed_trace);
    }
}
if (! str_contains($privacy_output, 'Korunacak içerik')) {
    throw new RuntimeException('Hide gizlilik temizliği normal sayfa içeriğini kaldırdı.');
}

Wext\StaticPublisher\Plugin::set_status('next-job', 'queued', 0, ['source' => 'test']);
$queued_status = Wext\StaticPublisher\Plugin::status();
if (($queued_status['last_completed_at'] ?? '') !== $last_completed_at) {
    throw new RuntimeException('Son başarılı statik oluşturma zamanı yeni iş kuyruğunda korunmadı.');
}

echo sprintf("Export integration test passed with %d URLs.\n", (int) ($status['url_count'] ?? 0));
