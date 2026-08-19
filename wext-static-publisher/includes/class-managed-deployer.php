<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use RuntimeException;
use WP_Error;

final class Managed_Deployer
{
    public const CONNECTION_KEY = 'wext_static_managed_connection';
    public const LICENSE_KEY = 'wext_static_license';
    private const INSTALLATION_KEY = 'wext_static_installation_id';
    private const CALLBACK_DELIVERIES_KEY = 'wext_static_callback_deliveries';
    private const STATE_PREFIX = 'wext_static_connect_';
    private const ARTIFACT_TTL = 900;
    private const CALLBACK_TOLERANCE = 300;

    public static function service_url(): string
    {
        $configured = defined('WEXTSTAT_DEPLOY_SERVICE_URL')
            ? (string) WEXTSTAT_DEPLOY_SERVICE_URL
            : 'https://deploy.wext.io';
        $url = (string) apply_filters('wext_static_deploy_service_url', $configured);
        $url = esc_url_raw(untrailingslashit($url));
        return wp_http_validate_url($url) && str_starts_with($url, 'https://') ? $url : '';
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

    public static function license(): array
    {
        $license = get_option(self::LICENSE_KEY, []);
        return is_array($license) ? wp_parse_args($license, [
            'active' => '0',
            'activation_id' => '',
            'site_id' => '',
            'plan_code' => '',
            'site_limit' => 0,
            'activation_credential' => '',
            'detach_credential' => '',
            'credential_expires_at' => '',
            'activated_at' => '',
        ]) : [];
    }

    public static function public_license(): array
    {
        $license = self::license();
        $expires = strtotime((string) ($license['credential_expires_at'] ?? '')) ?: 0;
        return [
            'available' => self::available(),
            'active' => (string) ($license['active'] ?? '0') === '1',
            'activation_id' => sanitize_text_field((string) ($license['activation_id'] ?? '')),
            'plan_code' => sanitize_key((string) ($license['plan_code'] ?? '')),
            'site_limit' => absint($license['site_limit'] ?? 0),
            'detach_available' => (string) ($license['detach_credential'] ?? '') !== '',
            'credential_available' => $expires > time() && (string) ($license['activation_credential'] ?? '') !== '',
            'credential_expires_at' => sanitize_text_field((string) ($license['credential_expires_at'] ?? '')),
            'activated_at' => sanitize_text_field((string) ($license['activated_at'] ?? '')),
        ];
    }

    public static function configured(): bool
    {
        $connection = self::connection();
        return self::available()
            && (string) (self::license()['active'] ?? '0') === '1'
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

    public static function activate_license(string $license_key): array
    {
        $license_key = trim($license_key);
        if (strlen($license_key) < 16) {
            throw new RuntimeException(__('Enter a valid Wext license key.', 'wext-static-publisher'));
        }

        $payload = self::remote_json('POST', '/v1/wordpress/license-activations', [
            'license_key' => $license_key,
            'installation_id' => self::installation_id(),
            'site_url' => home_url(),
        ], '', [201]);
        $activation_id = sanitize_text_field((string) ($payload['activation_id'] ?? ''));
        $site_id = sanitize_text_field((string) ($payload['site_id'] ?? ''));
        $credential = (string) ($payload['activation_credential'] ?? '');
        $detach_credential = (string) ($payload['detach_credential'] ?? '');
        $expires_at = sanitize_text_field((string) ($payload['expires_at'] ?? ''));
        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : [];
        if ($activation_id === '' || $site_id === '' || strlen($credential) < 32 || strlen($detach_credential) < 32 || strtotime($expires_at) <= time()) {
            throw new RuntimeException(__('The deployment service returned an invalid license response.', 'wext-static-publisher'));
        }

        update_option(self::LICENSE_KEY, [
            'active' => '1',
            'activation_id' => $activation_id,
            'site_id' => $site_id,
            'plan_code' => sanitize_key((string) ($plan['code'] ?? '')),
            'site_limit' => absint($plan['site_limit'] ?? 0),
            'activation_credential' => Secret_Store::encrypt($credential),
            'detach_credential' => Secret_Store::encrypt($detach_credential),
            'credential_expires_at' => $expires_at,
            'activated_at' => gmdate('c'),
        ], false);

        return self::public_license();
    }

    public static function authorization_url(): string
    {
        $service_url = self::service_url();
        if ($service_url === '') {
            throw new RuntimeException(__('Managed deployment service is not configured for this plugin package.', 'wext-static-publisher'));
        }

        $license = self::license();
        $expires = strtotime((string) ($license['credential_expires_at'] ?? '')) ?: 0;
        if ((string) ($license['active'] ?? '0') !== '1'
            || $expires <= time()
            || (string) ($license['activation_credential'] ?? '') === '') {
            throw new RuntimeException(__('Activate the license again before connecting Cloudflare.', 'wext-static-publisher'));
        }

        try {
            $credential = Secret_Store::decrypt((string) $license['activation_credential']);
        } catch (\Throwable $error) {
            throw new RuntimeException(__('The saved license credential is invalid. Activate the license again.', 'wext-static-publisher'));
        }
        $ticket = self::remote_json('POST', '/v1/wordpress/connections/tickets', [
            'site_url' => home_url(),
            'callback_url' => self::callback_url(),
            'locale' => self::service_locale(),
        ], $credential, [200]);
        $connect_ticket = (string) ($ticket['connect_ticket'] ?? '');
        if (strlen($connect_ticket) < 32) {
            throw new RuntimeException(__('The deployment service returned an invalid connection ticket.', 'wext-static-publisher'));
        }

        $state = wp_generate_password(48, false, false);
        set_transient(self::STATE_PREFIX . hash('sha256', $state), '1', 15 * MINUTE_IN_SECONDS);
        return add_query_arg([
            'site_url' => home_url(),
            'callback_url' => self::callback_url(),
            'state' => $state,
            'locale' => self::service_locale(),
            'connect_ticket' => $connect_ticket,
        ], $service_url . '/connect/cloudflare');
    }

    public static function complete_connection(string $state, string $code): array
    {
        if ((string) (self::license()['active'] ?? '0') !== '1') {
            throw new RuntimeException(__('An active Wext license is required to use Cloudflare deployment.', 'wext-static-publisher'));
        }
        $state_key = self::STATE_PREFIX . hash('sha256', $state);
        if ($state === '' || $code === '' || get_transient($state_key) !== '1') {
            throw new RuntimeException(__('The Cloudflare connection request has expired. Start the connection again.', 'wext-static-publisher'));
        }
        delete_transient($state_key);

        $payload = self::remote_json('POST', '/v1/wordpress/connections/exchange', [
            'code' => $code,
            'site_url' => home_url(),
            'callback_url' => self::callback_url(),
        ], '', [200]);
        $access_token = is_array($payload) ? (string) ($payload['access_token'] ?? '') : '';
        $callback_secret = is_array($payload) ? (string) ($payload['callback_secret'] ?? '') : '';
        if ($access_token === '' || strlen($callback_secret) < 32) {
            throw new RuntimeException(__('The deployment service returned an invalid connection response.', 'wext-static-publisher'));
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
        $license = self::license();
        if ($license !== []) {
            $license['activation_credential'] = '';
            $license['credential_expires_at'] = '';
            update_option(self::LICENSE_KEY, $license, false);
        }

        $settings = Plugin::settings();
        $settings['deployment_mode'] = 'managed';
        if ((string) $connection['deployment_url'] !== '') {
            $settings['target_url'] = (string) $connection['deployment_url'];
        }
        update_option(Plugin::SETTINGS_KEY, $settings, false);
        return self::public_connection();
    }

    public static function disconnect(): string
    {
        $connection = self::connection();
        if (self::available() && (string) ($connection['access_token'] ?? '') !== '') {
            try {
                $token = Secret_Store::decrypt((string) $connection['access_token']);
                $payload = self::remote_json('DELETE', '/v1/wordpress/connections/current', null, $token, [200]);
                $provider_revoke = sanitize_key((string) ($payload['provider_revoke'] ?? 'pending'));
            } catch (\Throwable $error) {
                error_log('[Wext Static Publisher] Managed Cloudflare disconnect could not be confirmed.');
                $provider_revoke = 'pending';
            }
        }
        delete_option(self::CONNECTION_KEY);
        $settings = Plugin::settings();
        $settings['deployment_mode'] = 'advanced';
        update_option(Plugin::SETTINGS_KEY, $settings, false);
        return $provider_revoke ?? 'already_revoked';
    }

    public static function deactivate_license(): void
    {
        $license = self::license();
        $connection = self::connection();
        $token = '';
        if ((string) ($license['detach_credential'] ?? '') !== '') {
            $token = Secret_Store::decrypt((string) $license['detach_credential']);
        } elseif ((string) ($connection['access_token'] ?? '') !== '') {
            $token = Secret_Store::decrypt((string) $connection['access_token']);
        }
        if ($token === '') {
            throw new RuntimeException(__('Activate the license again before detaching it from this site.', 'wext-static-publisher'));
        }
        self::remote_json('DELETE', '/v1/wordpress/license-activations/current', null, $token, [200]);
        delete_option(self::CONNECTION_KEY);
        delete_option(self::LICENSE_KEY);
        $settings = Plugin::settings();
        $settings['deployment_mode'] = 'advanced';
        update_option(Plugin::SETTINGS_KEY, $settings, false);
    }

    public static function notify_export(string $job_id, array $manifest): bool
    {
        if (! self::configured()) {
            return false;
        }

        $checksum = strtolower((string) ($manifest['build_sha256'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            Plugin::record_deployment_status($job_id, 'failed', [
                'error' => __('The site package could not be verified before publishing.', 'wext-static-publisher'),
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
        try {
            $payload = self::remote_json('POST', '/v1/deployments', [
                'job_id' => $job_id,
                'build_sha256' => $checksum,
                'artifact_url' => $artifact_url,
                'callback_url' => rest_url('wext-static/v1/managed-deployments/callback'),
                'target_url' => (string) (Plugin::settings()['target_url'] ?? ''),
            ], $token, [202]);
        } catch (\Throwable $error) {
            Plugin::record_deployment_status($job_id, 'failed', [
                'build_sha256' => $checksum,
                'error' => $error->getMessage(),
            ]);
            return false;
        }
        $deployment_id = sanitize_text_field((string) ($payload['deployment_id'] ?? ''));
        $response_job_id = (string) ($payload['job_id'] ?? '');
        $service_status = sanitize_key((string) ($payload['status'] ?? ''));
        $created_at = sanitize_text_field((string) ($payload['created_at'] ?? ''));
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $deployment_id) !== 1
            || ! hash_equals($job_id, $response_job_id)
            || $service_status === ''
            || strtotime($created_at) === false) {
            Plugin::record_deployment_status($job_id, 'failed', [
                'build_sha256' => $checksum,
                'error' => __('The deployment service returned an invalid job response.', 'wext-static-publisher'),
            ]);
            return false;
        }
        Plugin::record_deployment_status($job_id, 'dispatched', [
            'build_sha256' => $checksum,
            'service_deployment_id' => $deployment_id,
            'service_state' => $service_status,
            'service_created_at' => $created_at,
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
        ], rest_url('wext-static/v1/exports/' . rawurlencode($job_id) . '/managed-artifact'));
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

    public static function claim_callback_delivery(string $delivery_id): bool
    {
        if ($delivery_id === '') {
            return true;
        }
        if (strlen($delivery_id) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $delivery_id) !== 1) {
            return false;
        }
        $now = time();
        $deliveries = get_option(self::CALLBACK_DELIVERIES_KEY, []);
        $deliveries = is_array($deliveries) ? $deliveries : [];
        $deliveries = array_filter($deliveries, static fn ($seen_at): bool => absint($seen_at) >= $now - self::CALLBACK_TOLERANCE);
        $fingerprint = hash('sha256', $delivery_id);
        if (isset($deliveries[$fingerprint])) {
            return false;
        }
        $deliveries[$fingerprint] = $now;
        update_option(self::CALLBACK_DELIVERIES_KEY, $deliveries, false);
        return true;
    }

    private static function callback_secret(): string
    {
        return Secret_Store::decrypt((string) (self::connection()['callback_secret'] ?? ''));
    }

    private static function callback_url(): string
    {
        return admin_url('admin-post.php?action=wext_static_managed_callback');
    }

    private static function installation_id(): string
    {
        $encrypted = (string) get_option(self::INSTALLATION_KEY, '');
        if ($encrypted !== '') {
            try {
                $stored = Secret_Store::decrypt($encrypted);
                if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $stored) === 1) {
                    return $stored;
                }
            } catch (\Throwable $error) {
                // Replace an unreadable local identifier with a new protected identifier.
            }
        }
        $installation_id = 'wext_' . bin2hex(random_bytes(24));
        update_option(self::INSTALLATION_KEY, Secret_Store::encrypt($installation_id), false);
        return $installation_id;
    }

    private static function service_locale(): string
    {
        $locale = determine_locale();
        if (preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $locale) === 1) {
            return $locale;
        }
        return 'en_US';
    }

    private static function remote_json(string $method, string $path, ?array $body, string $token, array $success_codes): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'WextStaticPublisher/' . WEXTSTAT_VERSION,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $args = [
            'method' => $method,
            'timeout' => 20,
            'redirection' => 0,
            'headers' => $headers,
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request(self::service_url() . $path, $args);
        if ($response instanceof WP_Error) {
            throw new RuntimeException(__('The Wext deployment service could not be reached. Try again.', 'wext-static-publisher'));
        }
        $status = wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        $payload = is_array($payload) ? $payload : [];
        if (! in_array($status, $success_codes, true)) {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $code = sanitize_key((string) ($error['code'] ?? 'service_error'));
            $request_id = sanitize_text_field((string) ($error['request_id'] ?? ''));
            if (in_array($code, ['license_inactive', 'activation_inactive'], true)) {
                self::mark_license_inactive();
            }
            error_log(sprintf('[Wext Static Publisher] Deployment service error: %s%s', $code, $request_id === '' ? '' : ' (' . $request_id . ')'));
            throw new RuntimeException(self::safe_error_message($code));
        }
        return $payload;
    }

    private static function mark_license_inactive(): void
    {
        $license = self::license();
        if ($license === []) {
            return;
        }
        $license['active'] = '0';
        $license['activation_credential'] = '';
        $license['credential_expires_at'] = '';
        update_option(self::LICENSE_KEY, $license, false);
    }

    private static function safe_error_message(string $code): string
    {
        $messages = [
            'license_invalid' => __('The Wext license key is invalid.', 'wext-static-publisher'),
            'license_inactive' => __('The Wext license is inactive or expired.', 'wext-static-publisher'),
            'license_domain_not_allowed' => __('This site address is not allowed by the Wext license.', 'wext-static-publisher'),
            'license_site_limit_reached' => __('The Wext license site limit has been reached.', 'wext-static-publisher'),
            'installation_site_conflict' => __('This installation is already bound to another site.', 'wext-static-publisher'),
            'invalid_site_url' => __('The WordPress site address must be a public HTTPS origin without a path.', 'wext-static-publisher'),
            'invalid_callback_url' => __('The WordPress callback address was rejected by the deployment service.', 'wext-static-publisher'),
            'activation_inactive' => __('The site license activation is no longer active.', 'wext-static-publisher'),
            'connect_ticket_invalid' => __('The Cloudflare connection request expired. Activate the license and try again.', 'wext-static-publisher'),
            'service_token_required' => __('The saved Wext service credential is missing.', 'wext-static-publisher'),
            'deployment_target_not_allowed' => __('The connected Cloudflare target is no longer authorized for this site.', 'wext-static-publisher'),
            'idempotency_conflict' => __('This deployment job is already bound to different export data.', 'wext-static-publisher'),
            'route_not_found' => __('This operation is not available on the Wext deployment service yet.', 'wext-static-publisher'),
        ];
        return $messages[$code] ?? __('The Wext deployment service could not complete the operation. Try again.', 'wext-static-publisher');
    }
}
