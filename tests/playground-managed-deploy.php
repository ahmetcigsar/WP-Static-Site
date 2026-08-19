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

$access_token = 'managed-access-token';
$callback_secret = str_repeat('c', 48);
$deployment_request = [];
$activation_request = [];
$ticket_request = [];
$detach_request = [];
$legacy_detach_request = [];
$license_rejected = false;
add_filter('wext_static_deploy_service_url', static fn (): string => 'https://deploy.example.com');
add_filter('pre_http_request', static function ($preempt, $args, $url) use ($access_token, $callback_secret, &$deployment_request, &$activation_request, &$ticket_request, &$detach_request, &$legacy_detach_request, &$license_rejected) {
    if ($url === 'https://deploy.example.com/v1/wordpress/license-activations') {
        $activation_request = $args;
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'activation_id' => '11111111-1111-4111-8111-111111111111',
                'site_id' => '22222222-2222-4222-8222-222222222222',
                'plan' => ['code' => 'pro', 'site_limit' => 3],
                'activation_credential' => 'wext_act_' . str_repeat('a', 48),
                'detach_credential' => 'wext_detach_' . str_repeat('d', 48),
                'expires_at' => gmdate('c', time() + 900),
            ]),
            'response' => ['code' => 201, 'message' => 'Created'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($url === 'https://deploy.example.com/v1/wordpress/connections/tickets') {
        $ticket_request = $args;
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'connect_ticket' => 'wext_ct_' . str_repeat('t', 48),
                'expires_at' => gmdate('c', time() + 300),
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
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
        if ($license_rejected) {
            return [
                'headers' => [],
                'body' => wp_json_encode(['error' => ['code' => 'license_inactive', 'request_id' => 'license-test']]),
                'response' => ['code' => 403, 'message' => 'Forbidden'],
                'cookies' => [],
                'filename' => null,
            ];
        }
        $deployment_request = $args;
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'deployment_id' => '33333333-3333-4333-8333-333333333333',
                'job_id' => 'managed-test-job',
                'status' => 'queued',
                'created_at' => gmdate('c'),
            ]),
            'response' => ['code' => 202, 'message' => 'Accepted'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($url === 'https://deploy.example.com/v1/wordpress/license-activations/current') {
        $detach_request = $args;
        return [
            'headers' => [],
            'body' => wp_json_encode(['status' => 'deactivated']),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($url === 'https://deploy.example.com/v1/wordpress/license-activations/current/detach') {
        $legacy_detach_request = $args;
        return [
            'headers' => [],
            'body' => wp_json_encode(['status' => 'deactivated']),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    return $preempt;
}, 10, 3);

$license = Wext\StaticPublisher\Managed_Deployer::activate_license('wext-license-key-1234567890');
$stored_license = get_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, []);
$activation_payload = json_decode((string) ($activation_request['body'] ?? ''), true);
if (empty($license['active'])
    || ($license['plan_code'] ?? '') !== 'pro'
    || ($activation_payload['license_key'] ?? '') !== 'wext-license-key-1234567890'
    || ! str_starts_with((string) ($activation_payload['installation_id'] ?? ''), 'wext_')
    || str_contains(wp_json_encode($stored_license), 'wext-license-key-1234567890')
    || str_contains((string) ($stored_license['activation_credential'] ?? ''), 'wext_act_')
    || str_contains((string) ($stored_license['detach_credential'] ?? ''), 'wext_detach_')) {
    throw new RuntimeException('Lisans aktivasyonu güvenli biçimde kaydedilmedi.');
}

$authorization_url = Wext\StaticPublisher\Managed_Deployer::authorization_url();
parse_str((string) parse_url($authorization_url, PHP_URL_QUERY), $authorization_query);
if ((string) ($authorization_query['state'] ?? '') === ''
    || ! str_starts_with($authorization_url, 'https://deploy.example.com/connect/cloudflare?')
    || ! str_starts_with((string) ($authorization_query['connect_ticket'] ?? ''), 'wext_ct_')
    || ! str_starts_with((string) ($ticket_request['headers']['Authorization'] ?? ''), 'Bearer wext_act_')) {
    throw new RuntimeException('Yönetilen servis connect-ticket, URL ve state üretimi başarısız.');
}

$stored_license['credential_expires_at'] = gmdate('c', time() - 1);
update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, $stored_license, false);
$ticket_request = [];
$authorization_url = Wext\StaticPublisher\Managed_Deployer::authorization_url();
parse_str((string) parse_url($authorization_url, PHP_URL_QUERY), $authorization_query);
$state = (string) ($authorization_query['state'] ?? '');
if ($state === ''
    || empty(Wext\StaticPublisher\Managed_Deployer::public_license()['credential_available'])
    || ! str_starts_with((string) ($ticket_request['headers']['Authorization'] ?? ''), 'Bearer wext_detach_')) {
    throw new RuntimeException('Süresi dolan aktivasyon yetkisi kalıcı site credential ile yenilenemedi.');
}

$public = Wext\StaticPublisher\Managed_Deployer::complete_connection($state, 'one-time-code');
$stored = get_option(Wext\StaticPublisher\Managed_Deployer::CONNECTION_KEY, []);
if (empty($public['connected'])
    || ($public['domain'] ?? '') !== 'static.example.com'
    || ($stored['access_token'] ?? '') === $access_token
    || ($stored['callback_secret'] ?? '') === $callback_secret
    || Wext\StaticPublisher\Secret_Store::decrypt((string) $stored['access_token']) !== $access_token
    || (Wext\StaticPublisher\Plugin::settings()['deployment_mode'] ?? '') !== 'managed'
    || (Wext\StaticPublisher\Plugin::settings()['target_url'] ?? '') !== 'https://static.example.com') {
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

$callback_body = wp_json_encode([
    'delivery_id' => '44444444-4444-4444-8444-444444444444',
    'job_id' => $job_id,
    'build_sha256' => str_repeat('a', 64),
    'status' => 'completed',
    'deployment_url' => 'https://static.example.com',
]);
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
$deployment_status = Wext\StaticPublisher\Plugin::deployment_status();
if (($deployment_request['headers']['Authorization'] ?? '') !== 'Bearer ' . $access_token
    || ($deployment_payload['job_id'] ?? '') !== $job_id
    || ($deployment_payload['build_sha256'] ?? '') !== $checksum
    || ! str_contains((string) ($deployment_payload['artifact_url'] ?? ''), '/managed-artifact')
    || str_contains((string) ($deployment_payload['artifact_url'] ?? ''), $callback_secret)
    || ($deployment_status['service_deployment_id'] ?? '') !== '33333333-3333-4333-8333-333333333333'
    || ($deployment_status['service_state'] ?? '') !== 'queued'
    || empty($deployment_status['service_created_at'])) {
    throw new RuntimeException('Yönetilen deployment isteği beklenen güvenlik sözleşmesine uymuyor.');
}

if (! Wext\StaticPublisher\Managed_Deployer::claim_callback_delivery('delivery-1')
    || Wext\StaticPublisher\Managed_Deployer::claim_callback_delivery('delivery-1')) {
    throw new RuntimeException('Yönetilen callback replay kontrolü başarısız.');
}

$license_rejected = true;
if (Wext\StaticPublisher\Managed_Deployer::notify_export('inactive-license-job', ['build_sha256' => $checksum])
    || ! empty(Wext\StaticPublisher\Managed_Deployer::public_license()['active'])
    || Wext\StaticPublisher\Plugin::license_active()
    || Wext\StaticPublisher\Managed_Deployer::configured()) {
    throw new RuntimeException('Servisin pasif saydığı lisans yerel premium özellik kilidini kapatmadı.');
}

Wext\StaticPublisher\Managed_Deployer::deactivate_license();
if (($detach_request['method'] ?? '') !== 'DELETE'
    || ! str_starts_with((string) ($detach_request['headers']['Authorization'] ?? ''), 'Bearer wext_detach_')
    || get_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, null) !== null
    || get_option(Wext\StaticPublisher\Managed_Deployer::CONNECTION_KEY, null) !== null
    || (Wext\StaticPublisher\Plugin::settings()['deployment_mode'] ?? '') !== 'advanced') {
    throw new RuntimeException('Detach License işlemi site aktivasyonunu ve yerel bağlantıyı temizlemedi.');
}

update_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, [
    'active' => '1',
    'activation_id' => '11111111-1111-4111-8111-111111111111',
    'site_id' => '22222222-2222-4222-8222-222222222222',
    'plan_code' => 'pro',
], false);
delete_option(Wext\StaticPublisher\Managed_Deployer::CONNECTION_KEY);
Wext\StaticPublisher\Managed_Deployer::deactivate_license();
$legacy_detach_payload = json_decode((string) ($legacy_detach_request['body'] ?? ''), true);
if (($legacy_detach_request['method'] ?? '') !== 'POST'
    || isset($legacy_detach_request['headers']['Authorization'])
    || ($legacy_detach_payload['activation_id'] ?? '') !== '11111111-1111-4111-8111-111111111111'
    || ($legacy_detach_payload['installation_id'] ?? '') !== ($activation_payload['installation_id'] ?? '')
    || ($legacy_detach_payload['site_url'] ?? '') !== home_url()
    || get_option(Wext\StaticPublisher\Managed_Deployer::LICENSE_KEY, null) !== null) {
    throw new RuntimeException('Eski aktivasyonun güvenli Detach License uyumluluk akışı başarısız.');
}

echo "Wext Static Publisher managed deploy test passed.\n";
