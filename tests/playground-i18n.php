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

$expected_translations = [
    'tr_TR' => 'Ayarlar',
    'en_US' => 'Settings',
    'es_ES' => 'Ajustes',
    'fr_FR' => 'Réglages',
    'zh_CN' => '设置',
    'ja' => '設定',
    'ar' => 'الإعدادات',
    'pt_BR' => 'Configurações',
    'pt_PT' => 'Definições',
];

foreach ($expected_translations as $locale => $expected) {
    restore_current_locale();
    switch_to_locale($locale);
    unload_textdomain('ragnus-static-publisher');
    Ragnus\StaticPublisher\Plugin::load_textdomain();

    $actual = __('Settings', 'ragnus-static-publisher');
    if ($actual !== $expected) {
        $mofile = RAGSTAT_DIR . 'languages/ragnus-static-publisher-' . $locale . '.mo';
        $direct_loaded = load_textdomain('ragnus-static-publisher', $mofile);
        fwrite(STDERR, sprintf(
            "%s çevirisi beklenen değerde değil: %s (determine_locale=%s, file=%s, direct=%s, after=%s)\n",
            $locale,
            $actual,
            determine_locale(),
            is_readable($mofile) ? 'readable' : 'missing',
            $direct_loaded ? 'loaded' : 'failed',
            __('Settings', 'ragnus-static-publisher')
        ));
        exit(1);
    }

}

restore_current_locale();

$unsupported_locale = static fn (): string => 'de_DE';
add_filter('locale', $unsupported_locale);
unload_textdomain('ragnus-static-publisher');
Ragnus\StaticPublisher\Plugin::load_textdomain();
if (__('Settings', 'ragnus-static-publisher') !== 'Settings') {
    fwrite(STDERR, "Desteklenmeyen WordPress dili İngilizce arayüze dönmedi.\n");
    exit(1);
}
remove_filter('locale', $unsupported_locale);

echo "Ragnus Static Publisher i18n test passed.\n";
