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
require_once ABSPATH . 'wp-admin/includes/template.php';

$plugin = 'wext-static-publisher/wext-static-publisher.php';
$result = activate_plugin($plugin);
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . "\n");
    exit(1);
}

do_action('init');

if (! class_exists('Wext\\StaticPublisher\\Exporter')) {
    fwrite(STDERR, "Exporter sınıfı yüklenmedi.\n");
    exit(1);
}

$settings = Wext\StaticPublisher\Plugin::settings();
if (($settings['maximum_urls'] ?? null) !== 2000) {
    fwrite(STDERR, "Varsayılan ayarlar beklenen değerde değil.\n");
    exit(1);
}
if (($settings['archive_retention'] ?? null) !== 5) {
    fwrite(STDERR, "Varsayılan ZIP saklama sayısı 5 değil.\n");
    exit(1);
}
foreach (['sftp_host', 'sftp_username', 'sftp_password', 'sftp_remote_path', 'sftp_auto_deploy'] as $sftp_setting) {
    if (! array_key_exists($sftp_setting, $settings)) {
        fwrite(STDERR, "Varsayılan SFTP ayarı eksik: {$sftp_setting}\n");
        exit(1);
    }
}
if (($settings['deployment_mode'] ?? '') !== 'advanced') {
    fwrite(STDERR, "Yeni kurulum için gelişmiş deployment modu varsayılan değil.\n");
    exit(1);
}
$legacy_settings = ['deployment_webhook_url' => 'https://api.github.com/repos/example/legacy/dispatches'];
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $legacy_settings);
if ((Wext\StaticPublisher\Plugin::settings()['deployment_mode'] ?? '') !== 'advanced') {
    fwrite(STDERR, "Mevcut webhook kurulumu otomatik olarak Gelişmiş moda taşınmadı.\n");
    exit(1);
}
delete_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY);
$language_defaults = Wext\StaticPublisher\Plugin::language_settings();
$site_language = Wext\StaticPublisher\Language_Routing::site_language();
$default_supported_languages = Wext\StaticPublisher\Language_Routing::parse_languages((string) ($language_defaults['supported_languages'] ?? ''));
if (($language_defaults['enabled'] ?? '') !== '0'
    || ($language_defaults['default_language'] ?? '') !== $site_language
    || ($default_supported_languages[0] ?? '') !== $site_language
    || ! in_array('tr', $default_supported_languages, true)
    || ! in_array('en', $default_supported_languages, true)) {
    fwrite(STDERR, "Varsayılan dil yönlendirme ayarları doğru değil.\n");
    exit(1);
}
$french_site_locale = static fn (): string => 'fr_FR';
add_filter('pre_option_WPLANG', $french_site_locale);
$french_language_defaults = Wext\StaticPublisher\Language_Routing::defaults();
remove_filter('pre_option_WPLANG', $french_site_locale);
if (($french_language_defaults['default_language'] ?? '') !== 'fr'
    || Wext\StaticPublisher\Language_Routing::parse_languages((string) ($french_language_defaults['supported_languages'] ?? '')) !== ['fr', 'tr', 'en']) {
    fwrite(STDERR, "WordPress site dili varsayılan listenin başına taşınmadı.\n");
    exit(1);
}
$saved_language_settings = [
    'enabled' => '1',
    'supported_languages' => "de\nen",
    'default_language' => 'de',
    'cookie_days' => 30,
];
update_option(Wext\StaticPublisher\Plugin::LANGUAGE_SETTINGS_KEY, $saved_language_settings);
Wext\StaticPublisher\Plugin::activate();
if (get_option(Wext\StaticPublisher\Plugin::LANGUAGE_SETTINGS_KEY) !== $saved_language_settings) {
    fwrite(STDERR, "Aktivasyon mevcut dil ayarlarının üzerine yazdı.\n");
    exit(1);
}
delete_option(Wext\StaticPublisher\Plugin::LANGUAGE_SETTINGS_KEY);
Wext\StaticPublisher\Plugin::activate();
$sanitized_languages = Wext\StaticPublisher\Language_Routing::sanitize([
    'enabled' => '1',
    'supported_languages' => "TR\nen-US\ninvalid/path\ntr",
    'default_language' => 'en_US',
    'cookie_days' => 9000,
]);
if (($sanitized_languages['enabled'] ?? '') !== '1'
    || ($sanitized_languages['supported_languages'] ?? '') !== "tr\nen-us"
    || ($sanitized_languages['default_language'] ?? '') !== 'en-us'
    || ($sanitized_languages['cookie_days'] ?? 0) !== 3650) {
    fwrite(STDERR, "Dil yönlendirme ayarları doğru temizlenmedi.\n");
    exit(1);
}
$language_routing = new Wext\StaticPublisher\Language_Routing($sanitized_languages);
if ($language_routing->seed_urls('https://cms.example.com') !== [
    'https://cms.example.com/tr/',
    'https://cms.example.com/en-us/',
]) {
    fwrite(STDERR, "Dil kökleri export kuyruğu için doğru oluşturulmadı.\n");
    exit(1);
}
$language_html = $language_routing->inject_x_default(
    $language_routing->inject_preference_script('<html><head></head><body></body></html>'),
    'https://static.example.com'
);
if (! str_contains($language_html, 'wext-language-preference.js')
    || ! str_contains($language_html, 'hreflang="x-default"')
    || ! str_contains($language_html, 'https://static.example.com/')) {
    fwrite(STDERR, "Dil tercihi veya x-default işaretlemesi HTML içine eklenmedi.\n");
    exit(1);
}
foreach (['auto_export_post_created', 'auto_export_post_updated', 'auto_export_page_created', 'auto_export_page_updated', 'auto_export_theme'] as $trigger) {
    if (($settings[$trigger] ?? '') !== '1') {
        fwrite(STDERR, "Varsayılan otomatik export tetikleyicisi etkin değil: {$trigger}\n");
        exit(1);
    }
}
foreach (['headless_enabled', 'headless_frontend_behavior', 'headless_frontend_url', 'headless_preserve_path', 'headless_allow_authenticated_preview', 'headless_allow_graphql', 'headless_noindex', 'headless_disable_xmlrpc', 'headless_disable_comments'] as $headless_setting) {
    if (! array_key_exists($headless_setting, $settings)) {
        fwrite(STDERR, "Varsayılan Headless CMS ayarı eksik: {$headless_setting}\n");
        exit(1);
    }
}
update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, ['active' => '1'], false);
$sanitized_settings = Wext\StaticPublisher\Admin::sanitize([
    'target_url' => home_url(),
    'maximum_urls' => 2000,
    'archive_retention' => 5,
    'auto_export' => '1',
    'auto_export_post_created' => '1',
    'auto_export_theme' => '1',
]);
if (($sanitized_settings['auto_export'] ?? '') !== '1'
    || ($sanitized_settings['auto_export_post_created'] ?? '') !== '1'
    || ($sanitized_settings['auto_export_post_updated'] ?? '') !== '0'
    || ($sanitized_settings['auto_export_theme'] ?? '') !== '1') {
    fwrite(STDERR, "Otomatik export tetikleyicileri doğru temizlenmedi.\n");
    exit(1);
}

