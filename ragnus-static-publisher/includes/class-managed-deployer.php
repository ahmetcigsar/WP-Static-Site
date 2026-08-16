<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

use RuntimeException;
use WP_Error;

final class Managed_Deployer
{
    public const CONNECTION_KEY = 'ragnus_static_managed_connection';
    private const STATE_PREFIX = 'ragnus_static_connect_';
    private const ARTIFACT_TTL = 900;
    private const CALLBACK_TOLERANCE = 300;

    public static function service_url(): string
    {
        $configured = defined('RAGSTAT_DEPLOY_SERVICE_URL') ? (string) RAGSTAT_DEPLOY_SERVICE_URL : '';
        $url = (string) apply_filters('ragnus_static_deploy_service_url', $configured);
        return esc_url_raw(untrailingslashit($url));
    }

    public static function available(): bool
    {
        return self::service_url() !== '';
    }

    public static function connection(): array
    {
        $connection = get_option(self::CONNECTION_KEY, []);
        return is_array($connection) ? wp_parse_args($connection, [
            'connected' => '0',
            'account_label' => '',
            'domain' => '',
            'deployment_url' => '',
            'access_token' => '',
            'callback_secret' => '',
            'connected_at' => '',
        ]) : [];
    }

    public static function configured(): bool
    {
        $connection = self::connection();
        return self::available()
            && (string) ($connection['connected'] ?? '0') === '1'
            && (string) ($connection['access_token'] ?? '') !== ''
            && (string) ($connection['callback_secret'] ?? '') !== '';
    }

    public static function public_connection(): array
    {
        $connection = self::connection();
        return [
            'available' => self::available(),
            'connected' => self::configured(),
            'account_label' => sanitize_text_field((string) ($connection['account_label'] ?? '')),
            'domain' => sanitize_text_field((string) ($connection['domain'] ?? '')),
            'deployment_url' => esc_url_raw((string) ($connection['deployment_url'] ?? '')),
            'connected_at' => sanitize_text_field((string) ($connection['connected_at'] ?? '')),
        ];
    }

    public static function authorization_url(): string
    {
        $service_url = self::service_url();
        if ($service_url === '') {
            throw new RuntimeException(__('Managed deployment service is not configured for this plugin package.', 'ragnus-static-publisher'));
        }

        $state = wp_generate_password(48, false, false);
        set_transient(self::STATE_PREFIX . hash('sha256', $state), '1', 15 * MINUTE_IN_SECONDS);
        return add_query_arg([
            'site_url' => home_url(),
            'callback_url' => self::callback_url(),
            'state' => $state,
            'locale' => determine_locale(),
        ], $service_url . '/connect/cloudflare');
    }

    public static function complete_connection(string $state, string $code): array
    {
        $state_key = self::STATE_PREFIX . hash('sha256', $state);
        if ($state === '' || $code === '' || get_transient($state_key) !== '1') {
            throw new RuntimeException(__('The Cloudflare connection request has expired. Start the connection again.', 'ragnus-static-publisher'));
        }
        delete_transient($state_key);

        $response = wp_remote_post(self::service_url() . '/v1/wordpress/connections/exchange', [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'RagnusStaticPublisher/' . RAGSTAT_VERSION,
            ],
            'body' => wp_json_encode([
                'code' => $code,
                'site_url' => home_url(),
                'callback_url' => self::callback_url(),
            ]),
        ]);
        if ($response instanceof WP_Error) {
            throw new RuntimeException($response->get_error_message());
        }
        if (wp_remote_retrieve_response_code($response) >= 300) {
            throw new RuntimeException(__('Cloudflare connection could not be completed. Try connecting again.', 'ragnus-static-publisher'));
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        $access_token = is_array($payload) ? (string) ($payload['access_token'] ?? '') : '';
        $callback_secret = is_array($payload) ? (string) ($payload['callback_secret'] ?? '') : '';
        if ($access_token === '' || strlen($callback_secret) < 32) {
            throw new RuntimeException(__('The deployment service returned an invalid connection response.', 'ragnus-static-publisher'));
        }

        $connection = [
            'connected' => '1',
            'account_label' => sanitize_text_field((string) ($payload['account_label'] ?? 'Cloudflare')),
            'domain' => sanitize_text_field((string) ($payload['domain'] ?? '')),
            'deployment_url' => esc_url_raw((string) ($payload['deployment_url'] ?? '')),
            'access_token' => Secret_Store::encrypt($access_token),
            'callback_secret' => Secret_Store::encrypt($callback_secret),
            'connected_at' => gmdate('c'),
        ];
        update_option(self::CONNECTION_KEY, $connection, false);

        $settings = Plugin::settings();
        $settings['deployment_mode'] = 'managed';
        update_option(Plugin::SETTINGS_KEY, $settings, false);
        return self::public_connection();
    }

