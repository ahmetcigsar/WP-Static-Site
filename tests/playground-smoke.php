<?php

declare(strict_types=1);

if (! defined('ABSPATH') && file_exists('/wordpress/wp-load.php')) {
    require '/wordpress/wp-load.php';
}

if (! defined('ABSPATH')) {
    fwrite(STDERR, "WordPress bootstrap dosyası bulunamadı.\n");
    exit(1);
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'ragnus-static-publisher/ragnus-static-publisher.php';
$result = activate_plugin($plugin);
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . "\n");
    exit(1);
}

do_action('init');

if (! class_exists('Ragnus\\StaticPublisher\\Exporter')) {
    fwrite(STDERR, "Exporter sınıfı yüklenmedi.\n");
    exit(1);
}

$settings = Ragnus\StaticPublisher\Plugin::settings();
if (($settings['maximum_urls'] ?? null) !== 2000) {
    fwrite(STDERR, "Varsayılan ayarlar beklenen değerde değil.\n");
    exit(1);
}
if (($settings['archive_retention'] ?? null) !== 5) {
    fwrite(STDERR, "Varsayılan ZIP saklama sayısı 5 değil.\n");
    exit(1);
}

Ragnus\StaticPublisher\Admin::enqueue_assets('toplevel_page_ragnus-static-publisher');
if (! wp_style_is('ragnus-static-publisher-admin', 'enqueued')) {
    fwrite(STDERR, "Modern yönetim arayüzü stil dosyası yüklenmedi.\n");
    exit(1);
}
if (! wp_script_is('ragnus-static-publisher-admin', 'enqueued')) {
    fwrite(STDERR, "Canlı durum takip betiği yüklenmedi.\n");
    exit(1);
}
$script_data = (string) wp_scripts()->get_data('ragnus-static-publisher-admin', 'data');
if (! str_contains($script_data, 'ragnus-static/v1/exports/latest')
    || ! str_contains($script_data, 'pollInterval')
    || ! str_contains($script_data, 'runnerUrl')) {
    fwrite(STDERR, "Canlı durum takip betiğinin REST yapılandırması eksik.\n");
    exit(1);
}
if (! has_action('wp_ajax_ragnus_static_run_pending', [Ragnus\StaticPublisher\Admin::class, 'run_pending_export'])) {
    fwrite(STDERR, "Bekleyen export için yönetim ekranı çalıştırıcısı kayıtlı değil.\n");
    exit(1);
}

wp_set_current_user(1);
$_GET['tab'] = 'about';
ob_start();
Ragnus\StaticPublisher\Admin::render();
$about_html = (string) ob_get_clean();
unset($_GET['tab']);

foreach (['About', 'Versiyon Numarası', RAGSTAT_VERSION, 'mailto:info@ragnus.co', 'https://ragnus.co/'] as $expected) {
    if (! str_contains($about_html, $expected)) {
        fwrite(STDERR, "About sekmesinde beklenen içerik bulunamadı: {$expected}\n");
        exit(1);
    }
}

$admin_source = (string) file_get_contents(RAGSTAT_DIR . 'includes/class-admin.php');

foreach (['Statik Site Oluştur', '>İndir</a>'] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Main sekmesinde beklenen buton metni bulunamadı: {$expected}\n");
        exit(1);
    }
}

Ragnus\StaticPublisher\Plugin::set_status('stalled-test', 'queued', 0, [
    'queued_at' => gmdate('c', time() - 120),
    'status_message' => 'Export işi sıraya alındı.',
]);
$stalled_status = Ragnus\StaticPublisher\Plugin::public_status();
if (empty($stalled_status['stalled']) || ! str_contains((string) ($stalled_status['runtime_notice'] ?? ''), 'WP-Cron')) {
    fwrite(STDERR, "Kuyrukta takılan export işi algılanmadı.\n");
    exit(1);
}

echo "Ragnus Static Publisher smoke test passed.\n";
