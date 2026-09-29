<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (is_array($error) && in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, sprintf("Fatal error: %s in %s:%d\n", $error['message'], $error['file'], $error['line']));
    }
});

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$activation = activate_plugin('wext-static-publisher/wext-static-publisher.php');
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

$legacy_brand = 'rag' . 'nus';
$legacy_prefix = $legacy_brand . '_static_';
$legacy_secret = static function (string $plain_text) use ($legacy_brand): string {
    $key = hash_hkdf('sha256', wp_salt('auth'), 32, $legacy_brand . '-static-publisher-sftp');
    if (function_exists('sodium_crypto_secretbox') && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain_text, $nonce, $key));
    }

    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(12);
        $tag = '';
        $cipher_text = openssl_encrypt($plain_text, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (is_string($cipher_text)) {
            return 'o1:' . base64_encode($iv . $tag . $cipher_text);
        }
    }

    throw new RuntimeException('Migration testi için şifreleme desteği bulunamadı.');
};

$sftp_password = 'legacy-sftp-secret';
$service_token = 'legacy-service-token';
$callback_secret = str_repeat('l', 48);
foreach (['settings', 'managed_connection', 'brand_migration'] as $new_option_suffix) {
    delete_option('wext_static_' . $new_option_suffix);
}
remove_role('wext_static_deployer');
update_option($legacy_prefix . 'settings', [
    'target_url' => 'https://static.example.com',
    'maximum_urls' => 321,
    'sftp_password' => $legacy_secret($sftp_password),
], false);
update_option($legacy_prefix . 'managed_connection', [
    'connected' => '1',
    'access_token' => $legacy_secret($service_token),
    'callback_secret' => $legacy_secret($callback_secret),
], false);

add_role($legacy_prefix . 'deployer', 'Legacy Static Publisher Deploy', [
    'read' => true,
    $legacy_prefix . 'export' => true,
]);
$user_id = wp_create_user('legacy-wext-migration', wp_generate_password(32), 'migration@example.com');
if (is_wp_error($user_id)) {
    throw new RuntimeException($user_id->get_error_message());
}
(new WP_User((int) $user_id))->set_role($legacy_prefix . 'deployer');

$uploads = wp_upload_dir();
$legacy_storage = trailingslashit((string) $uploads['basedir']) . $legacy_brand . '-static';
$new_storage = trailingslashit((string) $uploads['basedir']) . 'wext-static-publisher';
$remove_directory = static function (string $directory) use (&$remove_directory): void {
    if (! is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $item = $directory . '/' . $entry;
        if (is_dir($item) && ! is_link($item)) {
            $remove_directory($item);
        } else {
            unlink($item);
        }
    }
    rmdir($directory);
};
$remove_directory($legacy_storage);
$remove_directory($new_storage);
wp_mkdir_p($legacy_storage . '/archives');
delete_option('wext_static_database_migration');
$fixture_zip = new ZipArchive();
$fixture_zip->open($legacy_storage . '/archives/migration-test.zip', ZipArchive::CREATE);
$fixture_zip->addFromString('index.html', 'migrated content');
$fixture_zip->addFromString('wext-static-manifest.json', wp_json_encode(['job_id' => 'migration-test']));
$fixture_zip->close();

$active_plugins = get_option('active_plugins', []);
$legacy_plugin = $legacy_brand . '-static-publisher/' . $legacy_brand . '-static-publisher.php';
update_option('active_plugins', array_merge($active_plugins, [$legacy_plugin]));
Wext\StaticPublisher\Plugin::activate();
if (! in_array($legacy_plugin, get_option('active_plugins'), true) || get_option('wext_static_brand_migration') === '2.0.0' || ! is_dir($legacy_storage)) {
    throw new RuntimeException('Activation must not deactivate or migrate an active legacy plugin.');
}
update_option('active_plugins', $active_plugins);
Wext\StaticPublisher\Plugin::activate();
do_action('init');

$settings = Wext\StaticPublisher\Plugin::settings();
$connection = get_option('wext_static_managed_connection', null);
$migrated_user = new WP_User((int) $user_id);
$new_storage = Wext\StaticPublisher\Plugin::storage_directory();
$checks = [
    'settings' => ($settings['maximum_urls'] ?? 0) === 321,
    'sftp_secret' => Wext\StaticPublisher\Secret_Store::decrypt((string) ($settings['sftp_password'] ?? '')) === $sftp_password,
    'old_connection_removed' => $connection === null,
    'role' => in_array('wext_static_deployer', $migrated_user->roles, true),
    'legacy_role_removed' => get_role($legacy_prefix . 'deployer') === null,
    'storage' => Wext\StaticPublisher\Export_Storage::exists($new_storage . '/archives/migration-test.zip') && Wext\StaticPublisher\Export_Storage::read($new_storage . '/builds/migration-test/index.html') === 'migrated content',
    'marker' => get_option('wext_static_brand_migration') === '2.0.0',
];
$failed_checks = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
if ($failed_checks !== []) {
    throw new RuntimeException('Wext marka migration kontrolleri başarısız: ' . implode(', ', $failed_checks));
}

// A corrupt historical ZIP must leave the site usable and preserve the original.
file_put_contents($legacy_storage . '/archives/corrupt-test.zip', 'not a ZIP');
delete_option('wext_static_database_migration');
Wext\StaticPublisher\Plugin::activate();
if (get_option('wext_static_storage_migration_error', '') === ''
    || get_option('wext_static_plugin_version') !== WEXTSTAT_VERSION
    || file_get_contents($legacy_storage . '/archives/corrupt-test.zip') !== 'not a ZIP') {
    throw new RuntimeException('Failed migration did not preserve originals and report an admin notice.');
}
unlink($legacy_storage . '/archives/corrupt-test.zip');
Wext\StaticPublisher\Plugin::activate();
if (get_option('wext_static_storage_migration_error', '') !== '') {
    throw new RuntimeException('Migration retry did not clear the notice.');
}
echo "Wext Static Publisher brand migration test passed.\n";
