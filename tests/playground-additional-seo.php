<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$activation = activate_plugin('wext-static-publisher/wext-static-publisher.php');
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}
update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, ['active' => '1'], false);

define('SURERANK_VERSION', 'test-surerank');
define('THE_SEO_FRAMEWORK_VERSION', 'test-framework');
define('WPSEO_VERSION', 'test-yoast');

$origin = home_url();
$target = 'https://static.example.com';
$schema = wp_json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'url' => $origin,
    'potentialAction' => ['@type' => 'SearchAction', 'target' => $origin . '/?s={search_term_string}'],
]);
$cases = [
    'surerank' => [
        'constant' => 'SURERANK_VERSION',
        'label' => 'SureRank SEO',
        'block_pattern' => '#<!--\s*SureRank Meta Data\s*-->.*?<!--\s*/SureRank Meta Data\s*-->#is',
        'sitemap_path' => '/sitemap_index.xml',
        'html' => '<!-- SureRank Meta Data --><meta name="description" content="SureRank"><link rel="canonical" href="' . $origin . '/sample/"><script type="application/ld+json" id="surerank-schema">' . $schema . '</script><!-- /SureRank Meta Data -->',
        'sitemap_files' => ['sitemap_index.xml', 'post-type-post-sitemap-1.xml'],
    ],
    'seo_framework' => [
        'constant' => 'THE_SEO_FRAMEWORK_VERSION',
        'label' => 'The SEO Framework',
        'block_pattern' => '#<!--\s*The SEO Framework\b.*?-->.*?<!--\s*/\s*The SEO Framework\b.*?-->#is',
        'sitemap_path' => '/sitemap.xml',
        'html' => '<!-- The SEO Framework by Sybre Waaijer --><meta name="description" content="TSF"><link rel="canonical" href="' . $origin . '/sample/"><script type="application/ld+json">' . $schema . '</script><!-- / The SEO Framework by Sybre Waaijer -->',
        'sitemap_files' => ['sitemap.xml', 'sitemap.xsl'],
    ],
    'yoast' => [
        'constant' => 'WPSEO_VERSION',
        'label' => 'Yoast SEO',
        'block_pattern' => '#<!--\s*This site is optimized with the .*?Yoast SEO.*?-->.*?<!--\s*/\s*Yoast SEO.*?-->#is',
        'sitemap_path' => '/sitemap_index.xml',
        'html' => '<!-- This site is optimized with the Yoast SEO v28.2 - https://yoast.com/ --><meta name="description" content="Yoast"><link rel="canonical" href="' . $origin . '/sample/"><script type="application/ld+json" class="yoast-schema-graph">' . $schema . '</script><!-- / Yoast SEO plugin. -->',
        'sitemap_files' => ['sitemap_index.xml', 'post-sitemap.xml'],
    ],
];

add_filter('pre_http_request', static function ($preempt, array $args, string $url) use ($origin) {
    $responses = [
        '/sitemap_index.xml' => '<?xml version="1.0"?><sitemapindex><sitemap><loc>' . $origin . '/post-sitemap.xml</loc></sitemap><sitemap><loc>' . $origin . '/post-type-post-sitemap-1.xml</loc></sitemap><sitemap><loc>https://external.example/evil-sitemap.xml</loc></sitemap></sitemapindex>',
        '/post-sitemap.xml' => '<?xml version="1.0"?><urlset><url><loc>' . $origin . '/sample/</loc></url></urlset>',
        '/post-type-post-sitemap-1.xml' => '<?xml version="1.0"?><urlset><url><loc>' . $origin . '/sample/</loc></url></urlset>',
        '/sitemap.xml' => '<?xml version="1.0"?><?xml-stylesheet href="' . $origin . '/sitemap.xsl"?><urlset><url><loc>' . $origin . '/sample/</loc></url></urlset>',
        '/sitemap.xsl' => '<?xml version="1.0"?><xsl:stylesheet version="1.0"></xsl:stylesheet>',
        '/robots.txt' => "User-agent: *\nSitemap: {$origin}/sitemap_index.xml\n",
    ];
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if (! isset($responses[$path])) {
        return $preempt;
    }
    return ['headers' => [], 'body' => $responses[$path], 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

foreach ($cases as $prefix => $case) {
    $settings = [
        $prefix . '_enabled' => '1',
        $prefix . '_metadata_pages' => '1',
        $prefix . '_metadata_posts' => '0',
        $prefix . '_metadata_custom_post_types' => '1',
        $prefix . '_metadata_archives' => '1',
        $prefix . '_schema' => '1',
        $prefix . '_sitemaps' => '1',
        $prefix . '_robots' => '1',
    ];
    $integration = new Wext\StaticPublisher\Block_SEO_Integration($origin, $target, array_merge($case, ['prefix' => $prefix]), $settings);
    $post_html = $integration->process_html($case['html'], true, 'arama', 'posts');
    if (str_contains($post_html, 'rel="canonical"') || ! str_contains($post_html, '/arama/?q={search_term_string}')) {
        throw new RuntimeException($case['label'] . ' post metadata veya Schema ayrımı çalışmadı.');
    }
    if (! str_contains($integration->process_html($case['html'], true, 'arama', 'pages'), 'rel="canonical"')) {
        throw new RuntimeException($case['label'] . ' sayfa metadata çıktısı korunmadı.');
    }
    if (str_contains($integration->process_html($case['html'], false, 'arama', 'pages'), 'SearchAction')) {
        throw new RuntimeException($case['label'] . ' SearchAction çıktısı kaldırılamadı.');
    }

    $schema_off = new Wext\StaticPublisher\Block_SEO_Integration($origin, $target, array_merge($case, ['prefix' => $prefix]), array_merge($settings, [$prefix . '_schema' => '0']));
    if (str_contains($schema_off->process_html($case['html'], true, 'arama', 'pages'), 'application/ld+json')) {
        throw new RuntimeException($case['label'] . ' Schema çıktısı kaldırılamadı.');
    }

    $files = [];
    $integration->export_root_files(static function (string $path, string $body) use (&$files): void {
        $files[$path] = $body;
    }, static function (): void {});
    foreach ($case['sitemap_files'] as $path) {
        if (! isset($files[$path]) || str_contains($files[$path], $origin) || ($path !== 'sitemap.xsl' && ! str_contains($files[$path], $target))) {
            throw new RuntimeException($case['label'] . ' sitemap çıktısı eksik: ' . $path . '; bulunan: ' . implode(', ', array_keys($files)));
        }
    }
    if (isset($files['evil-sitemap.xml']) || empty($files['robots.txt'])) {
        throw new RuntimeException($case['label'] . ' robots veya aynı-origin sitemap sınırı çalışmadı.');
    }
    $manifest = $integration->manifest_data();
    if (empty($manifest['active']) || ($manifest['version'] ?? '') !== constant($case['constant'])) {
        throw new RuntimeException($case['label'] . ' manifest bilgisi eksik.');
    }
}

echo "Wext Static Publisher SureRank, The SEO Framework and Yoast integration test passed.\n";
