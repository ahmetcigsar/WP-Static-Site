<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$plugin = 'wext-static-publisher/wext-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}
do_action('init');

$defaults = Wext\StaticPublisher\Plugin::settings();
foreach ([
    'headless_enabled' => '0',
    'headless_frontend_behavior' => '404',
    'headless_preserve_path' => '1',
    'headless_allow_authenticated_preview' => '1',
    'headless_noindex' => '1',
    'headless_disable_xmlrpc' => '1',
    'headless_disable_comments' => '1',
] as $key => $expected) {
    if (($defaults[$key] ?? null) !== $expected) {
        throw new RuntimeException("Headless varsayılan ayarı hatalı: {$key}");
    }
}

$invalid_redirect = Wext\StaticPublisher\Admin::sanitize([
    '_section' => 'headless',
    'headless_enabled' => '1',
    'headless_frontend_behavior' => 'redirect',
]);
if (($invalid_redirect['headless_frontend_behavior'] ?? '') !== '404') {
    throw new RuntimeException('WordPress originine yönlendirme güvenli biçimde 404 davranışına düşürülmedi.');
}

$general_settings = Wext\StaticPublisher\Admin::sanitize([
    '_section' => 'general',
    'target_url' => 'https://www.example.com/',
]);
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $general_settings);

$settings = Wext\StaticPublisher\Admin::sanitize([
    '_section' => 'headless',
    'headless_enabled' => '1',
    'headless_frontend_behavior' => 'redirect',
    'headless_preserve_path' => '1',
    'headless_allow_authenticated_preview' => '1',
    'headless_allow_graphql' => '1',
    'headless_noindex' => '1',
    'headless_disable_xmlrpc' => '1',
    'headless_disable_comments' => '1',
]);
if (($settings['headless_enabled'] ?? '') !== '1'
    || ($settings['headless_frontend_behavior'] ?? '') !== 'redirect'
    || ($settings['headless_frontend_url'] ?? '') !== 'https://www.example.com'
    || ($settings['target_url'] ?? '') !== 'https://www.example.com') {
    throw new RuntimeException('Headless ayarları diğer Static Site ayarlarını koruyarak temizlenmedi.');
}
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $settings);

$unsigned = [
    'method' => 'GET',
    'headers' => ['X-Wext-Static-Export' => '1'],
];
$signed = apply_filters('http_request_args', $unsigned, home_url('/sample/?page=2'));
if (empty($signed['headers']['X-Wext-Static-Timestamp'])
    || empty($signed['headers']['X-Wext-Static-Signature'])) {
    throw new RuntimeException('Aynı-origin static export isteği timestamp ve HMAC ile imzalanmadı.');
}
$external = apply_filters('http_request_args', $unsigned, 'https://external.example/sample/');
if (isset($external['headers']['X-Wext-Static-Signature'])) {
    throw new RuntimeException('Harici origin isteğine dahili export imzası eklendi.');
}

$original_server = $_SERVER;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/sample/?page=2';
$_SERVER['HTTP_X_WEXT_STATIC_EXPORT'] = '1';
$_SERVER['HTTP_X_WEXT_STATIC_TIMESTAMP'] = (string) $signed['headers']['X-Wext-Static-Timestamp'];
$_SERVER['HTTP_X_WEXT_STATIC_SIGNATURE'] = (string) $signed['headers']['X-Wext-Static-Signature'];
$frontend_destination = new ReflectionMethod(Wext\StaticPublisher\Headless_Mode::class, 'frontend_destination');
$frontend_destination->setAccessible(true);
if ($frontend_destination->invoke(null, $settings) !== 'https://www.example.com/sample/') {
    throw new RuntimeException('Headless yönlendirmesi Canlı Site Adresi ve istenen yolu kullanmadı.');
}
if (! Wext\StaticPublisher\Headless_Mode::valid_export_request()
    || Wext\StaticPublisher\Headless_Mode::should_protect_frontend()) {
    throw new RuntimeException('Geçerli imzalı export isteği WordPress tema render erişimini alamadı.');
}

$_SERVER['HTTP_X_WEXT_STATIC_SIGNATURE'] = str_repeat('0', 64);
if (Wext\StaticPublisher\Headless_Mode::valid_export_request()) {
    throw new RuntimeException('Sahte export imzası kabul edildi.');
}

unset(
    $_SERVER['HTTP_X_WEXT_STATIC_EXPORT'],
    $_SERVER['HTTP_X_WEXT_STATIC_TIMESTAMP'],
    $_SERVER['HTTP_X_WEXT_STATIC_SIGNATURE']
);
wp_set_current_user(0);
if (! Wext\StaticPublisher\Headless_Mode::should_protect_frontend()) {
    throw new RuntimeException('Anonim WordPress tema isteği Headless modunda korunmadı.');
}
if (apply_filters('wp_sitemaps_enabled', true) !== false
    || apply_filters('xmlrpc_enabled', true) !== false
    || apply_filters('comments_open', true, 0) !== false
    || apply_filters('pings_open', true, 0) !== false
    || apply_filters('robots_txt', "User-agent: *\nAllow: /\n", true) !== "User-agent: *\nDisallow: /\n") {
    throw new RuntimeException('Headless CMS sertleştirme filtrelerinden biri uygulanmadı.');
}

wp_set_current_user(1);
if (Wext\StaticPublisher\Headless_Mode::should_protect_frontend()) {
    throw new RuntimeException('Giriş yapmış editörün tema önizlemesi engellendi.');
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'headless'];
ob_start();
Wext\StaticPublisher\Admin::render();
$headless_html = (string) ob_get_clean();
$_GET = [];
foreach (['Headless CMS', 'Headless + Static Publisher', 'Protect the WordPress theme frontend', 'Save Headless CMS Settings'] as $expected) {
    if (! str_contains($headless_html, $expected)) {
        throw new RuntimeException("Headless CMS yönetim ekranı içeriği eksik: {$expected}");
    }
}
if (! str_contains($headless_html, 'notice notice-info inline wextstat-headless-notice')
    || substr_count($headless_html, 'wextstat-settings-tab is-active') !== 1
    || ! str_contains($headless_html, 'settings_tab=headless')
    || str_contains($headless_html, 'wextstat-headless-frontend-url')
    || str_contains($headless_html, 'Frontend Address')) {
    throw new RuntimeException('Headless CMS alt sekmesi etkin durumda render edilmedi.');
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'general'];
ob_start();
Wext\StaticPublisher\Admin::render();
$general_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($general_html, 'CMS Address')
    || ! str_contains($general_html, 'Live Site Address')
    || ! str_contains($general_html, 'id="wextstat-cms-address"')
    || ! str_contains($general_html, 'readonly aria-readonly="true"')
    || substr_count($general_html, 'type="url"') !== 2) {
    throw new RuntimeException('General ekranında CMS ve canlı site adresleri beklenen biçimde render edilmedi.');
}

$admin_css = (string) file_get_contents(WEXTSTAT_DIR . 'assets/admin.css');
if (! preg_match('/\.wextstat-headless-notice\s*\{[^}]*border:\s*1px solid var\(--wextstat-primary\);/s', $admin_css)) {
    throw new RuntimeException('Headless CMS bilgi mesajının dört taraflı solid çerçevesi bulunamadı.');
}

$_SERVER = $original_server;
delete_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY);
echo "Headless CMS mode test passed.\n";
