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
$hide_settings = Ragnus\StaticPublisher\Plugin::hide_settings();
if (($hide_settings['wp_content_directory'] ?? '') !== 'wp-content'
    || ($hide_settings['theme_style_name'] ?? '') !== 'style'
    || ($hide_settings['author_url'] ?? '') !== 'author') {
    fwrite(STDERR, "Varsayılan Hide ayarları doğru değil.\n");
    exit(1);
}
$sanitized_hide = Ragnus\StaticPublisher\Admin::sanitize_hide_settings([
    'wp_content_directory' => '../My Assets',
    'theme_style_name' => 'custom.css',
    'disable_emojis' => '1',
]);
if (($sanitized_hide['wp_content_directory'] ?? '') !== 'my-assets'
    || ($sanitized_hide['theme_style_name'] ?? '') !== 'custom'
    || ($sanitized_hide['disable_emojis'] ?? '') !== '1'
    || ($sanitized_hide['disable_xml_rpc'] ?? '') !== '0') {
    fwrite(STDERR, "Hide ayarları güvenli yol adlarına dönüştürülmedi.\n");
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
if (str_contains($admin_source, '<th>Kod</th>') || str_contains($admin_source, 'ragstat-http-code')) {
    fwrite(STDERR, "Activity Log Kod sütunu arayüzden kaldırılmadı.\n");
    exit(1);
}
if (! str_contains($admin_source, 'Kayıt Sayısı:') || ! str_contains($admin_source, "activity['job_total']")) {
    fwrite(STDERR, "Activity Log kayıt sayısı bilgisi arayüzde bulunamadı.\n");
    exit(1);
}
if (! str_contains($admin_source, '>Sıra</th>') || ! str_contains($admin_source, '$archive_index + 1')) {
    fwrite(STDERR, "Files tablosunun sıra sütunu bulunamadı.\n");
    exit(1);
}
if (substr_count($admin_source, 'class="ragstat-time-column"') < 2) {
    fwrite(STDERR, "Activity Log saat sütunu sınıfı eksik.\n");
    exit(1);
}
foreach (['Tekrar Kontrol Et', 'ragnus_static_refresh_diagnostics', 'Diagnostics::report()'] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Diagnostics yeniden kontrol arayüzü eksik: {$expected}\n");
        exit(1);
    }
}
foreach (["'hide' => 'Hide'", 'render_hide_tab', 'Hide Ayarlarını Kaydet'] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Hide sekmesi arayüzü eksik: {$expected}\n");
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
