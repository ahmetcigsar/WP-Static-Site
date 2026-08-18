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
wp_set_current_user(1);
update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, [
    'active' => '1',
    'activation_id' => 'activation-i18n',
    'site_id' => 'site-i18n',
    'plan_code' => 'pro',
    'site_limit' => 1,
    'activated_at' => gmdate('c'),
], false);
switch_to_locale('en_US');
unload_textdomain('wext-static-publisher');
Wext\StaticPublisher\Plugin::load_textdomain();

$screens = [
    'main' => ['tab' => 'main'],
    'settings-general' => ['tab' => 'settings', 'settings_tab' => 'general'],
    'settings-headless' => ['tab' => 'settings', 'settings_tab' => 'headless'],
    'settings-multilingual' => ['tab' => 'settings', 'settings_tab' => 'multilingual'],
    'seo-plugins' => ['tab' => 'seo', 'seo_tab' => 'plugins'],
    'seo-audit' => ['tab' => 'seo', 'seo_tab' => 'audit'],
    'seo-sitemaps' => ['tab' => 'seo', 'seo_tab' => 'sitemaps'],
    'seo-redirects' => ['tab' => 'seo', 'seo_tab' => 'redirects'],
    'seo-indexing' => ['tab' => 'seo', 'seo_tab' => 'indexing'],
    'seo-performance' => ['tab' => 'seo', 'seo_tab' => 'performance'],
    'deploy-zip' => ['tab' => 'deploy', 'deploy_tab' => 'zip'],
    'deploy-github' => ['tab' => 'deploy', 'deploy_tab' => 'github'],
    'deploy-cloudflare' => ['tab' => 'deploy', 'deploy_tab' => 'cloudflare'],
    'deploy-sftp' => ['tab' => 'deploy', 'deploy_tab' => 'sftp'],
    'deploy-auto' => ['tab' => 'deploy', 'deploy_tab' => 'auto-deploy'],
    'search-static' => ['tab' => 'search', 'search_tab' => 'static'],
    'search-selectors' => ['tab' => 'search', 'search_tab' => 'selectors'],
    'search-fuse' => ['tab' => 'search', 'search_tab' => 'fuse'],
    'hide-directory' => ['tab' => 'hide', 'hide_tab' => 'directory'],
    'hide-traces' => ['tab' => 'hide', 'hide_tab' => 'traces'],
    'hide-static-outputs' => ['tab' => 'hide', 'hide_tab' => 'static-outputs'],
    'diagnostics' => ['tab' => 'diagnostics'],
    'activity' => ['tab' => 'activity'],
    'about' => ['tab' => 'about', 'about_tab' => 'about'],
    'about-license' => ['tab' => 'about', 'about_tab' => 'license'],
    'about-support' => ['tab' => 'about', 'about_tab' => 'support'],
];

$expected = [
    'main' => ['Publishing Status', 'Status', 'Create Static Site'],
    'settings-general' => ['Static Site', 'General', 'Headless CMS', 'Multilingual', 'Save General Settings'],
    'settings-headless' => ['Headless CMS', 'Headless + Static Publisher', 'Visitor Response', 'Save Headless CMS Settings'],
    'settings-multilingual' => ['Static Site', 'Multilingual', 'Redirection by Browser Language', 'Save Language Settings'],
    'seo-plugins' => ['SEO Plugins', 'Rank Math SEO', 'Metadata', 'Technical SEO', 'Save SEO Plugin Settings'],
    'seo-audit' => ['SEO Audit', 'Create SEO audit report', 'Validate JSON-LD structured data', 'Save SEO Settings'],
    'seo-sitemaps' => ['Sitemaps', 'Generate Wext sitemap', 'Include images', 'Save SEO Settings'],
    'seo-redirects' => ['Redirects', 'Custom Redirect Rules', 'Redirect WordPress old slugs', 'Save SEO Settings'],
    'seo-indexing' => ['Indexing', 'X-Robots-Tag Rules', 'IndexNow Key', 'Save SEO Settings'],
    'seo-performance' => ['Performance', 'Large HTML Threshold (KB)', 'Large Asset Threshold (KB)', 'Save SEO Settings'],
    'deploy-zip' => ['ZIP File', 'ZIP Files', 'Number of ZIPs to Store', 'Save ZIP Settings'],
    'deploy-github' => ['GitHub Deployment Webhook', 'Save Deploy Settings'],
    'deploy-cloudflare' => ['License and connection required', 'Cloudflare Connection', 'Cloudflare Deploy', 'Deploy to Cloudflare'],
    'deploy-sftp' => ['SFTP Connection', 'Save SFTP Settings', 'Test Connection'],
    'deploy-auto' => ['Auto Deploy', 'Automatic Static Site Creation and Deploy', 'Save Auto Deploy Settings'],
    'search-static' => ['Static Search', 'Search Page Path', 'Save Search Settings'],
    'search-selectors' => ['Indexing Selectors', 'CSS Selector For Title', 'Save Search Settings'],
    'search-fuse' => ['Fuse.js', 'Category and Tags', 'Save Search Settings'],
    'hide-directory' => ['Directory', 'Traces', 'Static Outputs', 'WP-Content Directory', 'Save Hide Settings'],
    'hide-traces' => ['Directory', 'Traces', 'Static Outputs', 'Hide WordPress Version', 'Save Hide Settings'],
    'hide-static-outputs' => ['Directory', 'Traces', 'Static Outputs', 'Disable XML-RPC Links', 'Save Hide Settings'],
    'diagnostics' => ['checks passed', 'Last checked:', 'Check Again'],
    'activity' => ['Activity Logs', 'Search source or static URL...'],
    'about' => ['About', 'Version Number', 'Plugin Website', 'Pro v' . WEXTSTAT_VERSION],
    'about-license' => ['About', 'License', 'Wext License', 'Active', 'Plan'],
    'about-support' => ['About', 'Support', 'Wext Support', 'Plugin Website'],
];

$forbidden_fragments = [
    'Ayar', 'Ara...', 'Arama', 'Başarılı', 'Çalış', 'Devam', 'Dil ', 'Dizin',
    'Durum', 'Etkinleştir', 'Genel', 'Gizle', 'İçerik', 'Kaydet', 'Kayıt',
    'Kontrol', 'Önceki', 'Örnek', 'Sayfa', 'Seç', 'Sonraki', 'Yapı', 'Yayın',
];

foreach ($screens as $screen => $query) {
    $_GET = $query;
    ob_start();
    Wext\StaticPublisher\Admin::render();
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
echo "Wext Static Publisher English UI audit passed.\n";
