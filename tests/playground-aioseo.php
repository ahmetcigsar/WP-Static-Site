<?php

declare(strict_types=1);

define('AIOSEO_VERSION', 'test-version');

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'wext-static-publisher/wext-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}
update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, ['active' => '1'], false);

$enabled_settings = [
    'aioseo_enabled' => '1',
    'aioseo_metadata_pages' => '1',
    'aioseo_metadata_posts' => '1',
    'aioseo_metadata_custom_post_types' => '1',
    'aioseo_metadata_archives' => '1',
    'aioseo_schema' => '1',
    'aioseo_sitemaps' => '1',
    'aioseo_robots' => '1',
];
update_option(Wext\StaticPublisher\Plugin::SEO_PLUGIN_SETTINGS_KEY, $enabled_settings);

$origin = home_url();
$target = 'https://static.example.com';
$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [[
        '@type' => 'WebSite',
        'url' => $origin,
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => $origin . '/?s={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ]],
];
$html = '<html><head><!-- All in One SEO 5.0.0.1 - aioseo.com -->'
    . '<meta name="robots" content="index, follow"><link rel="canonical" href="' . $origin . '/sample/">'
    . '<script type="application/ld+json" class="aioseo-schema">' . wp_json_encode($schema) . '</script>'
    . '<!-- All in One SEO --></head></html>';

$integration = new Wext\StaticPublisher\AIOSEO_Integration($origin, $target);
$processed = $integration->process_html($html, true, 'arama');
if (! str_contains($processed, 'https://static.example.com') || ! str_contains($processed, '/arama/?q={search_term_string}')) {
    throw new RuntimeException('AIOSEO Schema veya SearchAction hedefi statik domaine dönüştürülemedi.');
}
$without_static_search = $integration->process_html($html, false, 'arama');
if (str_contains($without_static_search, 'SearchAction') || str_contains($without_static_search, '?s={search_term_string}')) {
    throw new RuntimeException('Statik arama kapalıyken dinamik AIOSEO SearchAction kaldırılmadı.');
}

$metadata_disabled = new Wext\StaticPublisher\AIOSEO_Integration($origin, $target, array_merge($enabled_settings, [
    'aioseo_metadata_posts' => '0',
]));
$metadata_disabled_html = $metadata_disabled->process_html($html, true, 'arama', 'posts');
if (str_contains($metadata_disabled_html, 'rel="canonical"') || ! str_contains($metadata_disabled_html, 'aioseo-schema')) {
    throw new RuntimeException('AIOSEO metadata seçimi Schema çıktısından bağımsız çalışmadı.');
}
if (! str_contains($metadata_disabled->process_html($html, true, 'arama', 'pages'), 'rel="canonical"')) {
    throw new RuntimeException('AIOSEO post metadata seçimi sayfa metadata çıktısını etkilememeliydi.');
}

$schema_disabled = new Wext\StaticPublisher\AIOSEO_Integration($origin, $target, array_merge($enabled_settings, [
    'aioseo_schema' => '0',
]));
if (str_contains($schema_disabled->process_html($html, true, 'arama'), 'aioseo-schema')) {
    throw new RuntimeException('AIOSEO Schema kapalıyken JSON-LD çıktısı kaldırılmadı.');
}

add_filter('pre_http_request', static function ($preempt, array $args, string $url) use ($origin) {
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $responses = [
        '/sitemap.xml' => ['application/xml', '<?xml version="1.0"?><sitemapindex><sitemap><loc>' . $origin . '/post-sitemap.xml</loc></sitemap><sitemap><loc>https://external.example/evil-sitemap.xml</loc></sitemap></sitemapindex>'],
        '/post-sitemap.xml' => ['application/xml', '<?xml version="1.0"?><urlset><url><loc>' . $origin . '/sample/</loc></url></urlset>'],
        '/robots.txt' => ['text/plain', "User-agent: *\nSitemap: {$origin}/sitemap.xml\n"],
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
    throw new RuntimeException('AIOSEO sitemap veya robots çıktıları alınamadı.');
}
foreach (['sitemap.xml', 'post-sitemap.xml', 'robots.txt'] as $path) {
    if (! isset($files[$path]) || str_contains($files[$path], $origin) || ! str_contains($files[$path], $target)) {
        throw new RuntimeException("AIOSEO dosyası hedef domaine dönüştürülemedi: {$path}");
    }
}
if (isset($files['evil-sitemap.xml'])) {
    throw new RuntimeException('Harici AIOSEO sitemap adresi export edilmemeliydi.');
}

$disabled = new Wext\StaticPublisher\AIOSEO_Integration($origin, $target, [
    'aioseo_enabled' => '0',
]);
if (str_contains($disabled->process_html($html, true, 'arama'), 'All in One SEO')) {
    throw new RuntimeException('Kapalı AIOSEO entegrasyonu çıktı bloğunu kaldırmadı.');
}

$manifest = $integration->manifest_data();
if (empty($manifest['active']) || ($manifest['version'] ?? '') !== 'test-version' || ($manifest['settings']['aioseo_metadata_posts'] ?? '') !== '1') {
    throw new RuntimeException('AIOSEO manifest bilgisi eksik.');
}

echo "Wext Static Publisher All in One SEO integration test passed.\n";
