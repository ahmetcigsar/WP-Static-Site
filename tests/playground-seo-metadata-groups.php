<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$activation = activate_plugin('wext-static-publisher/wext-static-publisher.php');
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}
update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, ['active' => '1'], false);

update_option(Wext\StaticPublisher\Plugin::SEO_PLUGIN_SETTINGS_KEY, [
    'rank_math_metadata' => '0',
    'aioseo_metadata' => '1',
    'seopress_metadata' => '0',
]);
$migrated = Wext\StaticPublisher\Plugin::seo_plugin_settings();
foreach (['pages', 'posts', 'custom_post_types', 'archives'] as $group) {
    if (($migrated['rank_math_metadata_' . $group] ?? '') !== '0' || ($migrated['aioseo_metadata_' . $group] ?? '') !== '1' || ($migrated['seopress_metadata_' . $group] ?? '') !== '0') {
        throw new RuntimeException('Eski metadata ayarı yeni içerik gruplarına taşınamadı: ' . $group);
    }
}

register_post_type('wextstat_product', [
    'public' => true,
    'rewrite' => ['slug' => 'products'],
    'label' => 'Products',
]);
flush_rewrite_rules(false);

$post_id = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Metadata Post']);
$page_id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Metadata Page']);
$regular_page_id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Regular Metadata Page']);
$product_id = wp_insert_post(['post_type' => 'wextstat_product', 'post_status' => 'publish', 'post_title' => 'Metadata Product']);
$posts_page_id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blog']);
update_option('page_for_posts', $posts_page_id);
update_option('show_on_front', 'page');
update_option('page_on_front', $page_id);

$exporter = new Wext\StaticPublisher\Exporter();
$method = new ReflectionMethod($exporter, 'metadata_group_for_url');
$cases = [
    get_permalink($regular_page_id) => 'pages',
    get_permalink($post_id) => 'posts',
    get_permalink($product_id) => 'custom_post_types',
    get_permalink($posts_page_id) => 'archives',
    home_url('/') => 'pages',
    home_url('/category/news/') => 'archives',
];
foreach ($cases as $url => $expected) {
    $actual = $method->invoke($exporter, $url);
    if ($actual !== $expected) {
        throw new RuntimeException("Metadata grubu yanlış: {$url}; beklenen {$expected}, bulunan {$actual}");
    }
}

echo "Wext Static Publisher SEO metadata group test passed.\n";
