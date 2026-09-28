<?php

declare(strict_types=1);

// Simulate language packs supplied by translate.wordpress.org, outside the plugin package.
$language_dir = '/wordpress/wp-content/languages/plugins';
if (! is_dir($language_dir)) { mkdir($language_dir, 0777, true); }
foreach (glob('/workspace/wordpress-org/translations/*.mo') ?: [] as $translation) {
    copy($translation, $language_dir . '/' . basename($translation));
}

if (! defined('ABSPATH') && file_exists('/wordpress/wp-load.php')) {
    require '/wordpress/wp-load.php';
}

if (! defined('ABSPATH')) {
    fwrite(STDERR, "WordPress bootstrap dosyası bulunamadı.\n");
    exit(1);
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'wext-static-publisher/wext-static-publisher.php';
$result = activate_plugin($plugin);
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . "\n");
    exit(1);
}

do_action('init');

$expected_translations = [
    'tr_TR' => 'Statik Site',
    'en_US' => 'Static Site',
    'es_ES' => 'Sitio estático',
    'fr_FR' => 'Site statique',
    'zh_CN' => '静态站点',
    'ja' => '静的サイト',
    'ar' => 'الموقع الثابت',
    'pt_BR' => 'Site estático',
    'pt_PT' => 'Site estático',
];
$expected_multilingual_translations = [
    'tr_TR' => 'Çoklu Dil',
    'en_US' => 'Multilingual',
    'es_ES' => 'Multilingüe',
    'fr_FR' => 'Multilingue',
    'zh_CN' => '多语言',
    'ja' => '多言語',
    'ar' => 'متعدد اللغات',
    'pt_BR' => 'Multilíngue',
    'pt_PT' => 'Multilingue',
];
$expected_auto_deploy_translations = [
    'tr_TR' => 'Otomatik Deploy',
    'en_US' => 'Auto Deploy',
    'es_ES' => 'Despliegue automático',
    'fr_FR' => 'Déploiement automatique',
    'zh_CN' => '自动部署',
    'ja' => '自動デプロイ',
    'ar' => 'النشر التلقائي',
    'pt_BR' => 'Deploy automático',
    'pt_PT' => 'Deploy automático',
];
$expected_save_zip_translations = [
    'tr_TR' => 'ZIP Ayarlarını Kaydet',
    'en_US' => 'Save ZIP Settings',
    'es_ES' => 'Guardar ajustes de ZIP',
    'fr_FR' => 'Enregistrer les réglages ZIP',
    'zh_CN' => '保存 ZIP 设置',
    'ja' => 'ZIP 設定を保存',
    'ar' => 'حفظ إعدادات ZIP',
    'pt_BR' => 'Salvar configurações de ZIP',
    'pt_PT' => 'Guardar definições de ZIP',
];

foreach ($expected_translations as $locale => $expected) {
    restore_current_locale();
    switch_to_locale($locale);
    unload_textdomain('wext-static-publisher', true);

    $actual = __('Static Site', 'wext-static-publisher');
    if ($actual !== $expected) {
        $mofile = WP_LANG_DIR . '/plugins/wext-static-publisher-' . $locale . '.mo';
        $direct_loaded = load_textdomain('wext-static-publisher', $mofile);
        fwrite(STDERR, sprintf(
            "%s çevirisi beklenen değerde değil: %s (determine_locale=%s, file=%s, direct=%s, after=%s)\n",
            $locale,
            $actual,
            determine_locale(),
            is_readable($mofile) ? 'readable' : 'missing',
            $direct_loaded ? 'loaded' : 'failed',
            __('Static Site', 'wext-static-publisher')
        ));
        exit(1);
    }

    $multilingual_actual = __('Multilingual', 'wext-static-publisher');
    if ($multilingual_actual !== $expected_multilingual_translations[$locale]) {
        fwrite(STDERR, sprintf(
            "%s Çoklu Dil çevirisi beklenen değerde değil: %s\n",
            $locale,
            $multilingual_actual
        ));
        exit(1);
    }

    $auto_deploy_actual = __('Auto Deploy', 'wext-static-publisher');
    if ($auto_deploy_actual !== $expected_auto_deploy_translations[$locale]) {
        fwrite(STDERR, sprintf(
            "%s Auto Deploy çevirisi beklenen değerde değil: %s\n",
            $locale,
            $auto_deploy_actual
        ));
        exit(1);
    }

    $save_zip_actual = __('Save ZIP Settings', 'wext-static-publisher');
    if ($save_zip_actual !== $expected_save_zip_translations[$locale]) {
        fwrite(STDERR, sprintf(
            "%s ZIP ayarları kaydet çevirisi beklenen değerde değil: %s\n",
            $locale,
            $save_zip_actual
        ));
        exit(1);
    }

    if ($locale === 'tr_TR') {
        foreach (['SFTP Connection' => 'SFTP Bağlantısı', 'Save SFTP Settings' => 'SFTP Ayarlarını Kaydet', 'Test Connection' => 'Bağlantıyı Test Et'] as $source => $sftp_expected) {
            $sftp_actual = __($source, 'wext-static-publisher');
            if ($sftp_actual !== $sftp_expected) {
                fwrite(STDERR, "Türkçe SFTP çevirisi beklenen değerde değil: {$source} => {$sftp_actual}\n");
                exit(1);
            }
        }
    }

}

restore_current_locale();

$unsupported_locale = static fn (): string => 'de_DE';
add_filter('locale', $unsupported_locale);
unload_textdomain('wext-static-publisher', true);
if (__('Settings', 'wext-static-publisher') !== 'Settings') {
    fwrite(STDERR, "Desteklenmeyen WordPress dili İngilizce arayüze dönmedi.\n");
    exit(1);
}
remove_filter('locale', $unsupported_locale);

echo "Wext Static Publisher i18n test passed.\n";
