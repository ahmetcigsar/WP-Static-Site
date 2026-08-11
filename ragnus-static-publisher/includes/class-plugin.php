<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

use Throwable;

final class Plugin
{
    public const EXPORT_CAPABILITY = 'ragnus_static_export';
    public const SETTINGS_KEY = 'ragnus_static_settings';
    public const HIDE_SETTINGS_KEY = 'ragnus_static_hide_settings';
    public const SEARCH_SETTINGS_KEY = 'ragnus_static_search_settings';
    public const STATUS_KEY = 'ragnus_static_status';
    public const LOCK_KEY = 'ragnus_static_export_lock';
    public const DIRTY_KEY = 'ragnus_static_export_dirty';
    public const CRON_HOOK = 'ragnus_static_run_export';

    public static function boot(): void
    {
        add_action('init', [self::class, 'apply_export_privacy'], 0);
        add_action('init', [self::class, 'maybe_upgrade'], 1);
        add_action('init', [self::class, 'register_cli']);
        add_action('rest_api_init', [REST_Controller::class, 'register']);
        add_filter('rest_pre_serve_request', [REST_Controller::class, 'serve_file'], 10, 2);
        add_action('admin_menu', [Admin::class, 'menu']);
        add_action('admin_enqueue_scripts', [Admin::class, 'enqueue_assets']);
        add_action('admin_init', [Admin::class, 'settings']);
        add_action('admin_post_ragnus_static_export', [Admin::class, 'start_export']);
        add_action('admin_post_ragnus_static_refresh_diagnostics', [Admin::class, 'refresh_diagnostics']);
        add_action('wp_ajax_ragnus_static_run_pending', [Admin::class, 'run_pending_export']);
        add_action('admin_post_ragnus_static_download', [Admin::class, 'download_export']);
        add_action('admin_post_ragnus_static_cleanup_exports', [Admin::class, 'cleanup_exports']);
        add_action('admin_post_ragnus_static_download_archive', [Admin::class, 'download_archive']);
        add_action('admin_post_ragnus_static_archive_bulk', [Admin::class, 'archive_bulk_action']);
        add_action('update_option_' . self::SETTINGS_KEY, [self::class, 'apply_archive_retention'], 10, 2);
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled'], 10, 1);
        add_action('transition_post_status', [self::class, 'maybe_schedule_after_post_transition'], 20, 3);
        add_action('created_term', [self::class, 'maybe_schedule_after_taxonomy_change'], 20, 3);
        add_action('edited_term', [self::class, 'maybe_schedule_after_taxonomy_change'], 20, 3);
        add_action('delete_term', [self::class, 'maybe_schedule_after_taxonomy_change'], 20, 3);
        add_action('add_attachment', [self::class, 'maybe_schedule_after_media_change']);
        add_action('edit_attachment', [self::class, 'maybe_schedule_after_media_change']);
        add_action('delete_attachment', [self::class, 'maybe_schedule_after_media_change']);
        add_action('wp_update_nav_menu', [self::class, 'maybe_schedule_after_menu_change']);
        add_action('customize_save_after', [self::class, 'maybe_schedule_after_theme_change']);
        add_action('switch_theme', [self::class, 'maybe_schedule_after_theme_change']);
        add_action('upgrader_process_complete', [self::class, 'maybe_schedule_after_upgrade'], 20, 2);
        add_action('updated_option', [self::class, 'maybe_schedule_after_option_change'], 20, 3);
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
        Diagnostics::refresh();
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
        return wp_parse_args(get_option(self::SETTINGS_KEY, []), array_merge([
            'target_url' => home_url(),
            'maximum_urls' => 2000,
            'excluded_paths' => "/wp-admin/\n/wp-login.php\n/wp-json/\n/feed/",
            'auto_export' => '0',
            'archive_retention' => 5,
            'deployment_webhook_url' => '',
            'deployment_webhook_token' => '',
        ], self::auto_export_trigger_defaults()));
    }

    public static function auto_export_trigger_defaults(): array
    {
        return [
            'auto_export_post_created' => '1',
            'auto_export_post_updated' => '1',
            'auto_export_page_created' => '1',
            'auto_export_page_updated' => '1',
            'auto_export_custom_content' => '1',
            'auto_export_taxonomy' => '0',
            'auto_export_media' => '0',
            'auto_export_menu' => '1',
            'auto_export_widgets' => '1',
            'auto_export_theme' => '1',
            'auto_export_site_settings' => '1',
        ];
    }

    public static function hide_defaults(): array
    {
        return [
            'wp_content_directory' => 'wp-content',
            'wp_includes_directory' => 'wp-includes',
            'uploads_directory' => 'uploads',
            'plugins_directory' => 'plugins',
            'themes_directory' => 'themes',
            'theme_style_name' => 'style',
            'author_url' => 'author',
            'hide_wordpress_version' => '0',
            'hide_generator_meta' => '0',
            'hide_wordpress_dns_prefetch' => '0',
            'hide_rsd_header' => '0',
            'disable_xml_rpc' => '0',
            'disable_embed_scripts' => '0',
            'disable_db_debug' => '0',
            'disable_wlw_manifest' => '0',
            'disable_emojis' => '0',
        ];
    }

    public static function hide_settings(): array
    {
        return wp_parse_args(get_option(self::HIDE_SETTINGS_KEY, []), self::hide_defaults());
    }