$sanitized_zip = Wext\StaticPublisher\Admin::sanitize([
    '_section' => 'zip',
    'archive_retention' => 12,
]);
if (($sanitized_zip['archive_retention'] ?? 0) !== 12
    || ($sanitized_zip['target_url'] ?? '') !== ($settings['target_url'] ?? '')
    || ($sanitized_zip['maximum_urls'] ?? 0) !== ($settings['maximum_urls'] ?? 0)) {
    fwrite(STDERR, "ZIP saklama ayarı diğer General ayarları korunarak kaydedilmedi.\n");
    exit(1);
}

$sanitized_deploy = Wext\StaticPublisher\Admin::sanitize([
    '_section' => 'deploy',
    'deployment_webhook_url' => 'https://api.github.com/repos/example/deploy/dispatches',
    'deployment_webhook_token' => 'test-token',
]);
if (($sanitized_deploy['deployment_mode'] ?? '') !== 'advanced') {
    fwrite(STDERR, "GitHub ayarı kaydedildiğinde Gelişmiş Kurulum modu seçilmedi.\n");
    exit(1);
}

$sftp_password = 'SFTP smoke secret!';
if (! class_exists('phpseclib3\\Net\\SFTP')) {
    fwrite(STDERR, "Paketlenmiş phpseclib SFTP istemcisi yüklenemedi.\n");
    exit(1);
}
$sanitized_sftp = Wext\StaticPublisher\Admin::sanitize([
    '_section' => 'sftp',
    'sftp_auto_deploy' => '1',
    'sftp_host' => 'sftp://Files.Example.com/upload',
    'sftp_port' => 2222,
    'sftp_username' => 'deploy-user',
    'sftp_password' => $sftp_password,
    'sftp_remote_path' => '/var/www/static',
    'sftp_host_fingerprint' => '01:23:45:67:89:ab:cd:ef:01:23:45:67:89:ab:cd:ef',
    'sftp_timeout' => 90,
]);
if (($sanitized_sftp['sftp_auto_deploy'] ?? '') !== '1'
    || ($sanitized_sftp['sftp_host'] ?? '') !== 'files.example.com'
    || ($sanitized_sftp['sftp_port'] ?? 0) !== 2222
    || ($sanitized_sftp['sftp_remote_path'] ?? '') !== '/var/www/static'
    || ($sanitized_sftp['sftp_host_fingerprint'] ?? '') !== '0123456789abcdef0123456789abcdef'
    || ($sanitized_sftp['sftp_password'] ?? '') === $sftp_password
    || Wext\StaticPublisher\Secret_Store::decrypt((string) $sanitized_sftp['sftp_password']) !== $sftp_password) {
    fwrite(STDERR, "SFTP ayarları veya şifreli parola saklama doğru çalışmıyor.\n");
    exit(1);
}
if (Wext\StaticPublisher\SFTP_Deployer::sanitize_remote_path('/var/../secret') !== '') {
    fwrite(STDERR, "Güvensiz SFTP uzak yolu reddedilmedi.\n");
    exit(1);
}
$incomplete_sftp = Wext\StaticPublisher\Admin::sanitize([
    '_section' => 'sftp',
    'sftp_auto_deploy' => '1',
    'sftp_host' => 'files.example.com',
    'sftp_remote_path' => '/public_html',
]);
if (($incomplete_sftp['sftp_auto_deploy'] ?? '') !== '0') {
    fwrite(STDERR, "Eksik SFTP bilgileriyle otomatik yükleme etkin kaldı.\n");
    exit(1);
}

