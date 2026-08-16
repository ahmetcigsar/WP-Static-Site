<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'wext-static-publisher/wext-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}
do_action('init');
wp_set_current_user(1);

add_filter('wext_static_deploy_service_url', static fn (): string => 'https://deploy.example.com');
$authorization_url = Wext\StaticPublisher\Managed_Deployer::authorization_url();
parse_str((string) parse_url($authorization_url, PHP_URL_QUERY), $authorization_query);
$state = (string) ($authorization_query['state'] ?? '');
if ($state === '' || ! str_starts_with($authorization_url, 'https://deploy.example.com/connect/cloudflare?')) {
    throw new RuntimeException('Yönetilen servis bağlantı URL ve state üretimi başarısız.');
}

$access_token = 'managed-access-token';
$callback_secret = str_repeat('c', 48);
$deployment_request = [];
add_filter('pre_http_request', static function ($preempt, $args, $url) use ($access_token, $callback_secret, &$deployment_request) {
    if ($url === 'https://deploy.example.com/v1/wordpress/connections/exchange') {
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'access_token' => $access_token,
                'callback_secret' => $callback_secret,
                'account_label' => 'Wext Test Account',
                'domain' => 'static.example.com',
                'deployment_url' => 'https://static.example.com',
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($url === 'https://deploy.example.com/v1/deployments') {
        $deployment_request = $args;
        return [
            'headers' => [],
            'body' => '{"accepted":true}',
            'response' => ['code' => 202, 'message' => 'Accepted'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    return $preempt;
}, 10, 3);

$public = Wext\StaticPublisher\Managed_Deployer::complete_connection($state, 'one-time-code');
$stored = get_option(Wext\StaticPublisher\Managed_Deployer::CONNECTION_KEY, []);
if (empty($public['connected'])
    || ($public['domain'] ?? '') !== 'static.example.com'
    || ($stored['access_token'] ?? '') === $access_token
    || ($stored['callback_secret'] ?? '') === $callback_secret
    || Wext\StaticPublisher\Secret_Store::decrypt((string) $stored['access_token']) !== $access_token
    || (Wext\StaticPublisher\Plugin::settings()['deployment_mode'] ?? '') !== 'managed') {
    throw new RuntimeException('Yönetilen servis bağlantısı güvenli biçimde kaydedilmedi.');
}

$job_id = 'managed-test-job';
$artifact_url = Wext\StaticPublisher\Managed_Deployer::signed_artifact_url($job_id);
parse_str((string) parse_url($artifact_url, PHP_URL_QUERY), $artifact_query);
if (! Wext\StaticPublisher\Managed_Deployer::verify_artifact(
    $job_id,
    (int) ($artifact_query['expires'] ?? 0),
    (string) ($artifact_query['signature'] ?? '')
)) {
    throw new RuntimeException('Süreli imzalı paket bağlantısı doğrulanamadı.');
}
if (Wext\StaticPublisher\Managed_Deployer::verify_artifact($job_id, time() - 1, 'invalid')) {
    throw new RuntimeException('Süresi dolmuş paket bağlantısı kabul edildi.');
}

$callback_body = wp_json_encode(['job_id' => $job_id, 'state' => 'completed']);
$timestamp = (string) time();
$callback_signature = hash_hmac('sha256', $timestamp . '.' . $callback_body, $callback_secret);
if (! Wext\StaticPublisher\Managed_Deployer::verify_callback($timestamp, $callback_signature, $callback_body)
    || Wext\StaticPublisher\Managed_Deployer::verify_callback((string) (time() - 1000), $callback_signature, $callback_body)) {
    throw new RuntimeException('İmzalı callback zaman veya imza kontrolü başarısız.');
}

$checksum = str_repeat('a', 64);
if (! Wext\StaticPublisher\Managed_Deployer::notify_export($job_id, ['build_sha256' => $checksum])) {
    throw new RuntimeException('Yönetilen deployment isteği başlatılamadı.');
}
$deployment_payload = json_decode((string) ($deployment_request['body'] ?? ''), true);
if (($deployment_request['headers']['Authorization'] ?? '') !== 'Bearer ' . $access_token
    || ($deployment_payload['job_id'] ?? '') !== $job_id
    || ($deployment_payload['build_sha256'] ?? '') !== $checksum
    || ! str_contains((string) ($deployment_payload['artifact_url'] ?? ''), '/managed-artifact')
    || str_contains((string) ($deployment_payload['artifact_url'] ?? ''), $callback_secret)) {
    throw new RuntimeException('Yönetilen deployment isteği beklenen güvenlik sözleşmesine uymuyor.');
}

echo "Wext Static Publisher managed deploy test passed.\n";
