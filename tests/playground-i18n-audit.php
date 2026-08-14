<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$plugin = 'ragnus-static-publisher/ragnus-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

do_action('init');
wp_set_current_user(1);
switch_to_locale('en_US');
unload_textdomain('ragnus-static-publisher');
Ragnus\StaticPublisher\Plugin::load_textdomain();

$screens = [
    'main' => ['tab' => 'main'],
    'files' => ['tab' => 'files'],
    'settings-general' => ['tab' => 'settings', 'settings_tab' => 'general'],
    'settings-automation' => ['tab' => 'settings', 'settings_tab' => 'automation'],
    'seo-plugins' => ['tab' => 'seo', 'seo_tab' => 'plugins'],
    'seo-language' => ['tab' => 'seo', 'seo_tab' => 'language'],
    'deploy-zip' => ['tab' => 'deploy', 'deploy_tab' => 'zip'],
    'deploy-github' => ['tab' => 'deploy', 'deploy_tab' => 'github'],
    'deploy-cloudflare' => ['tab' => 'deploy', 'deploy_tab' => 'cloudflare'],
    'deploy-sftp' => ['tab' => 'deploy', 'deploy_tab' => 'sftp'],
    'search' => ['tab' => 'search'],
    'hide' => ['tab' => 'hide'],
    'diagnostics' => ['tab' => 'diagnostics'],
    'activity' => ['tab' => 'activity'],
    'about' => ['tab' => 'about'],
];

$expected = [
    'main' => ['Publishing Status', 'Status', 'Create Static Site'],
    'files' => ['ZIP Files'],
    'settings-general' => ['Settings', 'Save General Settings'],
    'settings-automation' => ['Automatic Static Site Creation and Deploy', 'Save Automation Settings'],
    'seo-plugins' => ['SEO Plugins', 'Rank Math SEO', 'Metadata', 'Technical SEO', 'Save SEO Plugin Settings'],
    'seo-language' => ['Language', 'Redirection by Browser Language', 'Save Language Settings'],
    'deploy-zip' => ['ZIP File', 'Open ZIP Files'],
    'deploy-github' => ['GitHub Deployment Webhook', 'Save Deploy Settings'],
    'deploy-cloudflare' => ['Cloudflare account required', 'Open Cloudflare'],
    'deploy-sftp' => ['SFTP Connection', 'Save SFTP Settings', 'Test Connection'],
    'search' => ['Static Search', 'Save Search Settings'],
    'hide' => ['WP-Content Directory', 'Save Hide Settings'],
    'diagnostics' => ['checks passed', 'Last checked:', 'Check Again'],
    'activity' => ['Activity Logs', 'Search source or static URL...'],
    'about' => ['About', 'Version Number'],
];

$forbidden_fragments = [
    'Ayar', 'Ara...', 'Arama', 'Başarılı', 'Çalış', 'Devam', 'Dil ', 'Dizin',
    'Durum', 'Etkinleştir', 'Genel', 'Gizle', 'İçerik', 'Kaydet', 'Kayıt',
    'Kontrol', 'Önceki', 'Örnek', 'Sayfa', 'Seç', 'Sonraki', 'Yapı', 'Yayın',
];

foreach ($screens as $screen => $query) {
    $_GET = $query;
    ob_start();
    Ragnus\StaticPublisher\Admin::render();
    $html = (string) ob_get_clean();
    foreach ($expected[$screen] as $needle) {
        if (! str_contains(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $needle)) {
            fwrite(STDERR, sprintf("%s ekranında beklenen İngilizce metin bulunamadı: %s\n", $screen, $needle));
            exit(1);
        }
    }
    foreach ($forbidden_fragments as $fragment) {
        if (str_contains($html, $fragment)) {
            fwrite(STDERR, sprintf("%s ekranında inline Türkçe metin bulundu: %s\n", $screen, $fragment));
            exit(1);
        }
    }
    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/(?:\r\n|\r|\n){2,}/u', "\n", $text) ?? $text;
    echo "\n=== {$screen} ===\n" . trim($text) . "\n";
}

$_GET = [];
restore_current_locale();
echo "Ragnus Static Publisher English UI audit passed.\n";