$sftp_job_id = 'sftp-smoke-test';
$sftp_build_directory = Wext\StaticPublisher\Plugin::storage_directory() . '/builds/' . $sftp_job_id;
wp_mkdir_p($sftp_build_directory . '/assets');
file_put_contents($sftp_build_directory . '/index.html', '<h1>SFTP</h1>');
file_put_contents($sftp_build_directory . '/assets/app.css', 'body{}');
$sftp_deploy_calls = 0;
add_filter('wext_static_sftp_available', '__return_true');
add_filter('wext_static_sftp_test_result', static fn (): array => ['success' => true]);
add_filter('wext_static_sftp_deploy_result', static function ($result, $directory, $public_settings, $job_id, $files) use (&$sftp_deploy_calls, $sftp_build_directory, $sftp_job_id): array {
    ++$sftp_deploy_calls;
    if ($directory !== $sftp_build_directory
        || $job_id !== $sftp_job_id
        || isset($public_settings['sftp_password'])
        || $files !== ['assets/app.css', 'index.html']) {
        return ['success' => false, 'message' => 'SFTP test aktarım kapsamı hatalı.'];
    }
    return ['success' => true, 'file_count' => count($files)];
}, 10, 5);
$sftp_connection_result = Wext\StaticPublisher\SFTP_Deployer::test_connection($sanitized_sftp);
if (empty($sftp_connection_result['success'])
    || (Wext\StaticPublisher\SFTP_Deployer::status()['state'] ?? '') !== 'connected') {
    fwrite(STDERR, "SFTP bağlantı testi sonucu kaydedilmedi.\n");
    exit(1);
}
$sftp_result = Wext\StaticPublisher\SFTP_Deployer::deploy_job($sftp_job_id, $sanitized_sftp);
if (($sftp_result['file_count'] ?? 0) !== 2
    || (Wext\StaticPublisher\SFTP_Deployer::status()['state'] ?? '') !== 'completed') {
    fwrite(STDERR, "SFTP statik dosya aktarımı ve durum kaydı doğrulanamadı.\n");
    exit(1);
}
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $sanitized_sftp);
delete_option(Wext\StaticPublisher\Plugin::DIRTY_KEY);
Wext\StaticPublisher\Plugin::handle_completed_export($sftp_job_id, '', []);
if ($sftp_deploy_calls !== 2) {
    fwrite(STDERR, "Başarılı export sonrası otomatik SFTP aktarımı tetiklenmedi.\n");
    exit(1);
}
remove_all_filters('wext_static_sftp_deploy_result');
remove_all_filters('wext_static_sftp_test_result');
remove_all_filters('wext_static_sftp_available');
unlink($sftp_build_directory . '/assets/app.css');
unlink($sftp_build_directory . '/index.html');
rmdir($sftp_build_directory . '/assets');
rmdir($sftp_build_directory);
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $settings);
$automatic_settings = array_merge(
    $settings,
    array_fill_keys(array_keys(Wext\StaticPublisher\Plugin::auto_export_trigger_defaults()), '0'),
    [
        'auto_export' => '1',
        'auto_export_post_created' => '1',
    ]
);
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $automatic_settings);
$automatic_post_id = wp_insert_post([
    'post_title' => 'Otomatik export tetikleyici testi',
    'post_status' => 'draft',
    'post_type' => 'post',
], true);
if (is_wp_error($automatic_post_id)) {
    fwrite(STDERR, "Otomatik export test yazısı oluşturulamadı.\n");
    exit(1);
}
$automatic_post = get_post($automatic_post_id);
Wext\StaticPublisher\Plugin::maybe_schedule_after_post_transition('publish', 'draft', $automatic_post);
$automatic_status = Wext\StaticPublisher\Plugin::status();
$automatic_job_id = (string) ($automatic_status['job_id'] ?? '');
if ($automatic_job_id === ''
    || ($automatic_status['source'] ?? '') !== 'post-created'
    || wp_next_scheduled(Wext\StaticPublisher\Plugin::CRON_HOOK, [$automatic_job_id]) === false) {
    fwrite(STDERR, "Seçili yeni yazı tetikleyicisi otomatik export kuyruğu oluşturmadı.\n");
    exit(1);
}
Wext\StaticPublisher\Plugin::maybe_schedule_after_post_transition('publish', 'publish', $automatic_post);
if ((string) (Wext\StaticPublisher\Plugin::status()['job_id'] ?? '') !== $automatic_job_id) {
    fwrite(STDERR, "Kapalı yazı güncelleme tetikleyicisi yeni export kuyruğu oluşturdu.\n");
    exit(1);
}
wp_clear_scheduled_hook(Wext\StaticPublisher\Plugin::CRON_HOOK, [$automatic_job_id]);
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $settings);
wp_delete_post($automatic_post_id, true);
$hide_settings = Wext\StaticPublisher\Plugin::hide_settings();
if (($hide_settings['wp_content_directory'] ?? '') !== 'wp-content'
    || ($hide_settings['theme_style_name'] ?? '') !== 'style'
    || ($hide_settings['author_url'] ?? '') !== 'author') {
    fwrite(STDERR, "Varsayılan Hide ayarları doğru değil.\n");
    exit(1);
}
$sanitized_hide = Wext\StaticPublisher\Admin::sanitize_hide_settings([
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

$preserved_hide_settings = array_merge($hide_settings, [
    'wp_content_directory' => 'assets',
    'hide_wordpress_version' => '1',
    'disable_emojis' => '1',
]);
update_option(Wext\StaticPublisher\Plugin::HIDE_SETTINGS_KEY, $preserved_hide_settings);
$sanitized_hide_section = Wext\StaticPublisher\Admin::sanitize_hide_settings([
    '_section' => 'traces',
    'hide_generator_meta' => '1',
]);
if (($sanitized_hide_section['wp_content_directory'] ?? '') !== 'assets'
    || ($sanitized_hide_section['hide_wordpress_version'] ?? '') !== '0'
    || ($sanitized_hide_section['hide_generator_meta'] ?? '') !== '1'
    || ($sanitized_hide_section['disable_emojis'] ?? '') !== '1') {
    fwrite(STDERR, "Hide alt sekmesi kaydı diğer bölümlerin ayarlarını korumadı.\n");
    exit(1);
}
update_option(Wext\StaticPublisher\Plugin::HIDE_SETTINGS_KEY, $hide_settings);

$sanitized_search = Wext\StaticPublisher\Admin::sanitize_search_settings([
    'enabled' => '1',
    'page_path' => 'Site Search',
    'result_limit' => 250,
    'min_chars' => 0,
    'threshold' => 2,
    'title_selector' => 'h1.entry-title',
    'content_selector' => '#main',
    'excerpt_selector' => '.summary',
    'index_excerpt' => '1',
    'excerpt_weight' => 4,
]);
if (($sanitized_search['page_path'] ?? '') !== 'site-search'
    || ($sanitized_search['result_limit'] ?? 0) !== 100
    || ($sanitized_search['min_chars'] ?? 0) !== 1
    || ($sanitized_search['threshold'] ?? '') !== '0.8'
    || ($sanitized_search['index_excerpt'] ?? '') !== '1'
    || ($sanitized_search['index_title'] ?? '') !== '0') {
    fwrite(STDERR, "Arama ayarları beklenen sınırlarda temizlenmedi.\n");
    exit(1);
}
$saved_search_settings = Wext\StaticPublisher\Plugin::search_defaults();
$saved_search_settings['title_selector'] = '.preserved-title';
$saved_search_settings['title_weight'] = '8';
update_option(Wext\StaticPublisher\Plugin::SEARCH_SETTINGS_KEY, $saved_search_settings);
$static_search_update = Wext\StaticPublisher\Admin::sanitize_search_settings([
    '_section' => 'static',
    'enabled' => '1',
    'page_path' => 'find',
    'result_limit' => 25,
    'min_chars' => 3,
    'content_limit' => 4000,
    'threshold' => 0.25,
    'token_match' => 'any',
]);
delete_option(Wext\StaticPublisher\Plugin::SEARCH_SETTINGS_KEY);
if (($static_search_update['page_path'] ?? '') !== 'find'
    || ($static_search_update['title_selector'] ?? '') !== '.preserved-title'
    || ($static_search_update['title_weight'] ?? '') !== '8') {
    fwrite(STDERR, "Search alt sekmesi kaydedilirken diğer bölümlerin ayarları korunmadı.\n");
    exit(1);
}

$saved_seo_settings = array_merge(Wext\StaticPublisher\Plugin::seo_defaults(), [
    'redirect_rules' => "/old/ /new/ 301",
    'indexnow_key' => 'preserved-key',
]);
update_option(Wext\StaticPublisher\Plugin::SEO_SETTINGS_KEY, $saved_seo_settings);
$audit_seo_update = Wext\StaticPublisher\Admin::sanitize_seo_settings([
    '_section' => 'audit',
    'audit_enabled' => '1',
    'canonical_fallback' => '1',
]);
delete_option(Wext\StaticPublisher\Plugin::SEO_SETTINGS_KEY);
if (($audit_seo_update['audit_enabled'] ?? '') !== '1'
    || ($audit_seo_update['audit_html_report'] ?? '') !== '0'
    || ($audit_seo_update['redirect_rules'] ?? '') !== "/old/ /new/ 301"
    || ($audit_seo_update['indexnow_key'] ?? '') !== 'preserved-key') {
    fwrite(STDERR, "SEO alt sekmesi kaydedilirken diğer bölümlerin ayarları korunmadı.\n");
    exit(1);
}

Wext\StaticPublisher\Admin::enqueue_assets('toplevel_page_wext-static-publisher');
if (! wp_style_is('wext-static-publisher-admin', 'enqueued')) {
    fwrite(STDERR, "Modern yönetim arayüzü stil dosyası yüklenmedi.\n");
    exit(1);
}
if (! wp_script_is('wext-static-publisher-admin', 'enqueued')) {
    fwrite(STDERR, "Canlı durum takip betiği yüklenmedi.\n");
    exit(1);
}
$style_version = (string) (wp_styles()->registered['wext-static-publisher-admin']->ver ?? '');
$script_version = (string) (wp_scripts()->registered['wext-static-publisher-admin']->ver ?? '');
if ($style_version !== (string) filemtime(WEXTSTAT_DIR . 'assets/admin.css')
    || $script_version !== (string) filemtime(WEXTSTAT_DIR . 'assets/admin.js')) {
    fwrite(STDERR, "Yönetim asset sürümleri dosya değişikliklerini cache-bust etmiyor.\n");
    exit(1);
}
$script_data = (string) wp_scripts()->get_data('wext-static-publisher-admin', 'data');
if (! str_contains($script_data, 'wext-static/v1/exports/latest')
    || ! str_contains($script_data, 'pollInterval')
    || ! str_contains($script_data, 'runnerUrl')) {
    fwrite(STDERR, "Canlı durum takip betiğinin REST yapılandırması eksik.\n");
    exit(1);
}
if (! has_action('wp_ajax_wext_static_run_pending', [Wext\StaticPublisher\Admin::class, 'run_pending_export'])) {
    fwrite(STDERR, "Bekleyen export için yönetim ekranı çalıştırıcısı kayıtlı değil.\n");
    exit(1);
}
foreach (['admin_post_wext_static_sftp_test' => 'test_sftp_connection', 'admin_post_wext_static_sftp_deploy' => 'deploy_latest_with_sftp'] as $hook => $method) {
    if (! has_action($hook, [Wext\StaticPublisher\Admin::class, $method])) {
        fwrite(STDERR, "SFTP yönetim işlemi kaydı bulunamadı: {$hook}\n");
        exit(1);
    }
}

delete_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY);
wp_set_current_user(1);
$_GET['tab'] = 'about';
ob_start();
Wext\StaticPublisher\Admin::render();
$about_html = (string) ob_get_clean();
unset($_GET['tab']);

foreach (['About', 'Version Number', WEXTSTAT_VERSION, 'https://wext.io/', 'about_tab=about', 'about_tab=license', 'about_tab=support', 'Upgrade to Pro'] as $expected) {
    if (! str_contains($about_html, $expected)) {
        fwrite(STDERR, "About sekmesinde beklenen içerik bulunamadı: {$expected} (locale=" . determine_locale() . ', direct=' . __('About', 'wext-static-publisher') . ")\n");
        exit(1);
    }
}
if (str_contains($about_html, 'Support Email') || str_contains($about_html, 'mailto:')) {
    fwrite(STDERR, "About kartında Support Email satırı hâlâ gösteriliyor.\n");
    exit(1);
}
if (! preg_match('/<div class="wrap wextstat-admin">\s*<hr class="wp-header-end">\s*<header class="wextstat-admin-header">/', $about_html)) {
    fwrite(STDERR, "Global WordPress bildirim sınırı Statik Publisher başlığının önünde değil.\n");
    exit(1);
}

$_GET['tab'] = 'about';
$_GET['about_tab'] = 'license';
ob_start();
Wext\StaticPublisher\Admin::render();
$license_html = (string) ob_get_clean();
if (! str_contains($license_html, 'Wext License') || ! str_contains($license_html, 'Activate License')) {
    fwrite(STDERR, "About > License sayfası lisans kartını göstermiyor.\n");
    exit(1);
}

$_GET['about_tab'] = 'support';
ob_start();
Wext\StaticPublisher\Admin::render();
$support_html = (string) ob_get_clean();
if (! str_contains($support_html, 'Wext Support') || ! str_contains($support_html, 'mailto:info@wext.co') || ! str_contains($support_html, 'https://wext.io/')) {
    fwrite(STDERR, "About > Support sayfası beklenen destek bilgilerini göstermiyor.\n");
    exit(1);
}

update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, ['active' => '1', 'plan_code' => 'annual'], false);
$_GET['about_tab'] = 'license';
ob_start();
Wext\StaticPublisher\Admin::render();
$annual_html = (string) ob_get_clean();
update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, ['active' => '1', 'plan_code' => 'lifetime'], false);
ob_start();
Wext\StaticPublisher\Admin::render();
$lifetime_html = (string) ob_get_clean();
delete_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY);
unset($_GET['tab'], $_GET['about_tab']);
if (! str_contains($annual_html, 'Pro v' . WEXTSTAT_VERSION)
    || ! str_contains($annual_html, 'wextstat-license-badge is-pro')
    || ! str_contains($annual_html, 'Revoke License')
    || ! str_contains($annual_html, 'data-wextstat-confirm-label="Revoke License"')
    || ! str_contains($annual_html, 'wext_static_license_deactivate')
    || ! str_contains($annual_html, 'wextstat-license-active"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span>Active</span>')
    || ! str_contains($annual_html, 'wextstat-plan-badge is-annual">Annual</span>')
    || ! str_contains($lifetime_html, 'wextstat-plan-badge is-lifetime">Lifetime</span>')) {
    fwrite(STDERR, "Aktif lisans Pro ve versiyon rozetini göstermiyor.\n");
    exit(1);
}

