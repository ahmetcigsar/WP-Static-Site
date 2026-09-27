<?php

declare(strict_types=1);

define('SEOPRESS_VERSION', 'test-version');

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$activation = activate_plugin('wext-static-publisher/wext-static-publisher.php');
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

$settings = [
    'seopress_enabled' => '1',
    'seopress_metadata_pages' => '1',
    'seopress_metadata_posts' => '1',
    'seopress_metadata_custom_post_types' => '1',
    'seopress_metadata_archives' => '1',
    'seopress_schema' => '1',
    'seopress_sitemaps' => '1',
    'seopress_robots' => '1',
];
update_option(Wext\StaticPublisher\Plugin::SEO_PLUGIN_SETTINGS_KEY, $settings);

$origin = home_url();
$target = 'https://static.example.com';
$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [[
        '@type' => 'WebSite',
        'url' => $origin,
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $origin . '/?s={search_term_string}'],
        ],
    ]],
];
$html = '<html><head>'
    . '<meta name="description" content="Description"><meta name="robots" content="index, follow">'
    . '<meta property="og:url" content="' . $origin . '/sample/"><meta name="twitter:card" content="summary">'
    . '<link rel="canonical" href="' . $origin . '/sample/">'
    . '<script id="website-schema" type="application/ld+json">' . wp_json_encode($schema) . '</script>'
    . '</head></html>';

$integration = new Wext\StaticPublisher\SEOPress_Integration($origin, $target);
$processed = $integration->process_html($html, true, 'arama');
if (! str_contains($processed, $target) || ! str_contains($processed, '/arama/?q={search_term_string}')) {
    throw new RuntimeException('SEOPress Schema veya SearchAction hedefi statik domaine dönüştürülemedi.');
}
if (str_contains($integration->process_html($html, false, 'arama'), 'SearchAction')) {
    throw new RuntimeException('Statik arama kapalıyken SEOPress SearchAction kaldırılmadı.');
}

$metadata_disabled = new Wext\StaticPublisher\SEOPress_Integration($origin, $target, array_merge($settings, ['seopress_metadata_posts' => '0']));
$metadata_html = $metadata_disabled->process_html($html, true, 'arama', 'posts');
if (str_contains($metadata_html, 'rel="canonical"') || str_contains($metadata_html, 'property="og:url"') || ! str_contains($metadata_html, 'website-schema')) {
    throw new RuntimeException('SEOPress metadata seçimi Schema çıktısından bağımsız çalışmadı.');
}
if (! str_contains($metadata_disabled->process_html($html, true, 'arama', 'pages'), 'rel="canonical"')) {
    throw new RuntimeException('SEOPress post metadata seçimi sayfa metadata çıktısını etkilememeliydi.');
}

$schema_disabled = new Wext\StaticPublisher\SEOPress_Integration($origin, $target, array_merge($settings, ['seopress_schema' => '0']));
$schema_html = $schema_disabled->process_html($html, true, 'arama');
if (str_contains($schema_html, 'application/ld+json') || ! str_contains($schema_html, 'rel="canonical"')) {
    throw new RuntimeException('SEOPress Schema seçimi metadata çıktısından bağımsız çalışmadı.');
}

add_filter('pre_http_request', static function ($preempt, array $args, string $url) use ($origin) {
    $responses = [
        '/sitemaps.xml' => ['application/xml', '<?xml version="1.0"?><sitemapindex><sitemap><loc>' . $origin . '/post-sitemap.xml</loc></sitemap><sitemap><loc>https://external.example/evil-sitemap.xml</loc></sitemap></sitemapindex>'],
        '/post-sitemap.xml' => ['application/xml', '<?xml version="1.0"?><urlset><url><loc>' . $origin . '/sample/</loc></url></urlset>'],
        '/robots.txt' => ['text/plain', "User-agent: *\nSitemap: {$origin}/sitemaps.xml\n"],
    ];
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if (! isset($responses[$path])) {
        return $preempt;
    }
    return ['headers' => ['content-type' => $responses[$path][0]], 'body' => $responses[$path][1], 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

$files = [];
$result = $integration->export_root_files(
    static function (string $path, string $contents) use (&$files): void {
        $files[$path] = $contents;
    },
    static function (): void {}
);
if (($result['sitemaps'] ?? 0) !== 2 || empty($result['robots'])) {
    throw new RuntimeException('SEOPress sitemap veya robots çıktıları alınamadı.');
}
foreach (['sitemaps.xml', 'post-sitemap.xml', 'robots.txt'] as $path) {
    if (! isset($files[$path]) || str_contains($files[$path], $origin) || ! str_contains($files[$path], $target)) {
        throw new RuntimeException("SEOPress dosyası hedef domaine dönüştürülemedi: {$path}");
    }
}
if (isset($files['evil-sitemap.xml'])) {
    throw new RuntimeException('Harici SEOPress sitemap adresi export edilmemeliydi.');
}

$disabled = new Wext\StaticPublisher\SEOPress_Integration($origin, $target, ['seopress_enabled' => '0']);
$disabled_html = $disabled->process_html($html, true, 'arama');
if (str_contains($disabled_html, 'rel="canonical"') || str_contains($disabled_html, 'application/ld+json')) {
    throw new RuntimeException('Kapalı SEOPress entegrasyonu metadata ve Schema çıktılarını kaldırmadı.');
}

$manifest = $integration->manifest_data();
if (empty($manifest['active']) || ($manifest['version'] ?? '') !== 'test-version' || ($manifest['settings']['seopress_metadata_archives'] ?? '') !== '1') {
    throw new RuntimeException('SEOPress manifest bilgisi eksik.');
}

echo "WP Static Publisher SEOPress integration test passed.\n";
