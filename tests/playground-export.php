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
$last_completed_at = (string) ($status['last_completed_at'] ?? '');
if ($last_completed_at === '' || strtotime($last_completed_at) === false) {
    throw new RuntimeException('Son statik oluşturma zamanı kaydedilmedi.');
}
if (array_key_exists('log', get_option(Ragnus\StaticPublisher\Plugin::STATUS_KEY, []))) {
    throw new RuntimeException('Activity Log veritabanındaki export durumuna yazıldı.');
}
$activity = Ragnus\StaticPublisher\Activity_Log::page(1);
if (($activity['job_id'] ?? '') !== $job_id || ($activity['total'] ?? 0) < 2) {
    throw new RuntimeException('Son export Activity Log dosyasına yazılmadı.');
}
foreach ($activity['entries'] as $entry) {
    if (($entry['job_id'] ?? '') !== $job_id) {
        throw new RuntimeException('Activity Log önceki bir static işlemine ait kayıt içeriyor.');
    }
}
$mapped_entries = array_values(array_filter($activity['entries'], static function (array $entry): bool {
    return ($entry['source_url'] ?? '') !== '' && ($entry['static_path'] ?? '') !== '';
}));
if ($mapped_entries === []) {
    throw new RuntimeException('Export Activity Log kaynağı ve statik adresi ayrı alanlarda saklamadı.');
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

Ragnus\StaticPublisher\Plugin::set_status('next-job', 'queued', 0, ['source' => 'test']);
$queued_status = Ragnus\StaticPublisher\Plugin::status();
if (($queued_status['last_completed_at'] ?? '') !== $last_completed_at) {
    throw new RuntimeException('Son başarılı statik oluşturma zamanı yeni iş kuyruğunda korunmadı.');
}

echo sprintf("Export integration test passed with %d URLs.\n", (int) ($status['url_count'] ?? 0));