$_GET['tab'] = 'files';
$_GET['archive_notice'] = 'deleted';
$_GET['deleted'] = '1';
ob_start();
Wext\StaticPublisher\Admin::render();
$files_notice_html = (string) ob_get_clean();
unset($_GET['tab'], $_GET['archive_notice'], $_GET['deleted']);
if (! str_contains($files_notice_html, 'notice-success inline is-dismissible wextstat-files-notice')
    || ! str_contains($files_notice_html, '1 ZIP file has been deleted.')) {
    fwrite(STDERR, "Eski Files bağlantısı Deploy > ZIP File ekranına yönlenmiyor veya silme bildirimi inline gösterilmiyor.\n");
    exit(1);
}
if (! str_contains($files_notice_html, 'deploy_tab=zip')
    || ! str_contains($files_notice_html, 'wextstat-deploy-tab is-active')
    || str_contains($files_notice_html, 'tab=files')) {
    fwrite(STDERR, "Files ana sekmesi kaldırılmadı veya eski bağlantı Deploy > ZIP File ekranına taşınmadı.\n");
    exit(1);
}

foreach ([
    ['tab' => 'deploy', 'deploy_tab' => 'github'],
    ['tab' => 'deploy', 'deploy_tab' => 'cloudflare'],
    ['tab' => 'deploy', 'deploy_tab' => 'auto-deploy'],
    ['tab' => 'seo', 'seo_tab' => 'plugins'],
] as $locked_query) {
    $_GET = $locked_query;
    ob_start();
    Wext\StaticPublisher\Admin::render();
    $locked_html = (string) ob_get_clean();
    $_GET = [];
    if (! str_contains($locked_html, 'Active license required')
        || ! str_contains($locked_html, 'Open License Settings')
        || ! str_contains($locked_html, 'dashicons-lock')) {
        fwrite(STDERR, "Lisanssız premium ekranı kilitli gösterilmiyor.\n");
        exit(1);
    }
    foreach (['wextstat-deploy-settings-form', 'wextstat-automation-form', 'wextstat-seo-plugins-form', 'wext_static_managed_connect'] as $forbidden) {
        if (str_contains($locked_html, $forbidden)) {
            fwrite(STDERR, "Lisanssız premium ekran kullanılabilir bir form gösteriyor: {$forbidden}\n");
            exit(1);
        }
    }
}

