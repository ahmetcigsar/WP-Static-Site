<?php

declare(strict_types=1);

define('RANK_MATH_VERSION', 'test-version');

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'wext-static-publisher/wext-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

update_option(Wext\StaticPublisher\Plugin::SEO_PLUGIN_SETTINGS_KEY, [
    'rank_math_enabled' => '1',
    'rank_math_metadata_pages' => '1',
    'rank_math_metadata_posts' => '1',
    'rank_math_metadata_custom_post_types' => '1',
    'rank_math_metadata_archives' => '1',
    'rank_math_schema' => '1',
    'rank_math_sitemaps' => '1',
    'rank_math_robots' => '1',
]);

$origin = home_url();
$target = 'https://static.example.com';
$integration = new Wext\StaticPublisher\Rank_Math_Integration($origin, $target);
$html = '<html><head><!-- Search Engine Optimization by Rank Math - https://rankmath.com/ -->'
    . '<meta name="robots" content="index, follow"><link rel="canonical" href="' . $origin . '/sample/">'
    . '<script type="application/ld+json" class="rank-math-schema">'
    . wp_json_encode(['@type' => 'WebSite', 'url' => $origin, 'potentialAction' => ['@type' => 'SearchAction', 'target' => $origin . '/?s={search_term_string}', 'query-input' => 'required name=search_term_string']])
    . '</script><!-- /Rank Math WordPress SEO plugin --></head></html>';
$processed = $integration->process_html($html, true, 'arama');
if (! str_contains($processed, 'https://static.example.com') || ! str_contains($processed, '/arama/?q={search_term_string}')) {
    throw new RuntimeException('Rank Math metadata veya SearchAction hedefi statik domaine dönüştürülmedi.');
}
$without_static_search = $integration->process_html($html, false, 'arama');
if (str_contains($without_static_search, 'SearchAction') || str_contains($without_static_search, '?s={search_term_string}')) {
    throw new RuntimeException('Statik arama kapalıyken dinamik Rank Math SearchAction kaldırılmadı.');
}

$post_metadata_disabled = new Wext\StaticPublisher\Rank_Math_Integration($origin, $target, [
    'rank_math_enabled' => '1',
    'rank_math_metadata_pages' => '1',
    'rank_math_metadata_posts' => '0',
    'rank_math_metadata_custom_post_types' => '1',
    'rank_math_metadata_archives' => '1',
]);
if (str_contains($post_metadata_disabled->process_html($html, true, 'arama', 'posts'), 'rel="canonical"')) {
    throw new RuntimeException('Rank Math post metadata kapalıyken canonical kaldırılmadı.');
}
if (! str_contains($post_metadata_disabled->process_html($html, true, 'arama', 'pages'), 'rel="canonical"')) {
    throw new RuntimeException('Rank Math post metadata seçimi sayfa metadata çıktısını etkilememeliydi.');
}

add_filter('pre_http_request', static function ($preempt, array $args, string $url) use ($origin) {
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $responses = [
        '/sitemap_index.xml' => ['application/xml', '<?xml version="1.0"?><sitemapindex><sitemap><loc>' . $origin . '/post-sitemap.xml</loc></sitemap></sitemapindex>'],
        '/post-sitemap.xml' => ['application/xml', '<?xml version="1.0"?><urlset><url><loc>' . $origin . '/sample/</loc></url></urlset>'],
        '/robots.txt' => ['text/plain', "User-agent: *\nSitemap: {$origin}/sitemap_index.xml\n"],
    ];
    if (! isset($responses[$path])) {
        return $preempt;
    }
    return [
        'headers' => ['content-type' => $responses[$path][0]],
        'body' => $responses[$path][1],
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
}, 10, 3);

$files = [];
$logs = [];
$result = $integration->export_root_files(
    static function (string $path, string $contents) use (&$files): void {
        $files[$path] = $contents;
    },
    static function (string $level, string $message, string $url = '') use (&$logs): void {
        $logs[] = compact('level', 'message', 'url');
    }
);

if (($result['sitemaps'] ?? 0) !== 2 || empty($result['robots'])) {
    throw new RuntimeException('Rank Math sitemap veya robots çıktıları alınamadı.');
}
foreach (['sitemap_index.xml', 'post-sitemap.xml', 'robots.txt'] as $path) {
    if (! isset($files[$path]) || str_contains($files[$path], $origin) || ! str_contains($files[$path], $target)) {
        throw new RuntimeException("Rank Math dosyası hedef domaine dönüştürülemedi: {$path}");
    }
}

$disabled = new Wext\StaticPublisher\Rank_Math_Integration($origin, $target, [
    'rank_math_enabled' => '0',
]);
if (str_contains($disabled->process_html($html, true, 'arama'), 'Rank Math')) {
    throw new RuntimeException('Kapalı Rank Math entegrasyonu metadata bloğunu kaldırmadı.');
}

echo "WP Static Publisher Rank Math integration test passed.\n";
