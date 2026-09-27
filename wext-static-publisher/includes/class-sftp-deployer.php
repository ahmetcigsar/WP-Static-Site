<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text data. Escape when rendering in HTML.

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class SFTP_Deployer
{
    public const STATUS_KEY = 'wext_static_sftp_status';
    public const LOCK_KEY = 'wext_static_sftp_lock';

    public static function available(): bool
    {
        $available = class_exists(\phpseclib3\Net\SFTP::class);
        return (bool) apply_filters('wext_static_sftp_available', $available);
    }

    public static function configured(?array $settings = null): bool
    {
        $settings = $settings ?? Plugin::settings();
        return trim((string) ($settings['sftp_host'] ?? '')) !== ''
            && trim((string) ($settings['sftp_username'] ?? '')) !== ''
            && trim((string) ($settings['sftp_password'] ?? '')) !== ''
            && trim((string) ($settings['sftp_remote_path'] ?? '')) !== '';
    }

    public static function sanitize_host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^sftp://#i', '', $host) ?? $host;
        $host = preg_replace('#/.*$#', '', $host) ?? $host;
        return preg_replace('/[^a-z0-9.\-:\[\]]/', '', $host) ?? '';
    }

    public static function sanitize_remote_path(string $path): string
    {
        $path = trim(str_replace('\\', '/', sanitize_text_field($path)));
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (in_array('..', $segments, true)) {
            return '';
        }
        return '/' . implode('/', $segments);
    }

    public static function sanitize_fingerprint(string $fingerprint): string
    {
        $fingerprint = strtolower(str_replace(':', '', trim($fingerprint)));
        return preg_match('/^[a-f0-9]{32}$/', $fingerprint) === 1 ? $fingerprint : '';
    }

    public static function status(): array
    {
        $status = get_option(self::STATUS_KEY, []);
        return is_array($status) ? $status : [];
    }

    public static function test_connection(?array $settings = null): array
    {
        $settings = self::validated_settings($settings ?? Plugin::settings());
        self::record('testing', __('SFTP connection is being tested.', 'wext-static-publisher'));

        try {
            $override = apply_filters('wext_static_sftp_test_result', null, self::public_settings($settings));
            if (is_array($override)) {
                if (empty($override['success'])) {
                    throw new RuntimeException((string) ($override['message'] ?? __('SFTP connection failed.', 'wext-static-publisher')));
                }
            } else {
                self::perform_connection_test($settings);
            }

            $message = $settings['sftp_host_fingerprint'] === ''
                ? __('SFTP connection was successful. Add the server MD5 fingerprint to verify the host identity.', 'wext-static-publisher')
                : __('SFTP connection and server fingerprint were verified successfully.', 'wext-static-publisher');
            self::record('connected', $message);
            return ['success' => true, 'message' => $message];
        } catch (Throwable $error) {
            self::record('failed', $error->getMessage());
            throw $error;
        }
    }

    public static function deploy_latest(?array $settings = null): array
    {
        $archive = Archive_Manager::latest();
        if ($archive === null) {
            throw new RuntimeException(__('Create a static site before deploying with SFTP.', 'wext-static-publisher'));
        }
        return self::deploy_job((string) $archive['build_job_id'], $settings);
    }

    public static function deploy_job(string $job_id, ?array $settings = null): array
    {
        $safe_job_id = sanitize_file_name($job_id);
        if ($safe_job_id === '' || $safe_job_id !== $job_id) {
            throw new RuntimeException(__('The static build identifier is invalid.', 'wext-static-publisher'));
        }

        $build_directory = Plugin::storage_directory() . '/builds/' . $safe_job_id;
        if (! is_dir($build_directory) || ! is_readable($build_directory)) {
            throw new RuntimeException(__('The static build directory could not be read.', 'wext-static-publisher'));
        }

        $settings = self::validated_settings($settings ?? Plugin::settings());
        $files = self::files($build_directory);
        if ($files === []) {
            throw new RuntimeException(__('There are no static files to upload with SFTP.', 'wext-static-publisher'));
        }
        if (get_transient(self::LOCK_KEY)) {
            throw new RuntimeException(__('Another SFTP upload is already running.', 'wext-static-publisher'));
        }

        set_transient(self::LOCK_KEY, $job_id, 30 * MINUTE_IN_SECONDS);
        self::record('uploading', __('Static files are being uploaded with SFTP.', 'wext-static-publisher'), $job_id, 0);
        try {
            $override = apply_filters(
                'wext_static_sftp_deploy_result',
                null,
                $build_directory,
                self::public_settings($settings),
                $job_id,
                array_keys($files)
            );
            if (is_array($override)) {
                if (empty($override['success'])) {
                    throw new RuntimeException((string) ($override['message'] ?? __('SFTP upload failed.', 'wext-static-publisher')));
                }
                $uploaded = absint($override['file_count'] ?? count($files));
            } else {
                $uploaded = self::upload_files($settings, $files);
            }

            /* translators: %d is the number of uploaded static files. */
            $message = sprintf(__('%d static files were uploaded successfully with SFTP.', 'wext-static-publisher'), $uploaded);
            self::record('completed', $message, $job_id, $uploaded);
            return ['success' => true, 'message' => $message, 'file_count' => $uploaded];
        } catch (Throwable $error) {
            self::record('failed', $error->getMessage(), $job_id, 0);
            throw $error;
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    public static function record_failure(string $message, string $job_id = ''): void
    {
        self::record('failed', $message, $job_id, 0);
    }

    private static function validated_settings(array $settings): array
    {
        if (! self::available()) {
            throw new RuntimeException(__('No SFTP client is available. Reinstall the complete plugin package.', 'wext-static-publisher'));
        }
        if (! self::configured($settings)) {
            throw new RuntimeException(__('Save the SFTP host, username, password and remote directory first.', 'wext-static-publisher'));
        }

        $settings['sftp_host'] = self::sanitize_host((string) $settings['sftp_host']);
        $settings['sftp_port'] = max(1, min(65535, absint($settings['sftp_port'] ?? 22)));
        $settings['sftp_remote_path'] = self::sanitize_remote_path((string) $settings['sftp_remote_path']);
        $settings['sftp_host_fingerprint'] = self::sanitize_fingerprint((string) ($settings['sftp_host_fingerprint'] ?? ''));
        if ($settings['sftp_host'] === '' || $settings['sftp_remote_path'] === '') {
            throw new RuntimeException(__('The SFTP host or remote directory is invalid.', 'wext-static-publisher'));
        }
        return $settings;
    }

    private static function perform_connection_test(array $settings): void
    {
        $sftp = self::phpseclib_session($settings);
        if (! $sftp->chdir((string) $settings['sftp_remote_path'])) {
            throw new RuntimeException(__('The SFTP remote directory could not be opened.', 'wext-static-publisher'));
        }
    }

    private static function upload_files(array $settings, array $files): int
    {
        return self::upload_files_with_phpseclib($settings, $files);
    }

    private static function upload_files_with_phpseclib(array $settings, array $files): int
    {
        $sftp = self::phpseclib_session($settings);
        $root = (string) $settings['sftp_remote_path'];
        if (! $sftp->is_dir($root) && ! $sftp->mkdir($root, -1, true)) {
            throw new RuntimeException(__('The SFTP remote directory could not be created.', 'wext-static-publisher'));
        }

        $uploaded = 0;
        foreach ($files as $relative_path => $local_path) {
            $remote_path = trailingslashit($root) . ltrim($relative_path, '/');
            $remote_directory = str_replace('\\', '/', dirname($remote_path));
            if (! $sftp->is_dir($remote_directory) && ! $sftp->mkdir($remote_directory, -1, true)) {
                /* translators: %s is the remote directory path. */
                throw new RuntimeException(sprintf(__('The SFTP remote directory could not be created: %s', 'wext-static-publisher'), $remote_directory));
            }
            if (! $sftp->put($remote_path, $local_path, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
                $message = (string) ($sftp->getLastSFTPError() ?: __('Unknown SFTP error.', 'wext-static-publisher'));
                /* translators: %1$s is the file path; %2$s is the SFTP error. */
                throw new RuntimeException(sprintf(__('SFTP upload failed for %1$s: %2$s', 'wext-static-publisher'), $relative_path, $message));
            }
            ++$uploaded;
        }
        return $uploaded;
    }

    private static function phpseclib_session(array $settings): \phpseclib3\Net\SFTP
    {
        try {
            $sftp = new \phpseclib3\Net\SFTP(
                (string) $settings['sftp_host'],
                (int) $settings['sftp_port'],
                min(30, (int) $settings['sftp_timeout'])
            );
            $host_key = $sftp->getServerPublicHostKey();
            if (! is_string($host_key) || $host_key === '') {
                throw new RuntimeException(__('The SFTP server host key could not be read.', 'wext-static-publisher'));
            }

            if ($settings['sftp_host_fingerprint'] !== '') {
                $public_key = \phpseclib3\Crypt\PublicKeyLoader::load($host_key);
                $actual_fingerprint = self::sanitize_fingerprint((string) $public_key->getFingerprint('md5'));
                if ($actual_fingerprint === '' || ! hash_equals((string) $settings['sftp_host_fingerprint'], $actual_fingerprint)) {
                    throw new RuntimeException(__('The SFTP server host fingerprint does not match.', 'wext-static-publisher'));
                }
            }

            if (! $sftp->login((string) $settings['sftp_username'], Secret_Store::decrypt((string) $settings['sftp_password']))) {
                throw new RuntimeException(__('SFTP authentication failed. Check the username and password.', 'wext-static-publisher'));
            }
            return $sftp;
        } catch (RuntimeException $error) {
            throw $error;
        } catch (Throwable $error) {
            /* translators: %s is the SFTP connection error. */
            throw new RuntimeException(sprintf(__('SFTP connection failed: %s', 'wext-static-publisher'), $error->getMessage()), 0, $error);
        }
    }

    private static function files(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }
            $relative_path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
            $files[$relative_path] = $file->getPathname();
        }
        ksort($files);
        return $files;
    }

    private static function public_settings(array $settings): array
    {
        unset($settings['sftp_password'], $settings['deployment_webhook_token']);
        return $settings;
    }

    private static function record(string $state, string $message, string $job_id = '', int $file_count = 0): void
    {
        update_option(self::STATUS_KEY, [
            'state' => sanitize_key($state),
            'message' => sanitize_text_field($message),
            'job_id' => sanitize_file_name($job_id),
            'file_count' => max(0, $file_count),
            'updated_at' => gmdate('c'),
        ], false);
    }
}