$free_original_settings = Wext\StaticPublisher\Plugin::settings();
$free_export_settings = $free_original_settings;
$free_export_settings['target_url'] = 'https://static-free.example.com';
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $free_export_settings, false);
$free_exporter = new Wext\StaticPublisher\Exporter();
$licensed_features_property = new ReflectionProperty(Wext\StaticPublisher\Exporter::class, 'licensed_features');
$licensed_features_property->setAccessible(true);
$process_html_method = new ReflectionMethod(Wext\StaticPublisher\Exporter::class, 'process_html');
$process_html_method->setAccessible(true);
[$free_processed_html] = $process_html_method->invoke(
    $free_exporter,
    '<html><head><!-- Search Engine Optimization by Rank Math --><meta name="description" content="free"><!-- /Rank Math WordPress SEO plugin --></head><body><a href="' . home_url('/sample/') . '">Sample</a></body></html>',
    home_url('/')
);
if ($licensed_features_property->getValue($free_exporter) !== false
    || ! str_contains($free_processed_html, 'Search Engine Optimization by Rank Math')
    || ! str_contains($free_processed_html, 'href="/sample/"')) {
    fwrite(STDERR, "Lisanssız export Wext SEO işlemesini kapatırken temel statik URL dönüşümünü korumadı.\n");
    exit(1);
}
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $free_original_settings, false);
if (Wext\StaticPublisher\REST_Controller::can_use_github()) {
    fwrite(STDERR, "Lisanssız GitHub REST akışı engellenmedi.\n");
    exit(1);
}
$pre_expired_job_status = Wext\StaticPublisher\Plugin::status();
Wext\StaticPublisher\Plugin::set_status('expired-license-job', 'queued', 0, ['source' => 'post-updated']);
Wext\StaticPublisher\Plugin::run_scheduled('expired-license-job');
$expired_job_status = Wext\StaticPublisher\Plugin::status();
if (($expired_job_status['state'] ?? '') !== 'failed'
    || ! str_contains((string) ($expired_job_status['error'] ?? ''), 'active Wext license')) {
    fwrite(STDERR, "Lisans süresi dolduktan sonra kuyrukta kalan Auto Deploy işi durdurulmadı.\n");
    exit(1);
}
update_option(Wext\StaticPublisher\Plugin::STATUS_KEY, $pre_expired_job_status, false);

$original_license_gate_settings = Wext\StaticPublisher\Plugin::settings();
$license_gate_settings = $original_license_gate_settings;
$license_gate_settings['auto_export'] = '1';
$license_gate_settings['auto_export_post_created'] = '1';
$license_gate_settings['deployment_webhook_url'] = 'https://api.github.com/repos/example/site/dispatches';
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $license_gate_settings, false);
$status_before_license_gate = Wext\StaticPublisher\Plugin::status();
Wext\StaticPublisher\Plugin::maybe_schedule_automatic_export('auto_export_post_created', 'license-gate-test');
if (Wext\StaticPublisher\Plugin::status() !== $status_before_license_gate
    || Wext\StaticPublisher\Plugin::notify_deployment_webhook('license-gate-job', ['build_sha256' => str_repeat('a', 64)])) {
    fwrite(STDERR, "Lisanssız Auto Deploy veya GitHub webhook sunucu tarafında engellenmedi.\n");
    exit(1);
}
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $original_license_gate_settings, false);

update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, [
    'active' => '1',
    'activation_id' => 'activation-test',
    'site_id' => 'site-test',
    'plan_code' => 'pro',
    'site_limit' => 1,
    'activated_at' => gmdate('c'),
], false);
if (! Wext\StaticPublisher\Plugin::license_active()) {
    fwrite(STDERR, "Aktif lisans premium özellik kilidini açmadı.\n");
    exit(1);
}
if (! Wext\StaticPublisher\REST_Controller::can_use_github()) {
    fwrite(STDERR, "Aktif lisans GitHub REST akışını açmadı.\n");
    exit(1);
}

$archive_fixture = [];
for ($archive_number = 1; $archive_number <= 11; $archive_number++) {
    $archive_fixture[] = [
        'id' => 'archive-' . $archive_number,
        'job_id' => 'job-' . $archive_number,
        'url_count' => $archive_number,
        'created_at' => time() - $archive_number,
    ];
}
$zip_files_renderer = new ReflectionMethod(Wext\StaticPublisher\Admin::class, 'render_zip_files');
$zip_files_renderer->setAccessible(true);
$_GET = [];
ob_start();
$zip_files_renderer->invoke(null, $archive_fixture);
$zip_page_one_html = (string) ob_get_clean();
if (! str_contains($zip_page_one_html, 'job-1')
    || ! str_contains($zip_page_one_html, 'job-10')
    || str_contains($zip_page_one_html, 'job-11')
    || ! str_contains($zip_page_one_html, 'zip_page=2')
    || ! str_contains($zip_page_one_html, 'wextstat-zip-pagination')) {
    fwrite(STDERR, "ZIP dosyaları ilk sayfada 10 kayıtla sınırlandırılmadı.\n");
    exit(1);
}
$_GET = ['zip_page' => '2'];
ob_start();
$zip_files_renderer->invoke(null, $archive_fixture);
$zip_page_two_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($zip_page_two_html, 'job-11')
    || str_contains($zip_page_two_html, 'job-10')
    || ! preg_match('/wextstat-number-column[^>]*>11<\/td>/', $zip_page_two_html)) {
    fwrite(STDERR, "ZIP dosyaları ikinci sayfada doğru kayıt ve sıra numarasıyla gösterilmiyor.\n");
    exit(1);
}

