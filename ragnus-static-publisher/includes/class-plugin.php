<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

use Throwable;

final class Plugin
{
    public const EXPORT_CAPABILITY = 'ragnus_static_export';
    public const SETTINGS_KEY = 'ragnus_static_settings';
    public const STATUS_KEY = 'ragnus_static_status';
    public const LOCK_KEY = 'ragnus_static_export_lock';
    public const DIRTY_KEY = 'ragnus_static_export_dirty';
    public const CRON_HOOK = 'ragnus_static_run_export';

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_upgrade'], 1);
        add_action('init', [self::class, 'register_cli']);
        add_action('rest_api_init', [REST_Controller::class, 'register']);
        add_filter('rest_pre_serve_request', [REST_Controller::class, 'serve_file'], 10, 2);
        add_action('admin_menu', [Admin::class, 'menu']);
        add_action('admin_init', [Admin::class, 'settings']);
        add_action('admin_post_ragnus_static_export', [Admin::class, 'start_export']);
        add_action('admin_post_ragnus_static_download', [Admin::class, 'download_export']);
        add_action('admin_post_ragnus_static_cleanup_exports', [Admin::class, 'cleanup_exports']);
        add_action('admin_post_ragnus_static_download_archive', [Admin::class, 'download_archive']);
        add_action('admin_post_ragnus_static_archive_bulk', [Admin::class, 'archive_bulk_action']);
        add_action('update_option_' . self::SETTINGS_KEY, [self::class, 'apply_archive_retention'], 10, 2);
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled'], 10, 1);
        add_action('save_post', [self::class, 'maybe_schedule_after_save'], 20, 2);
        add_action('ragnus_static_export_completed', [self::class, 'handle_completed_export'], 10, 3);
    }

    public static function activate(): void
    {
        add_role('ragnus_static_deployer', 'Static Publisher Deploy', [
            'read' => true,
            self::EXPORT_CAPABILITY => true,
        ]);

        $administrator = get_role('administrator');
        if ($administrator !== null) {
            $administrator->add_cap(self::EXPORT_CAPABILITY);
        }

        update_option('ragnus_static_plugin_version', RAGSTAT_VERSION, false);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('ragnus_static_plugin_version') !== RAGSTAT_VERSION) {
            self::activate();
        }
        self::remove_legacy_activity_log();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_transient(self::LOCK_KEY);
    }

    public static function settings(): array
    {
        return wp_parse_args(get_option(self::SETTINGS_KEY, []), [
            'target_url' => home_url(),
            'maximum_urls' => 2000,
            'excluded_paths' => "/wp-admin/\n/wp-login.php\n/wp-json/\n/feed/",
            'auto_export' => '0',
            'archive_retention' => 5,
            'deployment_webhook_url' => '',
            'deployment_webhook_token' => '',
        ]);
    }

    public static function storage_directory(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'ragnus-static';
    }

    public static function schedule_export(string $source = 'manual'): string
    {
        $job_id = gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false);
        Activity_Log::reset($job_id);
        self::set_status($job_id, 'queued', 0, ['source' => $source, 'queued_at' => gmdate('c')]);
        wp_schedule_single_event(time(), self::CRON_HOOK, [$job_id]);
        spawn_cron(time());
        return $job_id;
    }

    public static function run_scheduled(string $job_id): void
    {
        try {
            (new Exporter())->run($job_id);
        } catch (Throwable $error) {
            error_log('[Ragnus Static Publisher] ' . $error->getMessage());
        }
    }

    public static function set_status(string $job_id, string $state, int $progress, array $extra = []): void
    {
        $current = self::status();
        unset($current['log'], $extra['log']);
        if (! isset($current['last_completed_at'])
            && ($current['state'] ?? '') === 'completed'
            && is_string($current['finished_at'] ?? null)) {
            $current['last_completed_at'] = $current['finished_at'];
        }
        update_option(self::STATUS_KEY, array_merge($current, [
            'job_id' => $job_id,
            'state' => $state,
            'progress' => max(0, min(100, $progress)),
        ], $extra), false);
    }

    public static function remove_legacy_activity_log(): void
    {
        $status = get_option(self::STATUS_KEY, []);
        if (! is_array($status) || ! array_key_exists('log', $status)) {
            return;
        }

        unset($status['log']);
        update_option(self::STATUS_KEY, $status, false);
    }

    public static function status(): array
    {
        $status = get_option(self::STATUS_KEY, []);
        return is_array($status) ? $status : [];
    }

    public static function public_status(): array
    {
        $status = self::status();
        unset($status['archive']);
        if (($status['state'] ?? '') === 'completed' && Archive_Manager::latest() !== null) {
            $status['artifact_url'] = rest_url('ragnus-static/v1/exports/latest/artifact');
        }
        return $status;
    }

    public static function maybe_schedule_after_save(int $post_id, \WP_Post $post): void
    {
        if ($post->post_status !== 'publish' || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ((string) self::settings()['auto_export'] !== '1') {
            return;
        }

        if (get_transient(self::LOCK_KEY)) {
            update_option(self::DIRTY_KEY, '1', false);
            return;
        }

        $current = self::status();
        $current_job_id = (string) ($current['job_id'] ?? '');
        $scheduled = $current_job_id !== '' ? wp_next_scheduled(self::CRON_HOOK, [$current_job_id]) : false;
        if ($scheduled !== false) {
            wp_unschedule_event($scheduled, self::CRON_HOOK, [$current_job_id]);
        }

        $job_id = gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false);
        Activity_Log::reset($job_id);
        self::set_status($job_id, 'queued', 0, ['source' => 'content-change', 'queued_at' => gmdate('c')]);
        wp_schedule_single_event(time() + 60, self::CRON_HOOK, [$job_id]);
    }

    public static function handle_completed_export(string $job_id, string $archive, array $manifest): void
    {
        self::apply_archive_retention([], self::settings());

        if (get_option(self::DIRTY_KEY) === '1') {
            delete_option(self::DIRTY_KEY);
            self::schedule_export('content-change-during-export');
            return;
        }

        $source = (string) (self::status()['source'] ?? '');
        if ($source !== 'ci-manual') {
            self::notify_deployment_webhook($job_id, $manifest);
        }
    }

    public static function apply_archive_retention(array $old_value, array $new_value): void
    {
        $keep = max(1, min(100, absint($new_value['archive_retention'] ?? 5)));
        $result = Archive_Manager::prune($keep);
        if ($result['failed'] > 0) {
            error_log('[Ragnus Static Publisher] Bazı eski export arşivleri silinemedi.');
        }
    }

    public static function notify_deployment_webhook(string $job_id, array $manifest): void
    {
        $settings = self::settings();
        $url = (string) $settings['deployment_webhook_url'];
        if ($url === '') {
            return;
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'RagnusStaticPublisher/' . RAGSTAT_VERSION,
            'X-GitHub-Api-Version' => '2026-03-10',
        ];
        $token = (string) $settings['deployment_webhook_token'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => $headers,
            'body' => wp_json_encode([
                'event_type' => 'wordpress_static_export_completed',
                'client_payload' => [
                    'job_id' => $job_id,
                    'build_sha256' => $manifest['build_sha256'] ?? '',
                ],
            ]),
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 300) {
            error_log('[Ragnus Static Publisher] Deployment webhook gönderilemedi.');
        }
    }

    public static function register_cli(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        \WP_CLI::add_command('ragnus-static export', static function ($args, $assoc_args): void {
            $job_id = gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false);
            Activity_Log::reset($job_id);
            self::set_status($job_id, 'running', 0, ['source' => 'wp-cli']);
            $status = (new Exporter())->run($job_id);
            if (($assoc_args['format'] ?? '') === 'json') {
                \WP_CLI::line((string) wp_json_encode(self::public_status(), JSON_UNESCAPED_SLASHES));
            } else {
                \WP_CLI::success(sprintf('Export tamamlandı: %d URL', (int) ($status['url_count'] ?? 0)));
            }
        });
    }
}
