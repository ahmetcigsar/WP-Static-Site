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
    public const LANGUAGE_SETTINGS_KEY = 'ragnus_static_language_settings';
    public const SEO_PLUGIN_SETTINGS_KEY = 'ragnus_static_seo_plugin_settings';
    public const SEO_SETTINGS_KEY = 'ragnus_static_seo_settings';
    public const INDEXNOW_SNAPSHOT_KEY = 'ragnus_static_indexnow_snapshot';
    public const INDEXNOW_STATUS_KEY = 'ragnus_static_indexnow_status';
    public const INDEXNOW_CRON_HOOK = 'ragnus_static_indexnow_notify';
    public const STATUS_KEY = 'ragnus_static_status';
    public const LOCK_KEY = 'ragnus_static_export_lock';
    public const DIRTY_KEY = 'ragnus_static_export_dirty';
    public const CRON_HOOK = 'ragnus_static_run_export';

    public static function boot(): void
    {
        add_action('init', [self::class, 'load_textdomain'], 0);
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
        add_action('admin_post_ragnus_static_sftp_test', [Admin::class, 'test_sftp_connection']);
        add_action('admin_post_ragnus_static_sftp_deploy', [Admin::class, 'deploy_latest_with_sftp']);
        add_action('update_option_' . self::SETTINGS_KEY, [self::class, 'apply_archive_retention'], 10, 2);
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled'], 10, 1);
        add_action(self::INDEXNOW_CRON_HOOK, [self::class, 'notify_indexnow'], 10, 1);
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

    public static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'ragnus-static-publisher',
            false,
            dirname(plugin_basename(RAGSTAT_FILE)) . '/languages'
        );
        $locale = determine_locale();
        $exact_mofile = RAGSTAT_DIR . 'languages/ragnus-static-publisher-' . $locale . '.mo';
        if (is_readable($exact_mofile)) {
            load_textdomain('ragnus-static-publisher', $exact_mofile);
            return;
        }

        $language = strtolower((string) strtok($locale, '_-'));
        $fallbacks = [
            'tr' => 'tr_TR',
            'en' => 'en_US',
            'es' => 'es_ES',
            'fr' => 'fr_FR',
            'zh' => 'zh_CN',
            'ja' => 'ja',
            'ar' => 'ar',
            'pt' => strcasecmp($locale, 'pt_PT') === 0 ? 'pt_PT' : 'pt_BR',
        ];
        $fallback_locale = $fallbacks[$language] ?? 'en_US';
        if ($fallback_locale === $locale) {
            return;
        }

        load_textdomain(
            'ragnus-static-publisher',
            RAGSTAT_DIR . 'languages/ragnus-static-publisher-' . $fallback_locale . '.mo'
        );
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

        if (get_option(self::LANGUAGE_SETTINGS_KEY, null) === null) {
            add_option(self::LANGUAGE_SETTINGS_KEY, Language_Routing::defaults(), '', false);
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
        wp_clear_scheduled_hook(self::INDEXNOW_CRON_HOOK);
        delete_transient(self::LOCK_KEY);
        delete_transient(SFTP_Deployer::LOCK_KEY);
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
            'sftp_auto_deploy' => '0',
            'sftp_host' => '',
            'sftp_port' => 22,
            'sftp_username' => '',
            'sftp_password' => '',
            'sftp_remote_path' => '/public_html',
            'sftp_host_fingerprint' => '',
            'sftp_timeout' => 60,
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

    public static function language_settings(): array
    {
        return wp_parse_args(get_option(self::LANGUAGE_SETTINGS_KEY, []), Language_Routing::defaults());
    }

    public static function seo_plugin_defaults(): array
    {
        return [
            'rank_math_enabled' => '1',
            'rank_math_metadata_pages' => '1',
            'rank_math_metadata_posts' => '1',
            'rank_math_metadata_custom_post_types' => '1',
            'rank_math_metadata_archives' => '1',
            'rank_math_schema' => '1',
            'rank_math_sitemaps' => '1',
            'rank_math_robots' => '1',
            'aioseo_enabled' => '1',
            'aioseo_metadata_pages' => '1',
            'aioseo_metadata_posts' => '1',
            'aioseo_metadata_custom_post_types' => '1',
            'aioseo_metadata_archives' => '1',
            'aioseo_schema' => '1',
            'aioseo_sitemaps' => '1',
            'aioseo_robots' => '1',
            'seopress_enabled' => '1',
            'seopress_metadata_pages' => '1',
            'seopress_metadata_posts' => '1',
            'seopress_metadata_custom_post_types' => '1',
            'seopress_metadata_archives' => '1',
            'seopress_schema' => '1',
            'seopress_sitemaps' => '1',
            'seopress_robots' => '1',
            'surerank_enabled' => '1',
            'surerank_metadata_pages' => '1',
            'surerank_metadata_posts' => '1',
            'surerank_metadata_custom_post_types' => '1',
            'surerank_metadata_archives' => '1',
            'surerank_schema' => '1',
            'surerank_sitemaps' => '1',
            'surerank_robots' => '1',
            'seo_framework_enabled' => '1',
            'seo_framework_metadata_pages' => '1',
            'seo_framework_metadata_posts' => '1',
            'seo_framework_metadata_custom_post_types' => '1',
            'seo_framework_metadata_archives' => '1',
            'seo_framework_schema' => '1',
            'seo_framework_sitemaps' => '1',
            'seo_framework_robots' => '1',
            'yoast_enabled' => '1',
            'yoast_metadata_pages' => '1',
            'yoast_metadata_posts' => '1',
            'yoast_metadata_custom_post_types' => '1',
            'yoast_metadata_archives' => '1',
            'yoast_schema' => '1',
            'yoast_sitemaps' => '1',
            'yoast_robots' => '1',
        ];
    }

    public static function seo_plugin_settings(): array
    {
        $stored = get_option(self::SEO_PLUGIN_SETTINGS_KEY, []);
        $stored = is_array($stored) ? $stored : [];
        foreach (['rank_math', 'aioseo', 'seopress', 'surerank', 'seo_framework', 'yoast'] as $plugin) {
            $legacy = (string) ($stored[$plugin . '_metadata'] ?? '1');
            foreach (['pages', 'posts', 'custom_post_types', 'archives'] as $group) {
                $key = $plugin . '_metadata_' . $group;
                if (! array_key_exists($key, $stored)) {
                    $stored[$key] = $legacy;
                }
            }
            unset($stored[$plugin . '_metadata']);
        }
        return wp_parse_args($stored, self::seo_plugin_defaults());
    }

    public static function seo_defaults(): array
    {
        return [
            'audit_enabled' => '1',
            'audit_html_report' => '1',
            'canonical_fallback' => '1',
            'schema_validation' => '1',
            'multilingual_validation' => '1',
            'image_audit' => '1',
            'advanced_sitemap' => '1',
            'sitemap_lastmod' => '1',
            'sitemap_images' => '1',
            'sitemap_hreflang' => '1',
            'sitemap_video' => '0',
            'sitemap_news' => '0',
            'redirect_old_slugs' => '1',
            'redirect_import_plugins' => '1',
            'redirect_rules' => '',
            'noindex_paths' => '',
            'x_robots_rules' => "*.pdf|noindex",
            'site_noindex' => '0',
            'indexnow_enabled' => '0',
            'indexnow_key' => '',
            'performance_audit' => '1',
            'large_html_kb' => 200,
            'large_asset_kb' => 500,
        ];
    }

    public static function seo_settings(): array
    {
        return wp_parse_args(get_option(self::SEO_SETTINGS_KEY, []), self::seo_defaults());
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
            'status_message' => __('Export job queued.', 'ragnus-static-publisher'),
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
                $status['runtime_notice'] = __('The export job has been in the queue for more than 90 seconds. Check WP-Cron or server loopback requests.', 'ragnus-static-publisher');
            }
        } elseif ($state === 'running') {
            $started_at = strtotime((string) ($status['started_at'] ?? ''));
            if ($started_at !== false && time() - $started_at >= 35 * MINUTE_IN_SECONDS) {
                $status['stalled'] = true;
                $status['runtime_notice'] = __('The export job has been running longer than expected. Check server error logs and PHP uptime limit.', 'ragnus-static-publisher');
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
            'status_message' => __('The automatic export job is queued.', 'ragnus-static-publisher'),
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
        $deployment_succeeded = false;
        if ($source !== 'ci-manual') {
            $deployment_succeeded = self::notify_deployment_webhook($job_id, $manifest);
        }

        $settings = self::settings();
        if ((string) ($settings['sftp_auto_deploy'] ?? '0') === '1') {
            try {
                SFTP_Deployer::deploy_job($job_id, $settings);
                $deployment_succeeded = true;
            } catch (Throwable $error) {
                SFTP_Deployer::record_failure($error->getMessage(), $job_id);
                error_log(sprintf(
                    __('[Ragnus Static Publisher] Automatic SFTP upload failed: %s', 'ragnus-static-publisher'),
                    $error->getMessage()
                ));
            }
        }

        $seo_settings = self::seo_settings();
        if ($deployment_succeeded && (string) ($seo_settings['indexnow_enabled'] ?? '0') === '1') {
            set_transient('ragnus_static_indexnow_pending_' . md5($job_id), $manifest, DAY_IN_SECONDS);
            wp_schedule_single_event(time() + 120, self::INDEXNOW_CRON_HOOK, [$job_id]);
        }
    }

    public static function notify_indexnow(string $job_id): void
    {
        $status = self::status();
        $pending_key = 'ragnus_static_indexnow_pending_' . md5($job_id);
        $pending_manifest = get_transient($pending_key);
        $manifest = is_array($pending_manifest) ? $pending_manifest : (array) ($status['manifest'] ?? []);
        if (! is_array($pending_manifest) && (($status['job_id'] ?? '') !== $job_id || ($status['state'] ?? '') !== 'completed')) {
            return;
        }

        $settings = self::seo_settings();
        $key = (string) ($settings['indexnow_key'] ?? '');
        $pages = (array) ($manifest['seo_toolkit']['page_hashes'] ?? []);
        if ((string) ($settings['indexnow_enabled'] ?? '0') !== '1'
            || preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) !== 1
            || $pages === []) {
            return;
        }

        $previous = get_option(self::INDEXNOW_SNAPSHOT_KEY, []);
        $previous = is_array($previous) ? $previous : [];
        $changed = [];
        foreach ($pages as $url => $hash) {
            if (! isset($previous[$url]) || ! hash_equals((string) $previous[$url], (string) $hash)) {
                $changed[] = (string) $url;
            }
        }
        foreach (array_diff_key($previous, $pages) as $url => $hash) {
            $changed[] = (string) $url;
        }
        $changed = array_slice(array_values(array_unique($changed)), 0, 10000);
        if ($changed === []) {
            update_option(self::INDEXNOW_STATUS_KEY, ['job_id' => $job_id, 'state' => 'unchanged', 'submitted_at' => gmdate('c')], false);
            delete_transient($pending_key);
            return;
        }

        $target = untrailingslashit((string) ($manifest['target'] ?? ''));
        $host = (string) wp_parse_url($target, PHP_URL_HOST);
        if ($host === '') {
            return;
        }
        $response = wp_remote_post('https://api.indexnow.org/indexnow', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode([
                'host' => $host,
                'key' => $key,
                'keyLocation' => $target . '/' . $key . '.txt',
                'urlList' => $changed,
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        update_option(self::INDEXNOW_STATUS_KEY, [
            'job_id' => $job_id,
            'state' => $code >= 200 && $code < 300 ? 'submitted' : 'failed',
            'url_count' => count($changed),
            'http_code' => $code,
            'submitted_at' => gmdate('c'),
        ], false);
        if ($code >= 200 && $code < 300) {
            update_option(self::INDEXNOW_SNAPSHOT_KEY, $pages, false);
            delete_transient($pending_key);
        }
    }

    public static function apply_archive_retention(array $old_value, array $new_value): void
    {
        $keep = max(1, min(100, absint($new_value['archive_retention'] ?? 5)));
        $result = Archive_Manager::prune($keep);
        if ($result['failed'] > 0) {
            error_log(__('[Ragnus Static Publisher] Some old export archives could not be deleted.', 'ragnus-static-publisher'));
        }
    }

    public static function notify_deployment_webhook(string $job_id, array $manifest): bool
    {
        $settings = self::settings();
        $url = (string) $settings['deployment_webhook_url'];
        if ($url === '') {
            return false;
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
            error_log(__('[Ragnus Static Publisher] Deployment webhook could not be sent.', 'ragnus-static-publisher'));
            return false;
        }
        return true;
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
                \WP_CLI::success(sprintf(__('Export completed: %d URL', 'ragnus-static-publisher'), (int) ($status['url_count'] ?? 0)));
            }
        });
    }
}
