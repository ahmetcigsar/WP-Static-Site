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

$initial_report = get_option(Ragnus\StaticPublisher\Diagnostics::OPTION_KEY, []);
if (! is_array($initial_report['groups'] ?? null) || ! is_string($initial_report['checked_at'] ?? null)) {
    throw new RuntimeException('Diagnostics kontrolleri ilk kurulumda kaydedilmedi.');
}

update_option('permalink_structure', '/%postname%/');
update_option('blog_public', '1');

$response = [
    'headers' => [],
    'body' => '',
    'response' => ['code' => 200, 'message' => 'OK'],
    'cookies' => [],
    'filename' => null,
];
$grants = ['GRANT ALL PRIVILEGES ON `wordpress`.* TO `wordpress`@`%`'];
$groups = Ragnus\StaticPublisher\Diagnostics::checks($response, $grants, []);

if (array_keys($groups) !== ['Server', 'WordPress', 'Plugins', 'File System', 'MySQL']) {
    throw new RuntimeException('Diagnostics grupları beklenen sırada değil.');
}
if (count($groups['Server']) !== 5
    || count($groups['WordPress']) !== 4
    || count($groups['Plugins']) !== 1
    || count($groups['File System']) !== 2
    || count($groups['MySQL']) !== 6) {
    throw new RuntimeException('Diagnostics beklenen on sekiz kontrolü içermiyor.');
}

$checks = [];
foreach ($groups as $group_checks) {
    foreach ($group_checks as $check) {
        $checks[$check['id']] = $check;
        if (! is_bool($check['passed']) || trim((string) $check['message']) === '') {
            throw new RuntimeException('Diagnostics sonucu geçerli durum ve açıklama içermiyor.');
        }
    }
}

foreach (['php-version', 'basic-auth', 'php-xml', 'curl', 'docker-site-url', 'permalinks', 'indexable', 'caching', 'wp-cron', 'incompatible-plugins', 'temp-directory-readable', 'temp-directory-writable', 'mysql-delete', 'mysql-insert', 'mysql-select', 'mysql-create', 'mysql-alter', 'mysql-drop'] as $expected_id) {
    if (! isset($checks[$expected_id])) {
        throw new RuntimeException('Diagnostics kontrolü eksik: ' . $expected_id);
    }
}
if (! $checks['docker-site-url']['passed']) {
    throw new RuntimeException('Başarılı HTTP yanıtı site erişimi kontrolünden geçmedi.');
}
foreach ($groups['MySQL'] as $mysql_check) {
    if (! $mysql_check['passed']) {
        throw new RuntimeException('ALL PRIVILEGES MySQL yetkileri doğru algılanmadı.');
    }
}

$incompatible_groups = Ragnus\StaticPublisher\Diagnostics::checks(
    $response,
    $grants,
    ['password-protected/password-protected.php']
);
if ($incompatible_groups['Plugins'][0]['passed']
    || ! str_contains($incompatible_groups['Plugins'][0]['message'], 'Password Protected')) {
    throw new RuntimeException('Uyumsuz etkin eklenti doğru algılanmadı.');
}

$unauthorized_response = $response;
$unauthorized_response['headers'] = ['www-authenticate' => 'Basic realm="Restricted"'];
$unauthorized_response['response']['code'] = 401;
$unauthorized_groups = Ragnus\StaticPublisher\Diagnostics::checks($unauthorized_response, $grants, []);
$unauthorized_checks = [];
foreach ($unauthorized_groups['Server'] as $check) {
    $unauthorized_checks[$check['id']] = $check;
}
if ($unauthorized_checks['basic-auth']['passed'] || $unauthorized_checks['docker-site-url']['passed']) {
    throw new RuntimeException('Basic Auth veya erişilemeyen site durumu doğru algılanmadı.');
}

update_option(Ragnus\StaticPublisher\Diagnostics::OPTION_KEY, [
    'checked_at' => gmdate('c'),
    'locale' => determine_locale(),
    'groups' => ['Test' => [[
        'id' => 'failed-test',
        'label' => 'Başarısız Test',
        'passed' => false,
        'message' => 'Test başarısız.',
    ]]],
], false);
wp_set_current_user(1);
$_GET['tab'] = 'diagnostics';
ob_start();
Ragnus\StaticPublisher\Admin::render();
$diagnostics_html = (string) ob_get_clean();
unset($_GET['tab']);
foreach (['Check Again', 'is-failed', 'dashicons-no-alt', 'aria-label="Failed"'] as $expected) {
    if (! str_contains($diagnostics_html, $expected)) {
        throw new RuntimeException('Diagnostics olumsuz durum arayüzü eksik: ' . $expected);
    }
}

echo "Diagnostics integration test passed.\n";