$admin_source = (string) file_get_contents(WEXTSTAT_DIR . 'includes/class-admin.php');

foreach ([
    'static' => ['Search Page Path', 'value="static"'],
    'selectors' => ['CSS Selector For Title', 'value="selectors"'],
    'fuse' => ['Category and Tags', 'value="fuse"'],
] as $search_tab => $search_expectations) {
    $_GET = ['tab' => 'search', 'search_tab' => $search_tab];
    ob_start();
    Wext\StaticPublisher\Admin::render();
    $search_html = (string) ob_get_clean();
    $_GET = [];

    foreach (['Static Search', 'Indexing Selectors', 'Fuse.js', 'search_tab=' . $search_tab, ...$search_expectations] as $expected) {
        if (! str_contains($search_html, $expected)) {
            fwrite(STDERR, "Search > {$search_tab} ekranında beklenen içerik bulunamadı: {$expected}\n");
            exit(1);
        }
    }
    if (substr_count($search_html, 'wextstat-search-tab is-active') !== 1) {
        fwrite(STDERR, "Search > {$search_tab} ekranında tek bir etkin dikey sekme bulunamadı.\n");
        exit(1);
    }
    if (($search_tab !== 'static' && str_contains($search_html, 'id="wextstat-search-path"'))
        || ($search_tab !== 'selectors' && str_contains($search_html, 'id="wextstat-title-selector"'))
        || ($search_tab !== 'fuse' && str_contains($search_html, 'class="wextstat-search-field-row"'))) {
        fwrite(STDERR, "Search > {$search_tab} ekranında başka bir alt sekmenin alanları gösteriliyor.\n");
        exit(1);
    }
}
if (str_contains($admin_source, 'Fuse.js Fields and Weights')) {
    fwrite(STDERR, "Fuse.js alt sekmesinin eski adı kaynakta kaldı.\n");
    exit(1);
}

foreach ([
    'directory' => ['WP-Content Directory', 'value="directory"'],
    'traces' => ['Hide WordPress Version', 'value="traces"'],
    'static-outputs' => ['Disable XML-RPC Links', 'value="static-outputs"'],
] as $hide_tab => $hide_expectations) {
    $_GET = ['tab' => 'hide', 'hide_tab' => $hide_tab];
    ob_start();
    Wext\StaticPublisher\Admin::render();
    $hide_html = (string) ob_get_clean();
    $_GET = [];

    foreach (['Directory', 'Traces', 'Static Outputs', 'hide_tab=' . $hide_tab, ...$hide_expectations] as $expected) {
        if (! str_contains($hide_html, $expected)) {
            fwrite(STDERR, "Hide > {$hide_tab} ekranında beklenen içerik bulunamadı: {$expected}\n");
            exit(1);
        }
    }
    if (substr_count($hide_html, 'wextstat-hide-tab is-active') !== 1) {
        fwrite(STDERR, "Hide > {$hide_tab} ekranında tek bir etkin dikey sekme bulunamadı.\n");
        exit(1);
    }
    if (($hide_tab !== 'directory' && str_contains($hide_html, 'id="wextstat-hide-wp-content"'))
        || ($hide_tab !== 'traces' && str_contains($hide_html, 'id="wextstat-hide-wordpress-version"'))
        || ($hide_tab !== 'static-outputs' && str_contains($hide_html, 'id="wextstat-disable-xml-rpc"'))) {
        fwrite(STDERR, "Hide > {$hide_tab} ekranında başka bir alt sekmenin alanları gösteriliyor.\n");
        exit(1);
    }
}
if (str_contains($admin_source, 'Hide WordPress Traces') || str_contains($admin_source, 'Disable on Static Output')) {
    fwrite(STDERR, "Hide alt sekmelerinin eski adları kaynakta kaldı.\n");
    exit(1);
}

foreach (['Create Static Site', 'Deploy to Cloudflare', 'Cloudflare Deploy', "esc_html_e('Download'"] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Main sekmesinde beklenen buton metni bulunamadı: {$expected}\n");
        exit(1);
    }
}
if (str_contains($admin_source, '<th>Kod</th>') || str_contains($admin_source, 'wextstat-http-code')) {
    fwrite(STDERR, "Activity Log Kod sütunu arayüzden kaldırılmadı.\n");
    exit(1);
}
if (! str_contains($admin_source, 'Number of Records:') || ! str_contains($admin_source, "activity['job_total']")) {
    fwrite(STDERR, "Activity Log kayıt sayısı bilgisi arayüzde bulunamadı.\n");
    exit(1);
}
if (! str_contains($admin_source, "esc_html_e('Order'") || ! str_contains($admin_source, '$archive_index + 1')) {
    fwrite(STDERR, "Files tablosunun sıra sütunu bulunamadı.\n");
    exit(1);
}
if (substr_count($admin_source, 'class="wextstat-time-column"') < 2) {
    fwrite(STDERR, "Activity Log saat sütunu sınıfı eksik.\n");
    exit(1);
}
foreach (['Check Again', 'wext_static_refresh_diagnostics', 'Diagnostics::report()'] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Diagnostics yeniden kontrol arayüzü eksik: {$expected}\n");
        exit(1);
    }
}
foreach (["'hide' => __('Hide'", 'render_hide_tab', 'Save Hide Settings'] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Hide sekmesi arayüzü eksik: {$expected}\n");
        exit(1);
    }
}
foreach (['Automatic Static Site Creation and Deploy', 'When a new article is published', 'When the current page is updated', 'When the theme changes', 'When site settings change'] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Deploy > Auto Deploy kartında beklenen içerik bulunamadı: {$expected}\n");
        exit(1);
    }
}
foreach (['transition_post_status', 'created_term', 'wp_update_nav_menu', 'upgrader_process_complete', 'updated_option'] as $hook) {
    if (! has_action($hook)) {
        fwrite(STDERR, "Otomatik export hook kaydı bulunamadı: {$hook}\n");
        exit(1);
    }
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'general'];
ob_start();
Wext\StaticPublisher\Admin::render();
$settings_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($settings_html, 'Static Site') || ! str_contains($settings_html, 'Headless CMS') || ! str_contains($settings_html, 'Multilingual') || ! str_contains($settings_html, 'Save General Settings') || str_contains($settings_html, 'settings_tab=automation') || str_contains($settings_html, 'Automatic Static Site Creation and Deploy') || str_contains($settings_html, 'Number of ZIPs to Store') || str_contains($settings_html, 'Redirection by Browser Language')) {
    fwrite(STDERR, "Automation veya ZIP saklama ayarı Settings ekranından kaldırılmadı.\n");
    exit(1);
}

