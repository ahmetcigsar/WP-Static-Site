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
foreach (['sftp_host', 'sftp_username', 'sftp_password', 'sftp_remote_path', 'sftp_auto_deploy'] as $sftp_setting) {
    if (! array_key_exists($sftp_setting, $settings)) {
        fwrite(STDERR, "Varsayılan SFTP ayarı eksik: {$sftp_setting}\n");
        exit(1);
    }
}
$language_defaults = Ragnus\StaticPublisher\Plugin::language_settings();
if (($language_defaults['enabled'] ?? '') !== '0'
    || ($language_defaults['default_language'] ?? '') !== 'tr') {
    fwrite(STDERR, "Varsayılan dil yönlendirme ayarları doğru değil.\n");
    exit(1);
}
$sanitized_languages = Ragnus\StaticPublisher\Language_Routing::sanitize([
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
$language_routing = new Ragnus\StaticPublisher\Language_Routing($sanitized_languages);
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
if (! str_contains($language_html, 'ragnus-language-preference.js')
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
$sanitized_settings = Ragnus\StaticPublisher\Admin::sanitize([
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

$sftp_password = 'SFTP smoke secret!';
if (! class_exists('phpseclib3\\Net\\SFTP')) {
    fwrite(STDERR, "Paketlenmiş phpseclib SFTP istemcisi yüklenemedi.\n");
    exit(1);
}
$sanitized_sftp = Ragnus\StaticPublisher\Admin::sanitize([
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
    || Ragnus\StaticPublisher\Secret_Store::decrypt((string) $sanitized_sftp['sftp_password']) !== $sftp_password) {
    fwrite(STDERR, "SFTP ayarları veya şifreli parola saklama doğru çalışmıyor.\n");
    exit(1);
}
if (Ragnus\StaticPublisher\SFTP_Deployer::sanitize_remote_path('/var/../secret') !== '') {
    fwrite(STDERR, "Güvensiz SFTP uzak yolu reddedilmedi.\n");
    exit(1);
}
$incomplete_sftp = Ragnus\StaticPublisher\Admin::sanitize([
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
$sftp_build_directory = Ragnus\StaticPublisher\Plugin::storage_directory() . '/builds/' . $sftp_job_id;
wp_mkdir_p($sftp_build_directory . '/assets');
file_put_contents($sftp_build_directory . '/index.html', '<h1>SFTP</h1>');
file_put_contents($sftp_build_directory . '/assets/app.css', 'body{}');
$sftp_deploy_calls = 0;
add_filter('ragnus_static_sftp_available', '__return_true');
add_filter('ragnus_static_sftp_test_result', static fn (): array => ['success' => true]);
add_filter('ragnus_static_sftp_deploy_result', static function ($result, $directory, $public_settings, $job_id, $files) use (&$sftp_deploy_calls, $sftp_build_directory, $sftp_job_id): array {
    ++$sftp_deploy_calls;
    if ($directory !== $sftp_build_directory
        || $job_id !== $sftp_job_id
        || isset($public_settings['sftp_password'])
        || $files !== ['assets/app.css', 'index.html']) {
        return ['success' => false, 'message' => 'SFTP test aktarım kapsamı hatalı.'];
    }
    return ['success' => true, 'file_count' => count($files)];
}, 10, 5);
$sftp_connection_result = Ragnus\StaticPublisher\SFTP_Deployer::test_connection($sanitized_sftp);
if (empty($sftp_connection_result['success'])
    || (Ragnus\StaticPublisher\SFTP_Deployer::status()['state'] ?? '') !== 'connected') {
    fwrite(STDERR, "SFTP bağlantı testi sonucu kaydedilmedi.\n");
    exit(1);
}
$sftp_result = Ragnus\StaticPublisher\SFTP_Deployer::deploy_job($sftp_job_id, $sanitized_sftp);
if (($sftp_result['file_count'] ?? 0) !== 2
    || (Ragnus\StaticPublisher\SFTP_Deployer::status()['state'] ?? '') !== 'completed') {
    fwrite(STDERR, "SFTP statik dosya aktarımı ve durum kaydı doğrulanamadı.\n");
    exit(1);
}
update_option(Ragnus\StaticPublisher\Plugin::SETTINGS_KEY, $sanitized_sftp);
delete_option(Ragnus\StaticPublisher\Plugin::DIRTY_KEY);
Ragnus\StaticPublisher\Plugin::handle_completed_export($sftp_job_id, '', []);
if ($sftp_deploy_calls !== 2) {
    fwrite(STDERR, "Başarılı export sonrası otomatik SFTP aktarımı tetiklenmedi.\n");
    exit(1);
}
remove_all_filters('ragnus_static_sftp_deploy_result');
remove_all_filters('ragnus_static_sftp_test_result');
remove_all_filters('ragnus_static_sftp_available');
unlink($sftp_build_directory . '/assets/app.css');
unlink($sftp_build_directory . '/index.html');
rmdir($sftp_build_directory . '/assets');
rmdir($sftp_build_directory);
update_option(Ragnus\StaticPublisher\Plugin::SETTINGS_KEY, $settings);
$automatic_settings = array_merge(
    $settings,
    array_fill_keys(array_keys(Ragnus\StaticPublisher\Plugin::auto_export_trigger_defaults()), '0'),
    [
        'auto_export' => '1',
        'auto_export_post_created' => '1',
    ]
);
update_option(Ragnus\StaticPublisher\Plugin::SETTINGS_KEY, $automatic_settings);
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
Ragnus\StaticPublisher\Plugin::maybe_schedule_after_post_transition('publish', 'draft', $automatic_post);
$automatic_status = Ragnus\StaticPublisher\Plugin::status();
$automatic_job_id = (string) ($automatic_status['job_id'] ?? '');
if ($automatic_job_id === ''
    || ($automatic_status['source'] ?? '') !== 'post-created'
    || wp_next_scheduled(Ragnus\StaticPublisher\Plugin::CRON_HOOK, [$automatic_job_id]) === false) {
    fwrite(STDERR, "Seçili yeni yazı tetikleyicisi otomatik export kuyruğu oluşturmadı.\n");
    exit(1);
}
Ragnus\StaticPublisher\Plugin::maybe_schedule_after_post_transition('publish', 'publish', $automatic_post);
if ((string) (Ragnus\StaticPublisher\Plugin::status()['job_id'] ?? '') !== $automatic_job_id) {
    fwrite(STDERR, "Kapalı yazı güncelleme tetikleyicisi yeni export kuyruğu oluşturdu.\n");
    exit(1);
}
wp_clear_scheduled_hook(Ragnus\StaticPublisher\Plugin::CRON_HOOK, [$automatic_job_id]);
update_option(Ragnus\StaticPublisher\Plugin::SETTINGS_KEY, $settings);
wp_delete_post($automatic_post_id, true);
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

$sanitized_search = Ragnus\StaticPublisher\Admin::sanitize_search_settings([
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
foreach (['admin_post_ragnus_static_sftp_test' => 'test_sftp_connection', 'admin_post_ragnus_static_sftp_deploy' => 'deploy_latest_with_sftp'] as $hook => $method) {
    if (! has_action($hook, [Ragnus\StaticPublisher\Admin::class, $method])) {
        fwrite(STDERR, "SFTP yönetim işlemi kaydı bulunamadı: {$hook}\n");
        exit(1);
    }
}

wp_set_current_user(1);
$_GET['tab'] = 'about';
ob_start();
Ragnus\StaticPublisher\Admin::render();
$about_html = (string) ob_get_clean();
unset($_GET['tab']);

foreach (['About', 'Version Number', RAGSTAT_VERSION, 'mailto:info@ragnus.co', 'https://ragnus.co/'] as $expected) {
    if (! str_contains($about_html, $expected)) {
        fwrite(STDERR, "About sekmesinde beklenen içerik bulunamadı: {$expected} (locale=" . determine_locale() . ', direct=' . __('About', 'ragnus-static-publisher') . ")\n");
        exit(1);
    }
}

$_GET['tab'] = 'files';
$_GET['archive_notice'] = 'deleted';
$_GET['deleted'] = '1';
ob_start();
Ragnus\StaticPublisher\Admin::render();
$files_notice_html = (string) ob_get_clean();
unset($_GET['tab'], $_GET['archive_notice'], $_GET['deleted']);
if (! str_contains($files_notice_html, 'notice-success inline is-dismissible ragstat-files-notice')
    || ! str_contains($files_notice_html, '1 ZIP file has been deleted.')) {
    fwrite(STDERR, "Files silme bildirimi içerik alanında inline olarak gösterilmiyor.\n");
    exit(1);
}

$admin_source = (string) file_get_contents(RAGSTAT_DIR . 'includes/class-admin.php');

foreach (['Search', 'Fuse.js 7.3.0', 'Indexing Selectors', 'Fuse.js Fields and Weights'] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Arama sekmesinde beklenen içerik bulunamadı: {$expected}\n");
        exit(1);
    }
}

foreach (['Create Static Site', "esc_html_e('Download'"] as $expected) {
    if (! str_contains($admin_source, $expected)) {
        fwrite(STDERR, "Main sekmesinde beklenen buton metni bulunamadı: {$expected}\n");
        exit(1);
    }
}
if (str_contains($admin_source, '<th>Kod</th>') || str_contains($admin_source, 'ragstat-http-code')) {
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
if (substr_count($admin_source, 'class="ragstat-time-column"') < 2) {
    fwrite(STDERR, "Activity Log saat sütunu sınıfı eksik.\n");
    exit(1);
}
foreach (['Check Again', 'ragnus_static_refresh_diagnostics', 'Diagnostics::report()'] as $expected) {
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
        fwrite(STDERR, "Settings otomatik deploy kartında beklenen içerik bulunamadı: {$expected}\n");
        exit(1);
    }
}
foreach (['transition_post_status', 'created_term', 'wp_update_nav_menu', 'upgrader_process_complete', 'updated_option'] as $hook) {
    if (! has_action($hook)) {
        fwrite(STDERR, "Otomatik export hook kaydı bulunamadı: {$hook}\n");
        exit(1);
    }
}

foreach ([
    'zip' => ['Open ZIP Files'],
    'github' => ['GitHub Deployment Webhook', 'Save Deploy Settings'],
    'cloudflare' => ['Cloudflare account required', 'Open Cloudflare'],
    'sftp' => ['SFTP Connection', 'Save SFTP Settings', 'Test Connection', 'Upload Latest Static Site'],
] as $deploy_tab => $panel_expectations) {
    $_GET = ['tab' => 'deploy', 'deploy_tab' => $deploy_tab];
    ob_start();
    Ragnus\StaticPublisher\Admin::render();
    $deploy_html = (string) ob_get_clean();

    foreach (['ZIP File', 'GitHub', 'Cloudflare', 'SFTP', 'ragstat-deploy-tabs', 'deploy_tab=' . $deploy_tab, ...$panel_expectations] as $expected) {
        if (! str_contains($deploy_html, $expected)) {
            fwrite(STDERR, "Deploy {$deploy_tab} sekmesinde beklenen içerik bulunamadı: {$expected}\n");
            exit(1);
        }
    }
    if (substr_count($deploy_html, 'ragstat-deploy-tab is-active') !== 1) {
        fwrite(STDERR, "Deploy {$deploy_tab} ekranında tek bir etkin dikey sekme bulunamadı.\n");
        exit(1);
    }
    if ($deploy_tab === 'github' && substr_count($deploy_html, 'data-ragstat-github-icon') < 2) {
        fwrite(STDERR, "GitHub sekmesi ve içerik panelinde GitHub ikonu bulunamadı.\n");
        exit(1);
    }
    if ($deploy_tab === 'cloudflare' && (substr_count($deploy_html, 'data-ragstat-cloudflare-icon') < 2 || substr_count($deploy_html, 'fill="currentColor"') < 4)) {
        fwrite(STDERR, "Cloudflare sekmesi ve içerik panelinde tek renk Cloudflare SVG ikonu bulunamadı.\n");
        exit(1);
    }
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'deploy'];
ob_start();
Ragnus\StaticPublisher\Admin::render();
$legacy_deploy_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($legacy_deploy_html, 'GitHub Deployment Webhook') || ! str_contains($legacy_deploy_html, 'deploy_tab=github')) {
    fwrite(STDERR, "Eski Settings > Deploy bağlantısı yeni üst sekmeye uyarlanmadı.\n");
    exit(1);
}

$_GET = ['tab' => 'seo', 'seo_tab' => 'plugins'];
ob_start();
Ragnus\StaticPublisher\Admin::render();
$seo_html = (string) ob_get_clean();
if (! str_contains($seo_html, 'SEO Plugins') || ! str_contains($seo_html, 'Rank Math SEO') || ! str_contains($seo_html, 'All in One SEO') || ! str_contains($seo_html, 'aioseo_enabled') || ! str_contains($seo_html, 'Save SEO Plugin Settings')) {
    fwrite(STDERR, "SEO Plugins dikey sekmesi, Rank Math veya All in One SEO ayarları bulunamadı.\n");
    exit(1);
}

$_GET = ['tab' => 'seo', 'seo_tab' => 'language'];
ob_start();
Ragnus\StaticPublisher\Admin::render();
$seo_language_html = (string) ob_get_clean();
if (! str_contains($seo_language_html, 'Redirection by Browser Language') || ! str_contains($seo_language_html, 'Save Language Settings')) {
    fwrite(STDERR, "SEO > Language dikey sekmesinde dil ayarları bulunamadı.\n");
    exit(1);
}

$_GET = ['tab' => 'settings', 'settings_tab' => 'languages'];
ob_start();
Ragnus\StaticPublisher\Admin::render();
$legacy_languages_html = (string) ob_get_clean();
$_GET = [];
if (! str_contains($legacy_languages_html, 'SEO Plugins') || ! str_contains($legacy_languages_html, 'tab=seo')) {
    fwrite(STDERR, "Eski Settings > Languages bağlantısı yeni SEO üst sekmesine uyarlanmadı.\n");
    exit(1);
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
