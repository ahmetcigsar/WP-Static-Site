<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/** Publishes a completed export to the site owner's Cloudflare account. */
final class Cloudflare_Deployer
{
    public const CONNECTION_KEY = 'wext_static_cloudflare_connection';
    private const API = 'https://api.cloudflare.com/client/v4';

    public static function connection(): array
    {
        $saved = get_option(self::CONNECTION_KEY, []);
        return is_array($saved) ? $saved : [];
    }

    public static function configured(): bool
    {
        $connection = self::connection();
        return (string) ($connection['account_id'] ?? '') !== ''
            && (string) ($connection['worker_name'] ?? '') !== ''
            && (string) ($connection['api_token'] ?? '') !== '';
    }

    public static function public_connection(): array
    {
        $connection = self::connection();
        return [
            'connected' => self::configured(),
            'account_id' => (string) ($connection['account_id'] ?? ''),
            'worker_name' => (string) ($connection['worker_name'] ?? ''),
            'connected_at' => (string) ($connection['connected_at'] ?? ''),
        ];
    }

    public static function save(string $account_id, string $worker_name, string $api_token): void
    {
        $account_id = trim($account_id);
        $worker_name = strtolower(trim($worker_name));
        $api_token = trim($api_token);
        if (preg_match('/^[a-f0-9]{32}$/i', $account_id) !== 1
            || preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $worker_name) !== 1) {
            throw new RuntimeException(__('Enter a valid Cloudflare Account ID and Worker name.', 'wext-static-publisher'));
        }
        $existing = self::connection();
        if ($api_token === '' && (string) ($existing['api_token'] ?? '') === '') {
            throw new RuntimeException(__('Enter a Cloudflare API token.', 'wext-static-publisher'));
        }
        if ($api_token !== '' && preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $api_token) !== 1) {
            throw new RuntimeException(__('Enter a valid Cloudflare API token.', 'wext-static-publisher'));
        }
        $encrypted = $api_token !== '' ? Secret_Store::encrypt($api_token) : (string) $existing['api_token'];
        $token = Secret_Store::decrypt($encrypted);
        $account_verify_path = '/accounts/' . strtolower($account_id) . '/tokens/verify';
        $verify_path = str_starts_with($token, 'cfat_') ? $account_verify_path : '/user/tokens/verify';
        try {
            $verified = self::request('GET', $verify_path, $token);
        } catch (RuntimeException $error) {
            if ($verify_path === $account_verify_path) {
                throw $error;
            }
            // Older account tokens have no prefix; try the account endpoint too.
            $verified = self::request('GET', $account_verify_path, $token);
        }
        if ((string) ($verified['status'] ?? '') !== 'active') {
            throw new RuntimeException(__('The Cloudflare API token is not active.', 'wext-static-publisher'));
        }
        // A valid token alone does not prove it can access the selected account.
        self::request('GET', '/accounts/' . strtolower($account_id) . '/workers/scripts', $token);
        update_option(self::CONNECTION_KEY, [
            'account_id' => strtolower($account_id),
            'worker_name' => $worker_name,
            'api_token' => $encrypted,
            'connected_at' => gmdate('c'),
        ], false);
    }

    public static function disconnect(): void
    {
        delete_option(self::CONNECTION_KEY);
    }

    public static function deploy_job(string $job_id, array $manifest): bool
    {
        if (! self::configured()) {
            return false;
        }
        $connection = self::connection();
        try {
            Plugin::record_deployment_status($job_id, 'deploying', [
                'build_sha256' => (string) ($manifest['build_sha256'] ?? ''),
                'deployment_url' => '',
                'error' => '',
            ]);
            self::upload($job_id, $connection);
            $target = esc_url_raw((string) ($manifest['target'] ?? (Plugin::settings()['target_url'] ?? '')));
            Plugin::record_deployment_status($job_id, 'completed', [
                'build_sha256' => (string) ($manifest['build_sha256'] ?? ''),
                'deployment_url' => $target,
                'error' => '',
            ]);
            return true;
        } catch (Throwable $error) {
            Plugin::record_deployment_status($job_id, 'failed', [
                'build_sha256' => (string) ($manifest['build_sha256'] ?? ''),
                'deployment_url' => '',
                'error' => $error->getMessage(),
            ]);
            error_log('[Wext Static Publisher] Cloudflare deployment failed: ' . $error->getMessage());
            return false;
        }
    }

    private static function upload(string $job_id, array $connection): void
    {
        $directory = Plugin::storage_directory() . '/builds/' . sanitize_file_name($job_id);
        if (! is_dir($directory) || ! is_readable($directory)) {
            throw new RuntimeException(__('The completed export folder is unavailable.', 'wext-static-publisher'));
        }
        $token = Secret_Store::decrypt((string) $connection['api_token']);
        $base = '/accounts/' . $connection['account_id'];
        $script = $base . '/workers/scripts/' . rawurlencode((string) $connection['worker_name']);
        $files = [];
        $assets = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($directory) + 1));
            if ($relative === '_headers' || $relative === '_redirects') {
                continue;
            }
            $contents = file_get_contents($path);
            if (! is_string($contents)) {
                throw new RuntimeException(__('An export file could not be read.', 'wext-static-publisher'));
            }
            $hash = substr(hash('sha256', base64_encode($contents) . pathinfo($relative, PATHINFO_EXTENSION)), 0, 32);
            $assets['/' . $relative] = ['hash' => $hash, 'size' => strlen($contents)];
            $files[$hash] = ['path' => $path, 'relative' => $relative];
        }
        if ($assets === []) {
            throw new RuntimeException(__('The export contains no files to publish.', 'wext-static-publisher'));
        }
        $session = self::request('POST', $script . '/assets-upload-session', $token, wp_json_encode(['manifest' => $assets]));
        $upload_token = (string) ($session['jwt'] ?? '');
        $buckets = $session['buckets'] ?? null;
        if ($upload_token === '' || ! is_array($buckets)) {
            throw new RuntimeException(__('Cloudflare returned an invalid asset upload session.', 'wext-static-publisher'));
        }
        $completion_token = $buckets === [] ? $upload_token : '';
        foreach ($buckets as $bucket) {
            if (! is_array($bucket)) {
                throw new RuntimeException(__('Cloudflare returned an invalid upload bucket.', 'wext-static-publisher'));
            }
            $parts = [];
            foreach ($bucket as $hash) {
                if (! is_string($hash) || ! isset($files[$hash])) {
                    throw new RuntimeException(__('Cloudflare requested an unknown asset.', 'wext-static-publisher'));
                }
                $contents = file_get_contents($files[$hash]['path']);
                if (! is_string($contents)) {
                    throw new RuntimeException(__('An export file could not be read.', 'wext-static-publisher'));
                }
                $parts[] = ['name' => $hash, 'type' => wp_check_filetype($files[$hash]['relative'])['type'] ?: 'application/octet-stream', 'body' => base64_encode($contents)];
            }
            $response = self::request('POST', $base . '/workers/assets/upload?base64=true', $upload_token, null, $parts);
            if ((string) ($response['jwt'] ?? '') !== '') {
                $completion_token = (string) $response['jwt'];
            }
        }
        if ($completion_token === '') {
            throw new RuntimeException(__('Cloudflare did not confirm the asset upload.', 'wext-static-publisher'));
        }
        $worker = file_get_contents(WEXTSTAT_DIR . 'assets/worker.mjs');
        if (! is_string($worker)) {
            throw new RuntimeException(__('The bundled Cloudflare Worker is missing.', 'wext-static-publisher'));
        }
        $metadata = [
            'main_module' => 'worker.mjs',
            'compatibility_date' => '2026-08-11',
            'bindings' => [['name' => 'ASSETS', 'type' => 'assets']],
            'assets' => [
                'jwt' => $completion_token,
                'config' => ['html_handling' => 'auto-trailing-slash', 'not_found_handling' => '404-page', 'run_worker_first' => ['/']],
            ],
        ];
        foreach (['_headers', '_redirects'] as $filename) {
            $path = $directory . '/' . $filename;
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if (! is_string($contents)) {
                    throw new RuntimeException(__('A Cloudflare configuration file could not be read.', 'wext-static-publisher'));
                }
                $metadata['assets']['config'][$filename] = $contents;
            }
        }
        self::request('PUT', $script, $token, null, [
            ['name' => 'metadata', 'type' => 'application/json', 'body' => (string) wp_json_encode($metadata)],
            ['name' => 'worker.mjs', 'type' => 'application/javascript+module', 'body' => $worker],
        ]);
    }

    private static function request(string $method, string $path, string $token, ?string $json = null, array $parts = []): array
    {
        $headers = ['Authorization' => 'Bearer ' . $token];
        $body = $json;
        if ($parts !== []) {
            $boundary = 'wextstat-' . bin2hex(random_bytes(12));
            $body = '';
            foreach ($parts as $part) {
                $body .= '--' . $boundary . "\r\n"
                    . 'Content-Disposition: form-data; name="' . $part['name'] . '"' . "\r\n"
                    . 'Content-Type: ' . $part['type'] . "\r\n\r\n"
                    . $part['body'] . "\r\n";
            }
            $body .= '--' . $boundary . "--\r\n";
            $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        } elseif ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = wp_remote_request(self::API . $path, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 120,
            'redirection' => 0,
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300 || ! is_array($payload) || empty($payload['success'])) {
            $error = is_array($payload['errors'] ?? null) ? (string) ($payload['errors'][0]['message'] ?? '') : '';
            throw new RuntimeException(sprintf(__('Cloudflare API request failed (HTTP %d): %s', 'wext-static-publisher'), $code, $error ?: __('Unknown error', 'wext-static-publisher')));
        }
        return is_array($payload['result'] ?? null) ? $payload['result'] : [];
    }
}