    public static function disconnect(): void
    {
        $connection = self::connection();
        if (self::available() && (string) ($connection['access_token'] ?? '') !== '') {
            try {
                $token = Secret_Store::decrypt((string) $connection['access_token']);
                wp_remote_request(self::service_url() . '/v1/wordpress/connections/current', [
                    'method' => 'DELETE',
                    'timeout' => 10,
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ]);
            } catch (\Throwable $error) {
                error_log('[Ragnus Static Publisher] ' . $error->getMessage());
            }
        }
        delete_option(self::CONNECTION_KEY);
    }

    public static function notify_export(string $job_id, array $manifest): bool
    {
        if (! self::configured()) {
            return false;
        }

        $checksum = strtolower((string) ($manifest['build_sha256'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            Plugin::record_deployment_status($job_id, 'failed', [
                'error' => __('The site package could not be verified before publishing.', 'ragnus-static-publisher'),
            ]);
            return false;
        }

        try {
            $token = Secret_Store::decrypt((string) self::connection()['access_token']);
            $artifact_url = self::signed_artifact_url($job_id);
        } catch (\Throwable $error) {
            Plugin::record_deployment_status($job_id, 'failed', ['error' => $error->getMessage()]);
            return false;
        }

        Plugin::record_deployment_status($job_id, 'waiting', [
            'build_sha256' => $checksum,
            'deployment_url' => '',
            'error' => '',
        ]);
        $response = wp_remote_post(self::service_url() . '/v1/deployments', [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'RagnusStaticPublisher/' . RAGSTAT_VERSION,
            ],
            'body' => wp_json_encode([
                'job_id' => $job_id,
                'build_sha256' => $checksum,
                'artifact_url' => $artifact_url,
                'callback_url' => rest_url('ragnus-static/v1/managed-deployments/callback'),
                'target_url' => (string) (Plugin::settings()['target_url'] ?? ''),
            ]),
        ]);
        if ($response instanceof WP_Error || wp_remote_retrieve_response_code($response) >= 300) {
            Plugin::record_deployment_status($job_id, 'failed', [
                'build_sha256' => $checksum,
                'error' => __('Cloudflare publishing could not be started. Check the connection and try again.', 'ragnus-static-publisher'),
            ]);
            return false;
        }
        Plugin::record_deployment_status($job_id, 'dispatched', [
            'build_sha256' => $checksum,
            'error' => '',
        ]);
        return true;
    }

    public static function signed_artifact_url(string $job_id): string
    {
        $expires = time() + self::ARTIFACT_TTL;
        $signature = hash_hmac('sha256', $job_id . '|' . $expires, self::callback_secret());
        return add_query_arg([
            'expires' => $expires,
            'signature' => $signature,
        ], rest_url('ragnus-static/v1/exports/' . rawurlencode($job_id) . '/managed-artifact'));
    }

    public static function verify_artifact(string $job_id, int $expires, string $signature): bool
    {
        if (! self::configured() || $expires < time() || $expires > time() + self::ARTIFACT_TTL + 60) {
            return false;
        }
        $expected = hash_hmac('sha256', $job_id . '|' . $expires, self::callback_secret());
        return $signature !== '' && hash_equals($expected, strtolower($signature));
    }

    public static function verify_callback(string $timestamp, string $signature, string $body): bool
    {
        if (! self::configured() || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::CALLBACK_TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, self::callback_secret());
        return $signature !== '' && hash_equals($expected, strtolower($signature));
    }

    private static function callback_secret(): string
    {
        return Secret_Store::decrypt((string) (self::connection()['callback_secret'] ?? ''));
    }

    private static function callback_url(): string
    {
        return admin_url('admin-post.php?action=ragnus_static_managed_callback');
    }
}