    public static function search_defaults(): array
    {
        return [
            'enabled' => '1',
            'page_path' => 'arama',
            'result_limit' => 20,
            'min_chars' => 2,
            'content_limit' => 5000,
            'threshold' => '0.35',
            'token_match' => 'all',
            'title_selector' => 'title',
            'content_selector' => 'body',
            'excerpt_selector' => '.entry-content',
            'exclude_urls' => "author\narchive\ncategory",
            'index_title' => '1',
            'index_excerpt' => '1',
            'index_content' => '1',
            'index_taxonomies' => '1',
            'title_weight' => '5',
            'excerpt_weight' => '2',
            'content_weight' => '1',
            'taxonomy_weight' => '3',
        ];
    }

    public static function search_settings(): array
    {
        return wp_parse_args(get_option(self::SEARCH_SETTINGS_KEY, []), self::search_defaults());
    }

    public static function apply_export_privacy(): void
    {
        if (($_SERVER['HTTP_X_RAGNUS_STATIC_EXPORT'] ?? '') !== '1') {
            return;
        }

        $settings = self::hide_settings();
        if (($settings['disable_db_debug'] ?? '0') !== '1') {
            return;
        }

        @ini_set('display_errors', '0');
        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'hide_errors')) {
            $wpdb->hide_errors();
        }
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
        self::set_status($job_id, 'queued', 0, [
            'source' => $source,
            'queued_at' => gmdate('c'),
            'phase' => 'queued',
            'status_message' => 'Export işi sıraya alındı.',
            'current_url' => '',
            'url_count' => 0,
            'error' => '',
        ]);
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
        $last_completed_at = strtotime((string) ($status['last_completed_at'] ?? ''));
        $status['last_completed_display'] = $last_completed_at === false
            ? '—'
            : wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $last_completed_at);

        $state = (string) ($status['state'] ?? '');
        $job_id = (string) ($status['job_id'] ?? '');
        $status['stalled'] = false;
        $status['runtime_notice'] = '';

        if ($state === 'queued' && $job_id !== '') {
            $queued_at = strtotime((string) ($status['queued_at'] ?? ''));
            $status['cron_scheduled'] = wp_next_scheduled(self::CRON_HOOK, [$job_id]) !== false;
            if ($queued_at !== false && time() - $queued_at >= 90) {
                $status['stalled'] = true;
                $status['runtime_notice'] = 'Export işi 90 saniyeden uzun süredir kuyrukta. WP-Cron veya sunucu loopback isteklerini kontrol edin.';
            }
        } elseif ($state === 'running') {
            $started_at = strtotime((string) ($status['started_at'] ?? ''));
            if ($started_at !== false && time() - $started_at >= 35 * MINUTE_IN_SECONDS) {
                $status['stalled'] = true;
                $status['runtime_notice'] = 'Export işi beklenenden uzun süredir çalışıyor. Sunucu hata kayıtlarını ve PHP çalışma süresi sınırını kontrol edin.';
            }
        }

        return $status;
    }

    public static function maybe_schedule_after_post_transition(string $new_status, string $old_status, \WP_Post $post): void
    {
        if (($new_status !== 'publish' && $old_status !== 'publish') || $post->post_type === 'revision') {
            return;
        }

        $created = $old_status !== 'publish';
        if ($post->post_type === 'post') {
            $trigger = $created ? 'auto_export_post_created' : 'auto_export_post_updated';
            $source = $created ? 'post-created' : 'post-updated';
        } elseif ($post->post_type === 'page') {
            $trigger = $created ? 'auto_export_page_created' : 'auto_export_page_updated';
            $source = $created ? 'page-created' : 'page-updated';
        } else {
            $trigger = 'auto_export_custom_content';
            $source = 'custom-content-changed';
        }
        self::maybe_schedule_automatic_export($trigger, $source);
    }

    public static function maybe_schedule_after_taxonomy_change(): void
    {
        self::maybe_schedule_automatic_export('auto_export_taxonomy', 'taxonomy-changed');
    }

    public static function maybe_schedule_after_media_change(): void
    {
        self::maybe_schedule_automatic_export('auto_export_media', 'media-changed');
    }

    public static function maybe_schedule_after_menu_change(): void
    {
        self::maybe_schedule_automatic_export('auto_export_menu', 'menu-changed');
    }

    public static function maybe_schedule_after_theme_change(): void
    {
        self::maybe_schedule_automatic_export('auto_export_theme', 'theme-changed');
    }

    public static function maybe_schedule_after_upgrade($upgrader, array $hook_extra): void
    {
        if (($hook_extra['type'] ?? '') === 'theme') {
            self::maybe_schedule_automatic_export('auto_export_theme', 'theme-updated');
        }
    }

    public static function maybe_schedule_after_option_change(string $option, $old_value, $value): void
    {
        if ($old_value === $value) {
            return;
        }

        if ($option === 'sidebars_widgets' || str_starts_with($option, 'widget_')) {
            self::maybe_schedule_automatic_export('auto_export_widgets', 'widgets-changed');
            return;
        }
        if (str_starts_with($option, 'theme_mods_')) {
            self::maybe_schedule_automatic_export('auto_export_theme', 'theme-settings-changed');
            return;
        }
        $site_options = [
            'blogname',
            'blogdescription',
            'show_on_front',
            'page_on_front',
            'page_for_posts',
            'posts_per_page',
            'permalink_structure',
            'date_format',
            'time_format',
            'timezone_string',
        ];
        if (in_array($option, $site_options, true)) {
            self::maybe_schedule_automatic_export('auto_export_site_settings', 'site-settings-changed');
        }
    }

    public static function maybe_schedule_automatic_export(string $trigger, string $source): void
    {
        $settings = self::settings();
        if ((string) $settings['auto_export'] !== '1' || (string) ($settings[$trigger] ?? '0') !== '1') {
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
        self::set_status($job_id, 'queued', 0, [
            'source' => $source,
            'queued_at' => gmdate('c'),
            'status_message' => 'Otomatik export işi sıraya alındı.',
        ]);
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
