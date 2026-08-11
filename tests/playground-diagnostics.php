<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'ragnus-static-publisher/ragnus-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
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
$groups = Ragnus\StaticPublisher\Diagnostics::checks($response);

if (array_keys($groups) !== ['Server', 'WordPress']) {
    throw new RuntimeException('Diagnostics grupları beklenen sırada değil.');
}
if (count($groups['Server']) !== 5 || count($groups['WordPress']) !== 4) {
    throw new RuntimeException('Diagnostics beklenen dokuz kontrolü içermiyor.');
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

foreach (['php-version', 'basic-auth', 'php-xml', 'curl', 'docker-site-url', 'permalinks', 'indexable', 'caching', 'wp-cron'] as $expected_id) {
    if (! isset($checks[$expected_id])) {
        throw new RuntimeException('Diagnostics kontrolü eksik: ' . $expected_id);
    }
}
if (! $checks['docker-site-url']['passed']) {
    throw new RuntimeException('Başarılı HTTP yanıtı site erişimi kontrolünden geçmedi.');
}

$unauthorized_response = $response;
$unauthorized_response['headers'] = ['www-authenticate' => 'Basic realm="Restricted"'];
$unauthorized_response['response']['code'] = 401;
$unauthorized_groups = Ragnus\StaticPublisher\Diagnostics::checks($unauthorized_response);
$unauthorized_checks = [];
foreach ($unauthorized_groups['Server'] as $check) {
    $unauthorized_checks[$check['id']] = $check;
}
if ($unauthorized_checks['basic-auth']['passed'] || $unauthorized_checks['docker-site-url']['passed']) {
    throw new RuntimeException('Basic Auth veya erişilemeyen site durumu doğru algılanmadı.');
}

echo "Diagnostics integration test passed.\n";
