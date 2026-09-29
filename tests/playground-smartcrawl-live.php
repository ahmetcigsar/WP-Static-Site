<?php
/** Run against a local WordPress server with SmartCrawl and Wext active. */
declare(strict_types=1);

require '/wordpress/wp-load.php';

use Wext\StaticPublisher\Exporter;
use Wext\StaticPublisher\Plugin;

if (! defined('SMARTCRAWL_VERSION') || ! class_exists(Exporter::class)) {
    throw new RuntimeException('Activate SmartCrawl and Wext Static Publisher before this test.');
}
$origin = untrailingslashit(home_url());
$target = 'https://static.example.com';
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$fetch = static function (string $url) use ($check): string {
    $response = wp_remote_get($url, ['timeout' => 30, 'redirection' => 0]);
    $check(! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200, 'Live request failed: ' . $url);
    return (string) wp_remote_retrieve_body($response);
};
update_option(Plugin::SETTINGS_KEY, array_merge(Plugin::settings(), ['target_url' => $target, 'maximum_urls' => 100, 'auto_export' => '0']));
update_option(Plugin::SEO_PLUGIN_SETTINGS_KEY, Plugin::seo_plugin_defaults());
update_option(Plugin::LANGUAGE_SETTINGS_KEY, array_merge(Plugin::language_settings(), ['enabled' => '0']));
update_option(Plugin::SEARCH_SETTINGS_KEY, array_merge(Plugin::search_defaults(), ['enabled' => '1', 'page_path' => 'search']));
update_option('wds_settings_options', array_merge(get_option('wds_settings_options', []), ['sitemap' => 1, 'onpage' => 1, 'social' => 1, 'schema' => 1]));
update_option('wds_sitemap_options', array_merge(get_option('wds_sitemap_options', []), ['override-native' => true, 'sitemap-stylesheet' => true]));
$social = array_merge(get_option('wds_social_options', []), ['og-enable' => true, 'twitter-card-enable' => true]);
update_option('wds_social_options', SmartCrawl\Admin\Settings\Social::get()->validate($social));

update_option('wds-advanced', array_merge(get_option('wds-advanced', []), ['robots' => ['active' => true, 'custom_directives' => "User-agent: *\nDisallow: /smartcrawl-private/", 'sitemap_directive_disabled' => false]]));

$source_pages = [];
foreach (['page', 'post'] as $type) {
    $slug = 'smartcrawl-' . $type;
    $post = get_page_by_path($slug, OBJECT, $type);
    $id = $post ? $post->ID : wp_insert_post(['post_type' => $type, 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => 'SmartCrawl ' . $type, 'post_content' => '<p>SmartCrawl static SEO test.</p>']);
    update_post_meta($id, '_wds_title', 'SmartCrawl Özel Başlık ' . $type);
    update_post_meta($id, '_wds_metadesc', 'SmartCrawl özel açıklama ' . $type);
    update_post_meta($id, '_wds_opengraph', ['title' => 'SmartCrawl OG ' . $type, 'description' => 'SmartCrawl sosyal açıklama ' . $type]);
    update_post_meta($id, '_wds_twitter', ['title' => 'SmartCrawl Twitter ' . $type, 'description' => 'SmartCrawl Twitter açıklama ' . $type]);
    $source_pages[$type] = $fetch(get_permalink($id));
    foreach (['SmartCrawl Özel Başlık', 'SmartCrawl özel açıklama', 'SmartCrawl OG', 'SmartCrawl Twitter', 'application/ld+json'] as $value) {
        $check(str_contains($source_pages[$type], $value), 'SmartCrawl did not generate source value: ' . $value);
    }
}
$source_sitemap = $fetch($origin . '/sitemap.xml');
$check(str_contains($source_sitemap, 'SMARTCRAWL SITEMAP'), 'Source sitemap is not SmartCrawl.');
$source_robots = $fetch($origin . '/robots.txt');
$check(str_contains($source_robots, 'Disallow: /smartcrawl-private/'), 'SmartCrawl custom robots rules not active.');
$job = 'smartcrawl-live-' . time();
$status = (new Exporter())->run($job);
$check(($status['state'] ?? '') === 'completed', 'Live export did not complete.');
$check(! empty($status['manifest']['smartcrawl']['active']), 'SmartCrawl missing from export manifest.');
require_once __DIR__ . '/database-test-helpers.php';
$zip = new ZipArchive();
$check($zip->open(wext_test_zip_path($status['archive'])) === true, 'Static ZIP cannot be opened.');
foreach ($source_pages as $type => $source) {
    $output = (string) $zip->getFromName('smartcrawl-' . $type . '/index.html');
    foreach (['SmartCrawl Özel Başlık ' . $type, 'SmartCrawl özel açıklama ' . $type, 'SmartCrawl OG ' . $type, 'SmartCrawl Twitter ' . $type, 'application/ld+json', $target . '/smartcrawl-' . $type . '/'] as $value) {
        $check(str_contains($output, $value), 'Static output lost: ' . $value);
    }
    $check(! str_contains($output, $origin) && ! str_contains($output, str_replace('/', '\\/', $origin)), 'WordPress origin remains in static HTML.');
    preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $output, $scripts);
    foreach ($scripts[1] as $json) {
        $check(is_array(json_decode($json, true)), 'Invalid exported JSON-LD.');
    }
}
foreach (['sitemap.xml', 'post-sitemap1.xml', 'page-sitemap1.xml', 'smartcrawl-sitemapIndexBody.xsl', 'smartcrawl-sitemapBody.xsl', 'robots.txt'] as $path) {
    $contents = $zip->getFromName($path);
    $check(is_string($contents) && $contents !== '', 'Missing static file: ' . $path);
    $check(! str_contains($contents, $origin), 'WordPress origin remains in: ' . $path);
    if (str_ends_with($path, '.xml')) {
        $check(! str_contains($contents, 'wds_sitemap_styling'), 'Dynamic stylesheet URL remains in sitemap.');
        $xml = new DOMDocument();
        $check($xml->loadXML($contents, LIBXML_NONET), 'Invalid sitemap XML: ' . $path);
    }
}
$check(str_contains((string) $zip->getFromName('robots.txt'), $target . '/sitemap.xml'), 'SmartCrawl robots sitemap declaration missing.');
$check(str_contains((string) $zip->getFromName('robots.txt'), 'Disallow: /smartcrawl-private/'), 'Custom SmartCrawl robots directive lost.');
$zip->close();
echo wp_json_encode(['result' => 'passed', 'smartcrawl' => SMARTCRAWL_VERSION, 'job' => $job, 'archive' => $status['archive'], 'url_count' => $status['url_count'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