foreach ([
    'easy-start' => ['Easy Start', 'Cloudflare Account', 'Site Domains', 'Create and Deploy Static Site'],
    'zip' => ['ZIP Files', 'Number of ZIPs to Store', 'Save ZIP Settings', 'value="zip"'],
    'github' => ['GitHub Deployment Webhook', 'Save Deploy Settings'],
    'cloudflare' => ['License and connection required', 'Cloudflare Connection', 'Cloudflare Deploy', 'Deploy to Cloudflare'],
    'sftp' => ['SFTP Connection', 'Save SFTP Settings', 'Test Connection', 'Upload Latest Static Site'],
    'auto-deploy' => ['Automatic Static Site Creation and Deploy', 'Save Auto Deploy Settings'],
] as $deploy_tab => $panel_expectations) {
    $_GET = ['tab' => 'deploy', 'deploy_tab' => $deploy_tab];
    ob_start();
    Wext\StaticPublisher\Admin::render();
    $deploy_html = (string) ob_get_clean();

    foreach (['Easy Start', 'ZIP File', 'GitHub', 'Cloudflare', 'SFTP', 'Auto Deploy', 'wextstat-deploy-tabs', 'deploy_tab=' . $deploy_tab, ...$panel_expectations] as $expected) {
        if (! str_contains($deploy_html, $expected)) {
            fwrite(STDERR, "Deploy {$deploy_tab} sekmesinde beklenen içerik bulunamadı: {$expected}\n");
            exit(1);
        }
    }
    if (substr_count($deploy_html, 'wextstat-deploy-tab is-active') !== 1) {
        fwrite(STDERR, "Deploy {$deploy_tab} ekranında tek bir etkin dikey sekme bulunamadı.\n");
        exit(1);
    }
    if (str_contains($deploy_html, 'deploy_tab=multilingual') || str_contains($deploy_html, 'Redirection by Browser Language')) {
        fwrite(STDERR, "Multilingual ayarları Deploy ekranından kaldırılmadı.\n");
        exit(1);
    }
    if ($deploy_tab === 'zip') {
        $zip_card_start = strpos($deploy_html, '<section class="wextstat-deploy-card" aria-labelledby="wextstat-deploy-zip-title">');
        $zip_files_start = strpos($deploy_html, 'class="wextstat-deploy-card__files"');
        $zip_card_end = $zip_card_start === false ? false : strpos($deploy_html, '</section>', $zip_card_start);
        $zip_settings_start = strpos($deploy_html, 'class="wextstat-settings-form wextstat-zip-settings-form"');
        if ($zip_card_start === false || $zip_files_start === false || $zip_card_end === false || $zip_settings_start === false
            || ! ($zip_card_start < $zip_files_start && $zip_files_start < $zip_card_end && $zip_card_end < $zip_settings_start)) {
            fwrite(STDERR, "ZIP Files listesi ZIP File kartının içine taşınmadı.\n");
            exit(1);
        }
    }
    if ($deploy_tab === 'github' && substr_count($deploy_html, 'data-wextstat-github-icon') < 2) {
        fwrite(STDERR, "GitHub sekmesi ve içerik panelinde GitHub ikonu bulunamadı.\n");
        exit(1);
    }
    if ($deploy_tab === 'cloudflare' && (substr_count($deploy_html, 'data-wextstat-cloudflare-icon') < 2 || substr_count($deploy_html, 'fill="currentColor"') < 4)) {
        fwrite(STDERR, "Cloudflare sekmesi ve içerik panelinde tek renk Cloudflare SVG ikonu bulunamadı.\n");
        exit(1);
    }
    if ($deploy_tab === 'cloudflare' && str_contains($deploy_html, 'wextstat-license-title')) {
        fwrite(STDERR, "Wext License kartı Deploy > Cloudflare ekranından kaldırılmadı.\n");
        exit(1);
    }
}

$_GET = ['tab' => 'deploy', 'deploy_tab' => 'easy-setup'];
ob_start();
Wext\StaticPublisher\Admin::render();
$removed_easy_setup_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($removed_easy_setup_html, 'ZIP Files') || str_contains($removed_easy_setup_html, 'Easy Setup') || str_contains($removed_easy_setup_html, 'Connect Cloudflare')) {
    fwrite(STDERR, "Kaldırılan Easy Setup rotası ZIP File ekranına düşmüyor.\n");
    exit(1);
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'multilingual'];
ob_start();
Wext\StaticPublisher\Admin::render();
$settings_multilingual_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($settings_multilingual_html, 'Static Site') || ! str_contains($settings_multilingual_html, 'Multilingual') || ! str_contains($settings_multilingual_html, 'Redirection by Browser Language') || ! str_contains($settings_multilingual_html, 'Save Language Settings') || ! str_contains($settings_multilingual_html, 'settings_tab=multilingual') || substr_count($settings_multilingual_html, 'wextstat-settings-tab is-active') !== 1) {
    fwrite(STDERR, "Static Site > Multilingual ekranı doğru oluşturulmadı.\n");
    exit(1);
}

$_GET = ['tab' => 'deploy', 'deploy_tab' => 'multilingual'];
ob_start();
Wext\StaticPublisher\Admin::render();
$legacy_deploy_multilingual_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($legacy_deploy_multilingual_html, 'Redirection by Browser Language') || ! str_contains($legacy_deploy_multilingual_html, 'settings_tab=multilingual') || str_contains($legacy_deploy_multilingual_html, 'deploy_tab=multilingual')) {
    fwrite(STDERR, "Eski Deploy > Multilingual bağlantısı Static Site > Multilingual ekranına uyarlanmadı.\n");
    exit(1);
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'automation'];
ob_start();
Wext\StaticPublisher\Admin::render();
$legacy_automation_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($legacy_automation_html, 'Automatic Static Site Creation and Deploy') || ! str_contains($legacy_automation_html, 'deploy_tab=auto-deploy') || str_contains($legacy_automation_html, 'settings_tab=automation')) {
    fwrite(STDERR, "Eski Settings > Automation bağlantısı yeni Deploy > Auto Deploy sekmesine uyarlanmadı.\n");
    exit(1);
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'deploy'];
ob_start();
Wext\StaticPublisher\Admin::render();
$legacy_deploy_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($legacy_deploy_html, 'GitHub Deployment Webhook') || ! str_contains($legacy_deploy_html, 'deploy_tab=github')) {
    fwrite(STDERR, "Eski Settings > Deploy bağlantısı yeni üst sekmeye uyarlanmadı.\n");
    exit(1);
}

