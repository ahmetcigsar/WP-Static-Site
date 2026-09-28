<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$activation = activate_plugin('wext-static-publisher/wext-static-publisher.php');
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

$inactive = new Wext\StaticPublisher\SmartCrawl_Integration(home_url(), 'https://static.example.com');
$untouched = '<meta name="description" content="Other plugin"><script type="application/ld+json">{}</script>';
if ($inactive->enabled() || $inactive->process_html($untouched, false, 'search') !== $untouched) {
    throw new RuntimeException('Inactive SmartCrawl must not alter HTML.');
}
define('SMARTCRAWL_VERSION', 'test-version');

$settings = [
    'smartcrawl_enabled' => '1',
    'smartcrawl_metadata_pages' => '1',
    'smartcrawl_metadata_posts' => '1',
    'smartcrawl_metadata_custom_post_types' => '1',
    'smartcrawl_metadata_archives' => '1',
    'smartcrawl_schema' => '1',
    'smartcrawl_sitemaps' => '1',
    'smartcrawl_robots' => '1',
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

$integration = new Wext\StaticPublisher\SmartCrawl_Integration($origin, $target);
$processed = $integration->process_html($html, true, 'arama');
if (! str_contains($processed, $target) || ! str_contains($processed, '/arama/?q={search_term_string}')) {
    throw new RuntimeException('SmartCrawl Schema veya SearchAction hedefi statik domaine dönüştürülemedi.');
}
if (str_contains($integration->process_html($html, false, 'arama'), 'SearchAction')) {
    throw new RuntimeException('Statik arama kapalıyken SmartCrawl SearchAction kaldırılmadı.');
}

$metadata_disabled = new Wext\StaticPublisher\SmartCrawl_Integration($origin, $target, array_merge($settings, ['smartcrawl_metadata_posts' => '0']));
$metadata_html = $metadata_disabled->process_html($html, true, 'arama', 'posts');
if (str_contains($metadata_html, 'rel="canonical"') || str_contains($metadata_html, 'property="og:url"') || ! str_contains($metadata_html, 'website-schema')) {
    throw new RuntimeException('SmartCrawl metadata seçimi Schema çıktısından bağımsız çalışmadı.');
}
if (! str_contains($metadata_disabled->process_html($html, true, 'arama', 'pages'), 'rel="canonical"')) {
    throw new RuntimeException('SmartCrawl post metadata seçimi sayfa metadata çıktısını etkilememeliydi.');
}

$schema_disabled = new Wext\StaticPublisher\SmartCrawl_Integration($origin, $target, array_merge($settings, ['smartcrawl_schema' => '0']));
$schema_html = $schema_disabled->process_html($html, true, 'arama');
if (str_contains($schema_html, 'application/ld+json') || ! str_contains($schema_html, 'rel="canonical"')) {
    throw new RuntimeException('SmartCrawl Schema seçimi metadata çıktısından bağımsız çalışmadı.');
}

add_filter('pre_http_request', static function ($preempt, array $args, string $url) use ($origin) {
    $responses = [
        '/sitemap.xml' => ['application/xml', '<?xml version="1.0"?><?xml-stylesheet href="' . $origin . '/?wds_sitemap_styling=1&amp;template=sitemapIndexBody"?><sitemapindex><sitemap><loc>' . $origin . '/post-sitemap.xml</loc></sitemap><sitemap><loc>https://external.example/evil-sitemap.xml</loc></sitemap></sitemapindex>'],
        '/post-sitemap.xml' => ['application/xml', '<?xml version="1.0"?><urlset><url><loc>' . $origin . '/sample/</loc></url></urlset>'],
        '/' => ['text/xsl', '<?xml version="1.0"?><xsl:stylesheet version="2.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"></xsl:stylesheet>'],
        '/robots.txt' => ['text/plain', "User-agent: *\nSitemap: {$origin}/sitemap.xml\n"],
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
    throw new RuntimeException('SmartCrawl sitemap veya robots çıktıları alınamadı.');
}
foreach (['sitemap.xml', 'post-sitemap.xml', 'robots.txt'] as $path) {
    if (! isset($files[$path]) || str_contains($files[$path], $origin) || ! str_contains($files[$path], $target)) {
        throw new RuntimeException("SmartCrawl dosyası hedef domaine dönüştürülemedi: {$path}");
    }
}
if (empty($files['smartcrawl-sitemapIndexBody.xsl']) || str_contains($files['sitemap.xml'], 'wds_sitemap_styling') || ! str_contains($files['sitemap.xml'], 'smartcrawl-sitemapIndexBody.xsl')) {
    throw new RuntimeException('SmartCrawl dynamic XSL was not exported as a static stylesheet.');
}
if (isset($files['evil-sitemap.xml'])) {
    throw new RuntimeException('Harici SmartCrawl sitemap adresi export edilmemeliydi.');
}

$disabled = new Wext\StaticPublisher\SmartCrawl_Integration($origin, $target, ['smartcrawl_enabled' => '0']);
$disabled_html = $disabled->process_html($html, true, 'arama');
if (str_contains($disabled_html, 'rel="canonical"') || str_contains($disabled_html, 'application/ld+json')) {
    throw new RuntimeException('Kapalı SmartCrawl entegrasyonu metadata ve Schema çıktılarını kaldırmadı.');
}

foreach (['pages', 'posts', 'custom_post_types', 'archives'] as $group) {
    $selective = new Wext\StaticPublisher\SmartCrawl_Integration($origin, $target, array_merge($settings, ['smartcrawl_metadata_' . $group => '0']));
    if (str_contains($selective->process_html($html, true, 'arama', $group), 'rel="canonical"')) {
        throw new RuntimeException('Metadata group toggle failed: ' . $group);
    }
}
$none = new Wext\StaticPublisher\SmartCrawl_Integration($origin, $target, array_merge($settings, ['smartcrawl_sitemaps' => '0', 'smartcrawl_robots' => '0']));
$none->export_root_files(static function (): void { throw new RuntimeException('Disabled root file exported.'); }, static function (): void {});
$list_schema = '<script type="application/ld+json">' . wp_json_encode([
    '@type' => 'WebSite',
    'potentialAction' => [['@type' => 'SearchAction'], ['@type' => 'ReadAction', 'name' => '</script><script>example</script>']],
]) . '</script>';
$list_output = $integration->process_html($list_schema, false, 'search');
preg_match('#<script[^>]*>(.*?)</script>#s', $list_output, $list_match);
$list_data = json_decode($list_match[1], true);
if (! is_array($list_data) || ! array_is_list($list_data['potentialAction']) || count($list_data['potentialAction']) !== 1 || substr_count($list_output, '</script>') !== 1) {
    throw new RuntimeException('Schema action arrays or script escaping were corrupted.');
}
$form_values = $settings;
unset($form_values['smartcrawl_metadata_posts']);
$once = Wext\StaticPublisher\Admin::sanitize_seo_plugin_settings($form_values);
$twice = Wext\StaticPublisher\Admin::sanitize_seo_plugin_settings($once);
if ($once !== $twice || $twice['smartcrawl_metadata_posts'] !== '0' || $twice['smartcrawl_metadata_pages'] !== '1') {
    throw new RuntimeException('Repeated WordPress sanitization re-enabled an unchecked SEO setting.');
}
$manifest = $integration->manifest_data();
if (empty($manifest['active']) || ($manifest['version'] ?? '') !== 'test-version' || ($manifest['settings']['smartcrawl_metadata_archives'] ?? '') !== '1') {
    throw new RuntimeException('SmartCrawl manifest bilgisi eksik.');
}

echo "Wext Static Publisher SmartCrawl integration test passed.\n";
