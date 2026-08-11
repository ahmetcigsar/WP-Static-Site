<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'ragnus-static-publisher/ragnus-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

do_action('init');
update_option('ragnus_static_settings', [
    'target_url' => 'https://static.example.com',
    'maximum_urls' => 50,
    'excluded_paths' => "/wp-admin/\n/wp-login.php\n/wp-json/\n/feed/",
    'auto_export' => '0',
]);

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
Ragnus\StaticPublisher\Plugin::set_status($job_id, 'running', 0, ['source' => 'test']);
$status = (new Ragnus\StaticPublisher\Exporter())->run($job_id);

if (($status['state'] ?? '') !== 'completed') {
    throw new RuntimeException('Export tamamlanmadı.');
}
if (! is_readable((string) ($status['archive'] ?? ''))) {
    throw new RuntimeException('Export arşivi üretilemedi.');
}
if (($status['manifest']['target'] ?? '') !== 'https://static.example.com') {
    throw new RuntimeException('Hedef domain manifest içine doğru yazılmadı.');
}

$zip = new ZipArchive();
if ($zip->open((string) $status['archive']) !== true) {
    throw new RuntimeException('Export ZIP dosyası açılamadı.');
}
$home_html = (string) $zip->getFromName('index.html');
$zip->close();
if (str_contains($home_html, 'https://static.example.com/wp-content/')) {
    preg_match_all('#https://static\.example\.com/wp-content/[^"\'\s<]+#', $home_html, $remaining_assets);
    throw new RuntimeException('Asset URL adresleri preview-safe kök yola dönüştürülmedi: ' . wp_json_encode(array_slice(array_unique($remaining_assets[0]), 0, 5)));
}
if (! str_contains($home_html, '/wp-content/')) {
    throw new RuntimeException('Ana sayfada beklenen kök asset yolu bulunamadı.');
}

echo sprintf("Export integration test passed with %d URLs.\n", (int) ($status['url_count'] ?? 0));