$_GET = ['tab' => 'seo', 'seo_tab' => 'plugins'];
ob_start();
Wext\StaticPublisher\Admin::render();
$seo_html = (string) ob_get_clean();
if (! str_contains($seo_html, 'SEO Plugins') || ! str_contains($seo_html, 'Rank Math SEO') || ! str_contains($seo_html, 'All in One SEO') || ! str_contains($seo_html, 'aioseo_enabled') || ! str_contains($seo_html, 'SEOPress') || ! str_contains($seo_html, 'seopress_enabled') || ! str_contains($seo_html, 'SureRank SEO') || ! str_contains($seo_html, 'surerank_enabled') || ! str_contains($seo_html, 'The SEO Framework') || ! str_contains($seo_html, 'seo_framework_enabled') || ! str_contains($seo_html, 'Yoast SEO') || ! str_contains($seo_html, 'yoast_enabled') || ! str_contains($seo_html, 'Post Metadata') || ! str_contains($seo_html, 'Custom Post Type Metadata') || ! str_contains($seo_html, 'Archive &amp; Taxonomy Metadata') || ! str_contains($seo_html, 'data-wextstat-seo-toggle') || ! str_contains($seo_html, 'data-wextstat-output-count') || ! str_contains($seo_html, '>Metadata<') || ! str_contains($seo_html, '>Technical SEO<') || ! str_contains($seo_html, 'wextstat-seo-save-bar') || ! str_contains($seo_html, 'Save SEO Plugin Settings')) {
    fwrite(STDERR, "SEO Plugins dikey sekmesi, Rank Math, All in One SEO veya SEOPress ayarları bulunamadı.\n");
    exit(1);
}
if (substr_count($seo_html, '<svg viewBox=') !== 6 || str_contains($seo_html, 'dashicons-chart-area') || str_contains($seo_html, 'dashicons-chart-pie')) {
    fwrite(STDERR, "SEO eklenti kartlarının resmi tek renkli SVG ikonları bulunamadı.\n");
    exit(1);
}
if (str_contains($seo_html, 'seo_tab=language') || str_contains($seo_html, 'Redirection by Browser Language')) {
    fwrite(STDERR, "Multilingual ayarları SEO ekranından kaldırılmadı.\n");
    exit(1);
}

foreach ([
    'audit' => ['Create SEO audit report', 'value="audit"'],
    'sitemaps' => ['Generate Wext sitemap', 'value="sitemaps"'],
    'redirects' => ['Custom Redirect Rules', 'value="redirects"'],
    'indexing' => ['IndexNow Key', 'value="indexing"'],
    'performance' => ['Large HTML Threshold (KB)', 'value="performance"'],
] as $seo_tab => $seo_expectations) {
    $_GET = ['tab' => 'seo', 'seo_tab' => $seo_tab];
    ob_start();
    Wext\StaticPublisher\Admin::render();
    $toolkit_html = (string) ob_get_clean();
    $_GET = [];
    foreach (['SEO Plugins', 'SEO Audit', 'Sitemaps', 'Redirects', 'Indexing', 'Performance', 'seo_tab=' . $seo_tab, ...$seo_expectations] as $expected) {
        if (! str_contains($toolkit_html, $expected)) {
            fwrite(STDERR, "SEO > {$seo_tab} ekranında beklenen içerik bulunamadı: {$expected}\n");
            exit(1);
        }
    }
    if (substr_count($toolkit_html, 'wextstat-seo-tab is-active') !== 1) {
        fwrite(STDERR, "SEO > {$seo_tab} ekranında tek bir etkin dikey sekme bulunamadı.\n");
        exit(1);
    }
}

if (! defined('RANK_MATH_VERSION')) {
    define('RANK_MATH_VERSION', 'test');
}
$_GET = ['tab' => 'seo', 'seo_tab' => 'plugins'];
ob_start();
Wext\StaticPublisher\Admin::render();
$active_seo_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($active_seo_html, 'Detected SEO plugin:') || ! str_contains($active_seo_html, 'wextstat-seo-plugin-card is-expanded') || ! str_contains($active_seo_html, 'aria-expanded="true"') || ! str_contains($active_seo_html, 'id="wextstat-rank-math-settings" data-wextstat-seo-panel')) {
    fwrite(STDERR, "Etkin SEO eklentisi üste alınmadı veya varsayılan olarak açılmadı.\n");
    exit(1);
}

$_GET = ['tab' => 'seo', 'seo_tab' => 'language'];
ob_start();
Wext\StaticPublisher\Admin::render();
$seo_language_html = (string) ob_get_clean();
if (! str_contains($seo_language_html, 'Multilingual') || ! str_contains($seo_language_html, 'Redirection by Browser Language') || ! str_contains($seo_language_html, 'Save Language Settings') || ! str_contains($seo_language_html, 'settings_tab=multilingual')) {
    fwrite(STDERR, "Eski SEO > Multilingual bağlantısı Static Site > Multilingual ekranına uyarlanmadı.\n");
    exit(1);
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'languages'];
ob_start();
Wext\StaticPublisher\Admin::render();
$legacy_languages_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($legacy_languages_html, 'Redirection by Browser Language') || ! str_contains($legacy_languages_html, 'settings_tab=multilingual')) {
    fwrite(STDERR, "Eski Settings > Languages bağlantısı Static Site > Multilingual ekranına uyarlanmadı.\n");
    exit(1);
}

Wext\StaticPublisher\Plugin::set_status('stalled-test', 'queued', 0, [
    'queued_at' => gmdate('c', time() - 120),
    'status_message' => 'Export işi sıraya alındı.',
]);
$stalled_status = Wext\StaticPublisher\Plugin::public_status();
if (empty($stalled_status['stalled']) || ! str_contains((string) ($stalled_status['runtime_notice'] ?? ''), 'WP-Cron')) {
    fwrite(STDERR, "Kuyrukta takılan export işi algılanmadı.\n");
    exit(1);
}

echo "Wext Static Publisher smoke test passed.\n";
