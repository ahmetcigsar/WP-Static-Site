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

echo "Ragnus Static Publisher smoke test passed.\n";
