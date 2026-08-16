<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

final class Admin
{
    public static function menu(): void
    {
        add_menu_page(
            'Ragnus Static Publisher',
            'Static Publisher',
            'manage_options',
            'ragnus-static-publisher',
            [self::class, 'render'],
            'dashicons-media-document',
            58
        );
    }

    public static function settings(): void
    {
        register_setting('ragnus_static', Plugin::SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => [],
        ]);
        register_setting('ragnus_static_hide', Plugin::HIDE_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_hide_settings'],
            'default' => Plugin::hide_defaults(),
        ]);
        register_setting('ragnus_static_search', Plugin::SEARCH_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_search_settings'],
            'default' => Plugin::search_defaults(),
        ]);
        register_setting('ragnus_static_languages', Plugin::LANGUAGE_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [Language_Routing::class, 'sanitize'],
            'default' => Language_Routing::defaults(),
        ]);
        register_setting('ragnus_static_seo_plugins', Plugin::SEO_PLUGIN_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_seo_plugin_settings'],
            'default' => Plugin::seo_plugin_defaults(),
        ]);
        register_setting('ragnus_static_seo', Plugin::SEO_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_seo_settings'],
            'default' => Plugin::seo_defaults(),
        ]);
    }

    public static function enqueue_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'toplevel_page_ragnus-static-publisher') {
            return;
        }

        wp_enqueue_style(
            'ragnus-static-publisher-admin',
            plugins_url('assets/admin.css', RAGSTAT_FILE),
            [],
            RAGSTAT_VERSION
        );

        wp_enqueue_script(
            'ragnus-static-publisher-admin',
            plugins_url('assets/admin.js', RAGSTAT_FILE),
            [],
            RAGSTAT_VERSION,
            true
        );
        $cloudflare_configured = Plugin::cloudflare_deployment_configured();
        wp_localize_script('ragnus-static-publisher-admin', 'RagnusStaticPublisherAdmin', [
            'statusUrl' => rest_url('ragnus-static/v1/exports/latest'),
            'nonce' => wp_create_nonce('wp_rest'),
            'runnerUrl' => admin_url('admin-ajax.php'),
            'runnerNonce' => wp_create_nonce('ragnus_static_run_pending'),
            'pollInterval' => 2000,
            'i18n' => [
                'queued' => __('Queued', 'ragnus-static-publisher'),
                'running' => __('Running', 'ragnus-static-publisher'),
                'completed' => __('Completed', 'ragnus-static-publisher'),
                'failed' => __('Failed', 'ragnus-static-publisher'),
                'notRun' => __('Not run yet', 'ragnus-static-publisher'),
                'progressLabel' => __('Static rendering progress', 'ragnus-static-publisher'),
                'retry' => __('Retry', 'ragnus-static-publisher'),
                'create' => __('Create Static Site', 'ragnus-static-publisher'),
                'deployCloudflare' => __('Deploy to Cloudflare', 'ragnus-static-publisher'),
                'waiting' => __('Waiting', 'ragnus-static-publisher'),
                'notDeployed' => __('Not deployed yet', 'ragnus-static-publisher'),
                'pollError' => __('Progress information is unavailable. Check your internet connection or WordPress REST API access.', 'ragnus-static-publisher'),
            ],
            'cloudflareConfigured' => $cloudflare_configured,
        ]);
    }

    public static function sanitize(array $value): array
    {
        $current = Plugin::settings();
        $section = sanitize_key((string) ($value['_section'] ?? 'all'));
        $sanitized = $current;

        if (in_array($section, ['general', 'all'], true)) {
            $sanitized['target_url'] = esc_url_raw(untrailingslashit((string) ($value['target_url'] ?? home_url())));
            $sanitized['maximum_urls'] = max(10, min(20000, absint($value['maximum_urls'] ?? 2000)));
            $sanitized['excluded_paths'] = sanitize_textarea_field((string) ($value['excluded_paths'] ?? ''));
        }

        if (in_array($section, ['headless', 'all'], true)) {
            $behavior = sanitize_key((string) ($value['headless_frontend_behavior'] ?? '404'));
            $behavior = in_array($behavior, ['404', '410', 'redirect'], true) ? $behavior : '404';
            $frontend_url = esc_url_raw(untrailingslashit((string) ($value['headless_frontend_url'] ?? '')));
            if ($frontend_url !== '' && ! self::valid_frontend_url($frontend_url)) {
                add_settings_error(
                    Plugin::SETTINGS_KEY,
                    'headless-frontend-url-error',
                    __('Enter an HTTP(S) frontend address on a different origin than WordPress.', 'ragnus-static-publisher'),
                    'error'
                );
                $frontend_url = (string) ($current['headless_frontend_url'] ?? '');
            }
            if ($behavior === 'redirect' && $frontend_url === '') {
                add_settings_error(
                    Plugin::SETTINGS_KEY,
                    'headless-redirect-url-error',
                    __('A frontend address is required for redirect behavior. The 404 behavior was selected instead.', 'ragnus-static-publisher'),
                    'error'
                );
                $behavior = '404';
            }

            $sanitized['headless_enabled'] = isset($value['headless_enabled']) ? '1' : '0';
            $sanitized['headless_frontend_behavior'] = $behavior;
            $sanitized['headless_frontend_url'] = $frontend_url;
            foreach (['headless_preserve_path', 'headless_allow_authenticated_preview', 'headless_allow_graphql', 'headless_noindex', 'headless_disable_xmlrpc', 'headless_disable_comments'] as $key) {
                $sanitized[$key] = isset($value[$key]) ? '1' : '0';
            }
        }

        if (in_array($section, ['zip', 'all'], true)) {
            $sanitized['archive_retention'] = max(1, min(100, absint($value['archive_retention'] ?? ($current['archive_retention'] ?? 5))));
        }

        if (in_array($section, ['automation', 'all'], true)) {
            $sanitized['auto_export'] = isset($value['auto_export']) ? '1' : '0';
            foreach (array_keys(Plugin::auto_export_trigger_defaults()) as $trigger) {
                $sanitized[$trigger] = isset($value[$trigger]) ? '1' : '0';
            }
        }

        $deploy_submitted = $section === 'deploy'
            || ($section === 'all' && (array_key_exists('deployment_webhook_url', $value) || array_key_exists('deployment_webhook_token', $value)));
        if ($deploy_submitted) {
            $submitted_token = sanitize_text_field((string) ($value['deployment_webhook_token'] ?? ''));
            $sanitized['deployment_webhook_url'] = esc_url_raw((string) ($value['deployment_webhook_url'] ?? ''));
            $sanitized['deployment_webhook_token'] = $submitted_token !== '' ? $submitted_token : (string) $current['deployment_webhook_token'];
            $sanitized['deployment_mode'] = 'advanced';
        }

        if (in_array($section, ['sftp', 'all'], true)) {
            $sanitized['sftp_auto_deploy'] = isset($value['sftp_auto_deploy']) ? '1' : '0';
            $sanitized['sftp_host'] = SFTP_Deployer::sanitize_host((string) ($value['sftp_host'] ?? ''));
            $sanitized['sftp_port'] = max(1, min(65535, absint($value['sftp_port'] ?? 22)));
            $sanitized['sftp_username'] = sanitize_text_field((string) ($value['sftp_username'] ?? ''));
            $submitted_remote_path = (string) ($value['sftp_remote_path'] ?? '/public_html');
            $remote_path = SFTP_Deployer::sanitize_remote_path($submitted_remote_path);
            if ($remote_path === '') {
                add_settings_error(Plugin::SETTINGS_KEY, 'sftp-path-error', __('The SFTP remote directory cannot contain .. path segments.', 'ragnus-static-publisher'), 'error');
                $remote_path = (string) $current['sftp_remote_path'];
            }
            $sanitized['sftp_remote_path'] = $remote_path;

            $submitted_fingerprint = trim((string) ($value['sftp_host_fingerprint'] ?? ''));
            $fingerprint = SFTP_Deployer::sanitize_fingerprint($submitted_fingerprint);
            if ($submitted_fingerprint !== '' && $fingerprint === '') {
                add_settings_error(Plugin::SETTINGS_KEY, 'sftp-fingerprint-error', __('Enter the SFTP server fingerprint as 32 hexadecimal MD5 characters.', 'ragnus-static-publisher'), 'error');
                $fingerprint = (string) $current['sftp_host_fingerprint'];
            }
            $sanitized['sftp_host_fingerprint'] = $fingerprint;
            $sanitized['sftp_timeout'] = max(10, min(600, absint($value['sftp_timeout'] ?? 60)));

            if (isset($value['sftp_clear_password'])) {
                $sanitized['sftp_password'] = '';
            } else {
                $submitted_password = (string) ($value['sftp_password'] ?? '');
                if ($submitted_password !== '') {
                    try {
                        $sanitized['sftp_password'] = Secret_Store::encrypt($submitted_password);
                    } catch (\Throwable $error) {
                        add_settings_error(Plugin::SETTINGS_KEY, 'sftp-secret-error', $error->getMessage(), 'error');
                        $sanitized['sftp_password'] = (string) $current['sftp_password'];
                    }
                }
            }
            if ($sanitized['sftp_auto_deploy'] === '1' && ! SFTP_Deployer::configured($sanitized)) {
                add_settings_error(Plugin::SETTINGS_KEY, 'sftp-auto-error', __('Complete the SFTP connection information before enabling automatic upload.', 'ragnus-static-publisher'), 'error');
                $sanitized['sftp_auto_deploy'] = '0';
            }
        }

        return $sanitized;
    }

    private static function valid_frontend_url(string $url): bool
    {
        $parts = wp_parse_url($url);
        $home_parts = wp_parse_url(home_url('/'));
        if (! is_array($parts)
            || ! is_array($home_parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === '') {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $home_scheme = strtolower((string) ($home_parts['scheme'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $home_port = (int) ($home_parts['port'] ?? ($home_scheme === 'https' ? 443 : 80));
        return $scheme !== $home_scheme
            || strtolower((string) $parts['host']) !== strtolower((string) ($home_parts['host'] ?? ''))
            || $port !== $home_port;
    }

    public static function sanitize_seo_plugin_settings(array $value): array
    {
        $sanitized = [];
        foreach (array_keys(Plugin::seo_plugin_defaults()) as $key) {
            $sanitized[$key] = isset($value[$key]) ? '1' : '0';
        }
        return $sanitized;
    }

    public static function sanitize_seo_settings(array $value): array
    {
        $defaults = Plugin::seo_defaults();
        $current = Plugin::seo_settings();
        $section = sanitize_key((string) ($value['_section'] ?? 'all'));
        $sanitized = array_intersect_key($current, $defaults);

        if (in_array($section, ['audit', 'all'], true)) {
            foreach (['audit_enabled', 'audit_html_report', 'canonical_fallback', 'schema_validation', 'multilingual_validation', 'image_audit'] as $key) {
                $sanitized[$key] = isset($value[$key]) ? '1' : '0';
            }
        }
        if (in_array($section, ['sitemaps', 'all'], true)) {
            foreach (['advanced_sitemap', 'sitemap_lastmod', 'sitemap_images', 'sitemap_hreflang', 'sitemap_video', 'sitemap_news'] as $key) {
                $sanitized[$key] = isset($value[$key]) ? '1' : '0';
            }
        }
        if (in_array($section, ['redirects', 'all'], true)) {
            $sanitized['redirect_old_slugs'] = isset($value['redirect_old_slugs']) ? '1' : '0';
            $sanitized['redirect_import_plugins'] = isset($value['redirect_import_plugins']) ? '1' : '0';
            $sanitized['redirect_rules'] = sanitize_textarea_field((string) ($value['redirect_rules'] ?? ''));
        }
        if (in_array($section, ['indexing', 'all'], true)) {
            $sanitized['noindex_paths'] = sanitize_textarea_field((string) ($value['noindex_paths'] ?? ''));
            $sanitized['x_robots_rules'] = sanitize_textarea_field((string) ($value['x_robots_rules'] ?? ''));
            $sanitized['site_noindex'] = isset($value['site_noindex']) ? '1' : '0';
            $sanitized['indexnow_enabled'] = isset($value['indexnow_enabled']) ? '1' : '0';
            $submitted_key = sanitize_text_field((string) ($value['indexnow_key'] ?? ''));
            if ($submitted_key === '') {
                $sanitized['indexnow_key'] = (string) ($current['indexnow_key'] ?? '');
            } elseif (preg_match('/^[A-Za-z0-9-]{8,128}$/', $submitted_key) === 1) {
                $sanitized['indexnow_key'] = $submitted_key;
            } else {
                add_settings_error(Plugin::SEO_SETTINGS_KEY, 'indexnow-key-error', __('IndexNow key must be 8–128 characters and contain only letters, numbers, or hyphens.', 'ragnus-static-publisher'), 'error');
                $sanitized['indexnow_key'] = (string) ($current['indexnow_key'] ?? '');
                $sanitized['indexnow_enabled'] = '0';
            }
        }
        if (in_array($section, ['performance', 'all'], true)) {
            $sanitized['performance_audit'] = isset($value['performance_audit']) ? '1' : '0';
            $sanitized['large_html_kb'] = max(50, min(5000, absint($value['large_html_kb'] ?? $defaults['large_html_kb'])));
            $sanitized['large_asset_kb'] = max(100, min(20000, absint($value['large_asset_kb'] ?? $defaults['large_asset_kb'])));
        }
        return $sanitized;
    }

    public static function sanitize_hide_settings(array $value): array
    {
        $defaults = Plugin::hide_defaults();
        $current = Plugin::hide_settings();
        $section = sanitize_key((string) ($value['_section'] ?? 'all'));
        $sanitized = array_intersect_key($current, $defaults);
        $path_keys = [
            'wp_content_directory',
            'wp_includes_directory',
            'uploads_directory',
            'plugins_directory',
            'themes_directory',
            'theme_style_name',
            'author_url',
        ];
        if (in_array($section, ['directory', 'all'], true)) {
            foreach ($path_keys as $key) {
                $default = $defaults[$key];
                $candidate = strtolower(trim((string) ($value[$key] ?? '')));
                if ($key === 'theme_style_name') {
                    $candidate = preg_replace('/\.css$/i', '', $candidate) ?? $candidate;
                }
                $candidate = sanitize_key(str_replace(' ', '-', $candidate));
                $sanitized[$key] = $candidate !== '' ? $candidate : $default;
            }
        }

        $trace_keys = [
            'hide_wordpress_version',
            'hide_generator_meta',
            'hide_wordpress_dns_prefetch',
            'hide_rsd_header',
        ];
        if (in_array($section, ['traces', 'all'], true)) {
            foreach ($trace_keys as $key) {
                $sanitized[$key] = isset($value[$key]) ? '1' : '0';
            }
        }

        $static_output_keys = array_diff(array_keys($defaults), $path_keys, $trace_keys);
        if (in_array($section, ['static-outputs', 'all'], true)) {
            foreach ($static_output_keys as $key) {
                $sanitized[$key] = isset($value[$key]) ? '1' : '0';
            }
        }
        return $sanitized;
    }

    public static function sanitize_search_settings(array $value): array
    {
        $defaults = Plugin::search_defaults();
        $current = Plugin::search_settings();
        $section = sanitize_key((string) ($value['_section'] ?? 'all'));
        $fields = ['index_title', 'index_excerpt', 'index_content', 'index_taxonomies'];
        $sanitized = array_intersect_key($current, $defaults);

        if (in_array($section, ['static', 'all'], true)) {
            $sanitized['enabled'] = isset($value['enabled']) ? '1' : '0';
            $sanitized['page_path'] = sanitize_title((string) ($value['page_path'] ?? $defaults['page_path'])) ?: $defaults['page_path'];
            $sanitized['result_limit'] = max(5, min(100, absint($value['result_limit'] ?? $defaults['result_limit'])));
            $sanitized['min_chars'] = max(1, min(10, absint($value['min_chars'] ?? $defaults['min_chars'])));
            $sanitized['content_limit'] = max(500, min(20000, absint($value['content_limit'] ?? $defaults['content_limit'])));
            $sanitized['threshold'] = (string) max(0.1, min(0.8, (float) ($value['threshold'] ?? $defaults['threshold'])));
            $sanitized['token_match'] = in_array(($value['token_match'] ?? ''), ['all', 'any'], true) ? $value['token_match'] : 'all';
        }

        if (in_array($section, ['selectors', 'all'], true)) {
            $sanitized['title_selector'] = sanitize_text_field((string) ($value['title_selector'] ?? $defaults['title_selector']));
            $sanitized['content_selector'] = sanitize_text_field((string) ($value['content_selector'] ?? $defaults['content_selector']));
            $sanitized['excerpt_selector'] = sanitize_text_field((string) ($value['excerpt_selector'] ?? $defaults['excerpt_selector']));
            $sanitized['exclude_urls'] = sanitize_textarea_field((string) ($value['exclude_urls'] ?? ''));
        }

        if (in_array($section, ['fuse', 'all'], true)) {
            foreach ($fields as $field) {
                $sanitized[$field] = isset($value[$field]) ? '1' : '0';
            }
            if (! in_array('1', array_intersect_key($sanitized, array_flip($fields)), true)) {
                $sanitized['index_title'] = '1';
            }
            foreach (['title_weight', 'excerpt_weight', 'content_weight', 'taxonomy_weight'] as $weight) {
                $sanitized[$weight] = (string) max(0.1, min(10, (float) ($value[$weight] ?? $defaults[$weight])));
            }
        }

        return $sanitized;
    }

    public static function start_export(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'ragnus-static-publisher'), 403);
        }
        check_admin_referer('ragnus_static_export');
        $current_status = Plugin::public_status();
        $current_state = (string) ($current_status['state'] ?? '');
        $retry_stalled_queue = $current_state === 'queued' && ! empty($current_status['stalled']) && ! get_transient(Plugin::LOCK_KEY);
        if (get_transient(Plugin::LOCK_KEY) || (in_array($current_state, ['queued', 'running'], true) && ! $retry_stalled_queue)) {
            wp_safe_redirect(add_query_arg('started', 'running', self::admin_page_url('main')));
            exit;
        }
        if ($retry_stalled_queue) {
            $old_job_id = (string) ($current_status['job_id'] ?? '');
            $scheduled = $old_job_id === '' ? false : wp_next_scheduled(Plugin::CRON_HOOK, [$old_job_id]);
            if ($scheduled !== false) {
                wp_unschedule_event($scheduled, Plugin::CRON_HOOK, [$old_job_id]);
            }
        }
        $job_id = Plugin::schedule_export('admin');
        if (Plugin::cloudflare_deployment_configured()) {
            Plugin::record_deployment_status($job_id, 'waiting', [
                'build_sha256' => '',
                'deployment_url' => '',
                'error' => '',
            ]);
        }
        wp_safe_redirect(add_query_arg('started', '1', self::admin_page_url('main')));
        exit;
    }

    public static function download_export(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'ragnus-static-publisher'), 403);
        }
        check_admin_referer('ragnus_static_download');

        $archive = Archive_Manager::latest();
        if ($archive === null || ! is_readable((string) $archive['path'])) {
            wp_die(__('No downloadable exports found.', 'ragnus-static-publisher'), 404);
        }

        self::send_file((string) $archive['path'], (string) $archive['id'] . '.zip');
    }

    public static function run_pending_export(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not authorized for this operation.', 'ragnus-static-publisher')], 403);
        }
        check_ajax_referer('ragnus_static_run_pending', 'nonce');

        $status = Plugin::status();
        $job_id = sanitize_file_name((string) ($status['job_id'] ?? ''));
        if (($status['state'] ?? '') !== 'queued' || $job_id === '') {
            wp_send_json_success(['status' => Plugin::public_status()]);
        }
        if (get_transient(Plugin::LOCK_KEY)) {
            wp_send_json_error(['message' => __('The export process is already running.', 'ragnus-static-publisher')], 409);
        }

        $scheduled = wp_next_scheduled(Plugin::CRON_HOOK, [$job_id]);
        if ($scheduled !== false) {
            wp_unschedule_event($scheduled, Plugin::CRON_HOOK, [$job_id]);
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        Plugin::run_scheduled($job_id);
        wp_send_json_success(['status' => Plugin::public_status()]);
    }

    public static function refresh_diagnostics(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'ragnus-static-publisher'), 403);
        }
        check_admin_referer('ragnus_static_refresh_diagnostics');
        Diagnostics::refresh();
        wp_safe_redirect(add_query_arg('checked', '1', self::admin_page_url('diagnostics')));
        exit;
    }

    public static function test_sftp_connection(): void
    {
        self::authorize_sftp_action('ragnus_static_sftp_test');
        try {
            SFTP_Deployer::test_connection();
            self::redirect_sftp('connection-success');
        } catch (\Throwable $error) {
            self::redirect_sftp('error');
        }
    }

    public static function deploy_latest_with_sftp(): void
    {
        self::authorize_sftp_action('ragnus_static_sftp_deploy');
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        try {
            SFTP_Deployer::deploy_latest();
            self::redirect_sftp('deploy-success');
        } catch (\Throwable $error) {
            SFTP_Deployer::record_failure($error->getMessage());
            self::redirect_sftp('error');
        }
    }

    private static function authorize_sftp_action(string $nonce_action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'ragnus-static-publisher'), 403);
        }
        check_admin_referer($nonce_action);
    }

    private static function redirect_sftp(string $notice): void
    {
        wp_safe_redirect(add_query_arg('sftp_notice', $notice, self::deploy_page_url('sftp')));
        exit;
    }

    public static function download_archive(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'ragnus-static-publisher'), 403);
        }

        $archive_id = isset($_GET['archive_id']) ? sanitize_file_name(wp_unslash((string) $_GET['archive_id'])) : '';
        check_admin_referer('ragnus_static_download_archive_' . $archive_id);
        $archive = Archive_Manager::find($archive_id);
        if ($archive === null || ! is_readable((string) $archive['path'])) {
            wp_die(__('No downloadable ZIP file found.', 'ragnus-static-publisher'), 404);
        }

        self::send_file((string) $archive['path'], $archive_id . '.zip');
    }

    public static function archive_bulk_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'ragnus-static-publisher'), 403);
        }
        check_admin_referer('ragnus_static_archive_bulk');

        $requested_ids = [];
        if (isset($_POST['archive_ids']) && is_array($_POST['archive_ids'])) {
            foreach (wp_unslash($_POST['archive_ids']) as $archive_id) {
                if (is_string($archive_id)) {
                    $requested_ids[] = sanitize_file_name($archive_id);
                }
            }
        }
        $single_delete = isset($_POST['delete_archive'])
            ? sanitize_file_name(wp_unslash((string) $_POST['delete_archive']))
            : '';
        $bulk_action = isset($_POST['archive_bulk_action'])
            ? sanitize_key(wp_unslash((string) $_POST['archive_bulk_action']))
            : '';

        if ($single_delete !== '') {
            $requested_ids = [$single_delete];
            $bulk_action = 'delete';
        }
        if ($requested_ids === []) {
            self::redirect_archive_notice('no-selection');
        }

        if ($bulk_action === 'download') {
            $archives = Archive_Manager::selected($requested_ids);
            if (count($archives) === 1) {
                self::send_file((string) $archives[0]['path'], (string) $archives[0]['id'] . '.zip');
            }

            try {
                $bundle = Archive_Manager::create_bundle($requested_ids);
                self::send_file($bundle, 'ragnus-static-selected-exports.zip', true);
            } catch (\RuntimeException $error) {
                wp_die(esc_html($error->getMessage()), 500);
            }
        }

        if ($bulk_action === 'delete') {
            if (get_transient(Plugin::LOCK_KEY)) {
                self::redirect_archive_notice('running');
            }
            $result = Archive_Manager::delete($requested_ids);
            self::redirect_archive_notice($result['failed'] > 0 ? 'partial' : 'deleted', $result);
        }

        self::redirect_archive_notice('invalid-action');
    }

    private static function send_file(string $path, string $filename, bool $delete_after = false): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        if ($delete_after) {
            unlink($path);
        }
        exit;
    }

    private static function redirect_archive_notice(string $notice, array $result = []): void
    {
        $query = [
            'archive_notice' => $notice,
            'deleted' => absint($result['deleted'] ?? 0),
            'failed' => absint($result['failed'] ?? 0),
        ];
        $zip_page = isset($_POST['zip_page']) ? max(1, absint(wp_unslash((string) $_POST['zip_page']))) : 1;
        if ($zip_page > 1) {
            $query['zip_page'] = $zip_page;
        }

        wp_safe_redirect(add_query_arg($query, self::deploy_page_url('zip')));
        exit;
    }

    public static function cleanup_exports(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'ragnus-static-publisher'), 403);
        }
        check_admin_referer('ragnus_static_cleanup_exports');

        if (get_transient(Plugin::LOCK_KEY)) {
            wp_safe_redirect(add_query_arg('cleanup', 'running', self::deploy_page_url('zip')));
            exit;
        }

        $result = Archive_Manager::delete_old_archives();
        $query = [
            'cleanup' => $result['failed'] > 0 ? 'partial' : 'success',
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ];
        wp_safe_redirect(add_query_arg($query, self::deploy_page_url('zip')));
        exit;
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $requested_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'main';
        $requested_settings_tab = isset($_GET['settings_tab']) ? sanitize_key(wp_unslash((string) $_GET['settings_tab'])) : 'general';
        $requested_deploy_tab = isset($_GET['deploy_tab']) ? sanitize_key(wp_unslash((string) $_GET['deploy_tab'])) : 'zip';
        $requested_seo_tab = isset($_GET['seo_tab']) ? sanitize_key(wp_unslash((string) $_GET['seo_tab'])) : 'plugins';
        $requested_search_tab = isset($_GET['search_tab']) ? sanitize_key(wp_unslash((string) $_GET['search_tab'])) : 'static';
        $requested_hide_tab = isset($_GET['hide_tab']) ? sanitize_key(wp_unslash((string) $_GET['hide_tab'])) : 'directory';
        if ($requested_tab === 'settings' && $requested_settings_tab === 'automation') {
            $requested_tab = 'deploy';
            $requested_deploy_tab = 'auto-deploy';
        } elseif ($requested_tab === 'settings' && $requested_settings_tab === 'deploy') {
            $requested_tab = 'deploy';
            $requested_deploy_tab = 'github';
        } elseif ($requested_tab === 'settings' && $requested_settings_tab === 'languages') {
            $requested_settings_tab = 'multilingual';
        } elseif ($requested_tab === 'seo' && $requested_seo_tab === 'language') {
            $requested_tab = 'settings';
            $requested_settings_tab = 'multilingual';
        } elseif ($requested_tab === 'deploy' && $requested_deploy_tab === 'multilingual') {
            $requested_tab = 'settings';
            $requested_settings_tab = 'multilingual';
        } elseif ($requested_tab === 'files') {
            $requested_tab = 'deploy';
            $requested_deploy_tab = 'zip';
        }
        $tabs = [
            'main' => __('Main', 'ragnus-static-publisher'),
            'deploy' => __('Deploy', 'ragnus-static-publisher'),
            'settings' => __('Static Site', 'ragnus-static-publisher'),
            'seo' => __('SEO', 'ragnus-static-publisher'),
            'search' => __('Search', 'ragnus-static-publisher'),
            'hide' => __('Hide', 'ragnus-static-publisher'),
            'diagnostics' => __('Diagnostics', 'ragnus-static-publisher'),
            'activity' => __('Activity Logs', 'ragnus-static-publisher'),
            'about' => __('About', 'ragnus-static-publisher'),
        ];
        $current_tab = isset($tabs[$requested_tab]) ? $requested_tab : 'main';
        $status = Plugin::public_status();
        $archives = Archive_Manager::archives();
        ?>
        <div class="wrap ragstat-admin">
            <hr class="wp-header-end">
            <header class="ragstat-admin-header">
                <span class="ragstat-admin-header__icon dashicons dashicons-media-document" aria-hidden="true"></span>
                <div>
                    <h1>Ragnus Static Publisher</h1>
                    <p><?php esc_html_e('Generate a static copy of your WordPress site. Cloudflare credentials are not kept in this plugin.', 'ragnus-static-publisher'); ?></p>
                </div>
            </header>
            <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php echo esc_attr__('Static Publisher sections', 'ragnus-static-publisher'); ?>">
                <?php foreach ($tabs as $tab_id => $tab_label) : ?>
                    <a class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::admin_page_url($tab_id)); ?>" <?php echo $current_tab === $tab_id ? 'aria-current="page"' : ''; ?>><?php echo esc_html($tab_label); ?></a>
                <?php endforeach; ?>
            </nav>

            <main class="ragstat-tab-content">
            <?php if ($current_tab === 'main') : ?>
                <?php self::render_main_tab($status, $archives); ?>
            <?php elseif ($current_tab === 'deploy') : ?>
                <?php self::render_deploy_tab($archives, Plugin::settings(), $requested_deploy_tab); ?>
            <?php elseif ($current_tab === 'activity') : ?>
                <?php self::render_activity_tab(); ?>
            <?php elseif ($current_tab === 'settings') : ?>
                <?php self::render_settings_tab(Plugin::settings(), $requested_settings_tab); ?>
            <?php elseif ($current_tab === 'seo') : ?>
                <?php self::render_seo_tab($requested_seo_tab, Plugin::seo_settings()); ?>
            <?php elseif ($current_tab === 'search') : ?>
                <?php self::render_search_tab(Plugin::search_settings(), $requested_search_tab); ?>
            <?php elseif ($current_tab === 'hide') : ?>
                <?php self::render_hide_tab(Plugin::hide_settings(), $requested_hide_tab); ?>
            <?php elseif ($current_tab === 'diagnostics') : ?>
                <?php self::render_diagnostics_tab(); ?>
            <?php else : ?>
                <?php self::render_about_tab(); ?>
            <?php endif; ?>
            </main>
            <?php self::render_confirmation_modal(); ?>
        </div>
        <?php
    }

    private static function render_confirmation_modal(): void
    {
        ?>
        <div class="ragstat-confirm-modal" data-ragstat-confirm-modal hidden>
            <div class="ragstat-confirm-modal__backdrop" data-ragstat-confirm-cancel></div>
            <div class="ragstat-confirm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ragstat-confirm-title" aria-describedby="ragstat-confirm-message">
                <span class="ragstat-confirm-modal__icon dashicons dashicons-warning" aria-hidden="true"></span>
                <div class="ragstat-confirm-modal__content">
                    <h2 id="ragstat-confirm-title"><?php esc_html_e('Confirm Transaction', 'ragnus-static-publisher'); ?></h2>
                    <p id="ragstat-confirm-message"></p>
                </div>
                <div class="ragstat-confirm-modal__actions">
                    <button type="button" class="button" data-ragstat-confirm-cancel><?php esc_html_e('Cancel', 'ragnus-static-publisher'); ?></button>
                    <button type="button" class="button button-primary ragstat-confirm-modal__confirm" data-ragstat-confirm-accept><?php esc_html_e('Delete', 'ragnus-static-publisher'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function admin_page_url(string $tab): string
    {
        $url = admin_url('admin.php?page=ragnus-static-publisher');
        return $tab === 'main' ? $url : add_query_arg('tab', $tab, $url);
    }

    private static function settings_page_url(string $settings_tab): string
    {
        return add_query_arg('settings_tab', $settings_tab, self::admin_page_url('settings'));
    }

    private static function deploy_page_url(string $deploy_tab): string
    {
        return add_query_arg('deploy_tab', $deploy_tab, self::admin_page_url('deploy'));
    }

    private static function seo_page_url(string $seo_tab): string
    {
        return add_query_arg('seo_tab', $seo_tab, self::admin_page_url('seo'));
    }

    private static function search_page_url(string $search_tab): string
    {
        return add_query_arg('search_tab', $search_tab, self::admin_page_url('search'));
    }

    private static function hide_page_url(string $hide_tab): string
    {
        return add_query_arg('hide_tab', $hide_tab, self::admin_page_url('hide'));
    }

    private static function render_main_tab(array $status, array $archives): void
    {
        $state = (string) ($status['state'] ?? '');
        $progress = max(0, min(100, absint($status['progress'] ?? 0)));
        $state_labels = [
            'queued' => __('Queued', 'ragnus-static-publisher'),
            'running' => __('Running', 'ragnus-static-publisher'),
            'completed' => __('Completed', 'ragnus-static-publisher'),
            'failed' => __('Failed', 'ragnus-static-publisher'),
        ];
        $last_completed_at = is_string($status['last_completed_at'] ?? null)
            ? $status['last_completed_at']
            : ($state === 'completed' && is_string($status['finished_at'] ?? null) ? $status['finished_at'] : '');
        $last_completed_timestamp = $last_completed_at !== '' ? strtotime($last_completed_at) : false;
        $is_active = in_array($state, ['queued', 'running'], true)
            && ! ($state === 'queued' && ! empty($status['stalled']));
        $runtime_notice = (string) ($status['runtime_notice'] ?? '');
        $cloudflare_configured = Plugin::cloudflare_deployment_configured();
        $deployment = is_array($status['deployment'] ?? null) ? $status['deployment'] : [];
        $deployment_state = (string) ($deployment['state'] ?? '');
        $deployment_labels = [
            'waiting' => __('Waiting', 'ragnus-static-publisher'),
            'dispatched' => __('Queued', 'ragnus-static-publisher'),
            'deploying' => __('Running', 'ragnus-static-publisher'),
            'completed' => __('Completed', 'ragnus-static-publisher'),
            'failed' => __('Failed', 'ragnus-static-publisher'),
        ];
        ?>
        <div data-ragstat-status-root>
        <h2><?php esc_html_e('Publishing Status', 'ragnus-static-publisher'); ?></h2>
        <div id="ragstat-runtime-notice" class="notice notice-warning inline ragstat-runtime-notice" role="status" <?php echo $runtime_notice === '' ? 'hidden' : ''; ?>><p><?php echo esc_html($runtime_notice); ?></p></div>
        <table class="widefat striped ragstat-status-table">
            <tbody>
            <tr><th><?php esc_html_e('Status', 'ragnus-static-publisher'); ?></th><td id="ragstat-status-state"><?php echo esc_html($state_labels[$state] ?? __('Not run yet', 'ragnus-static-publisher')); ?></td></tr>
            <tr>
                <th><?php esc_html_e('Progress', 'ragnus-static-publisher'); ?></th>
                <td id="ragstat-progress-cell">
                    <?php if ($state === 'completed') : ?>
                        <span class="ragstat-progress-status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong><?php esc_html_e('Completed', 'ragnus-static-publisher'); ?></strong></span>
                    <?php elseif ($state === 'failed') : ?>
                        <span class="ragstat-progress-status"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><strong><?php esc_html_e('Failed', 'ragnus-static-publisher'); ?></strong></span>
                    <?php else : ?>
                        <div class="ragstat-progress <?php echo in_array($state, ['queued', 'running'], true) ? 'is-active' : ''; ?>" role="progressbar" aria-label="<?php echo esc_attr__('Static rendering progress', 'ragnus-static-publisher'); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $progress); ?>">
                            <div class="ragstat-progress__bar" style="width:<?php echo esc_attr((string) $progress); ?>%"></div>
                        </div>
                        <span class="ragstat-progress-percent"><?php echo esc_html((string) $progress); ?>%</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr><th><?php esc_html_e('Stage', 'ragnus-static-publisher'); ?></th><td id="ragstat-status-message"><?php echo esc_html((string) ($status['status_message'] ?? '—')); ?></td></tr>
            <tr><th><?php esc_html_e('Job ID', 'ragnus-static-publisher'); ?></th><td><code id="ragstat-job-id"><?php echo esc_html((string) ($status['job_id'] ?? '—')); ?></code></td></tr>
            <tr><th><?php esc_html_e('URL Count', 'ragnus-static-publisher'); ?></th><td id="ragstat-url-count"><?php echo esc_html((string) ($status['url_count'] ?? 0)); ?></td></tr>
            <tr id="ragstat-current-url-row" <?php echo empty($status['current_url']) ? 'hidden' : ''; ?>><th><?php esc_html_e('Current URL', 'ragnus-static-publisher'); ?></th><td><code id="ragstat-current-url"><?php echo esc_html((string) ($status['current_url'] ?? '')); ?></code></td></tr>
            <tr><th><?php esc_html_e('Last Static Build', 'ragnus-static-publisher'); ?></th><td id="ragstat-last-completed"><?php echo $last_completed_timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $last_completed_timestamp)); ?></td></tr>
            <tr id="ragstat-error-row" <?php echo empty($status['error']) ? 'hidden' : ''; ?>><th><?php esc_html_e('Error', 'ragnus-static-publisher'); ?></th><td id="ragstat-error"><?php echo esc_html((string) ($status['error'] ?? '')); ?></td></tr>
            </tbody>
        </table>

        <?php if ($cloudflare_configured) : ?>
            <h2><?php esc_html_e('Cloudflare Deploy', 'ragnus-static-publisher'); ?></h2>
            <table class="widefat striped ragstat-status-table ragstat-deployment-status-table">
                <tbody>
                <tr><th><?php esc_html_e('Status', 'ragnus-static-publisher'); ?></th><td id="ragstat-deployment-state"><?php echo esc_html($deployment_labels[$deployment_state] ?? __('Not deployed yet', 'ragnus-static-publisher')); ?></td></tr>
                <tr><th><?php esc_html_e('Job ID', 'ragnus-static-publisher'); ?></th><td><code id="ragstat-deployment-job-id"><?php echo esc_html((string) ($deployment['job_id'] ?? '—')); ?></code></td></tr>
                <tr><th><?php esc_html_e('Last Cloudflare Deploy', 'ragnus-static-publisher'); ?></th><td id="ragstat-deployment-updated"><?php echo esc_html((string) ($deployment['updated_display'] ?? '—')); ?></td></tr>
                <tr id="ragstat-deployment-url-row" <?php echo empty($deployment['deployment_url']) ? 'hidden' : ''; ?>><th><?php esc_html_e('Deployment URL', 'ragnus-static-publisher'); ?></th><td><a id="ragstat-deployment-url" href="<?php echo esc_url((string) ($deployment['deployment_url'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) ($deployment['deployment_url'] ?? '')); ?></a></td></tr>
                <tr id="ragstat-deployment-error-row" <?php echo empty($deployment['error']) ? 'hidden' : ''; ?>><th><?php esc_html_e('Error', 'ragnus-static-publisher'); ?></th><td id="ragstat-deployment-error"><?php echo esc_html((string) ($deployment['error'] ?? '')); ?></td></tr>
                </tbody>
            </table>
        <?php endif; ?>

        <form class="ragstat-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ragnus_static_export">
            <?php wp_nonce_field('ragnus_static_export'); ?>
            <?php submit_button($cloudflare_configured ? __('Deploy to Cloudflare', 'ragnus-static-publisher') : __('Create Static Site', 'ragnus-static-publisher'), 'primary', 'submit', false, $is_active ? ['disabled' => 'disabled'] : []); ?>
            <a id="ragstat-download" class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ragnus_static_download'), 'ragnus_static_download')); ?>" <?php echo $archives === [] ? 'hidden' : ''; ?>><?php esc_html_e('Download', 'ragnus-static-publisher'); ?></a>
        </form>
        </div>
        <?php
    }

    private static function render_zip_files(array $archives): void
    {
        $cleanup_status = isset($_GET['cleanup']) ? sanitize_key(wp_unslash((string) $_GET['cleanup'])) : '';
        $archive_notice = isset($_GET['archive_notice']) ? sanitize_key(wp_unslash((string) $_GET['archive_notice'])) : '';
        $per_page = 10;
        $total_archives = count($archives);
        $total_pages = max(1, (int) ceil($total_archives / $per_page));
        $current_page = isset($_GET['zip_page']) ? max(1, absint(wp_unslash((string) $_GET['zip_page']))) : 1;
        $current_page = min($current_page, $total_pages);
        $archive_offset = ($current_page - 1) * $per_page;
        $visible_archives = array_slice($archives, $archive_offset, $per_page);
        ?>
        <h2><?php esc_html_e('ZIP Files', 'ragnus-static-publisher'); ?></h2>
        <?php if ($cleanup_status === 'success') : ?>
            <div class="notice notice-success inline is-dismissible ragstat-files-notice"><p><?php echo esc_html(sprintf(__('The old ZIP file %d has been deleted.', 'ragnus-static-publisher'), absint($_GET['deleted'] ?? 0))); ?></p></div>
        <?php elseif ($cleanup_status === 'partial') : ?>
            <div class="notice notice-warning inline is-dismissible ragstat-files-notice"><p><?php echo esc_html(sprintf(__('%1$d old ZIP file was deleted, %2$d file could not be deleted.', 'ragnus-static-publisher'), absint($_GET['deleted'] ?? 0), absint($_GET['failed'] ?? 0))); ?></p></div>
        <?php elseif ($cleanup_status === 'running') : ?>
            <div class="notice notice-warning inline is-dismissible ragstat-files-notice"><p><?php esc_html_e('Old files cannot be deleted while export is in progress.', 'ragnus-static-publisher'); ?></p></div>
        <?php endif; ?>

        <?php if ($archive_notice === 'deleted') : ?>
            <div class="notice notice-success inline is-dismissible ragstat-files-notice"><p><?php echo esc_html(sprintf(__('%d ZIP file has been deleted.', 'ragnus-static-publisher'), absint($_GET['deleted'] ?? 0))); ?></p></div>
        <?php elseif ($archive_notice === 'partial') : ?>
            <div class="notice notice-warning inline is-dismissible ragstat-files-notice"><p><?php echo esc_html(sprintf(__('ZIP file %1$d was deleted, file %2$d could not be deleted.', 'ragnus-static-publisher'), absint($_GET['deleted'] ?? 0), absint($_GET['failed'] ?? 0))); ?></p></div>
        <?php elseif ($archive_notice === 'no-selection') : ?>
            <div class="notice notice-warning inline is-dismissible ragstat-files-notice"><p><?php esc_html_e('Select at least one ZIP file to process.', 'ragnus-static-publisher'); ?></p></div>
        <?php elseif ($archive_notice === 'invalid-action') : ?>
            <div class="notice notice-warning inline is-dismissible ragstat-files-notice"><p><?php esc_html_e('Select a valid batch action.', 'ragnus-static-publisher'); ?></p></div>
        <?php elseif ($archive_notice === 'running') : ?>
            <div class="notice notice-warning inline is-dismissible ragstat-files-notice"><p><?php esc_html_e('ZIP files cannot be deleted while export is in progress.', 'ragnus-static-publisher'); ?></p></div>
        <?php endif; ?>

        <?php if ($archives === []) : ?>
            <p><?php esc_html_e('There is no ZIP file created yet.', 'ragnus-static-publisher'); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ragnus_static_archive_bulk">
                <input type="hidden" name="zip_page" value="<?php echo esc_attr((string) $current_page); ?>">
                <?php wp_nonce_field('ragnus_static_archive_bulk'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label class="screen-reader-text" for="ragstat-bulk-action"><?php esc_html_e('Select Batch Action', 'ragnus-static-publisher'); ?></label>
                        <select id="ragstat-bulk-action" name="archive_bulk_action">
                            <option value=""><?php esc_html_e('Batch Process', 'ragnus-static-publisher'); ?></option>
                            <option value="download"><?php esc_html_e('Download Selected', 'ragnus-static-publisher'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete Selected', 'ragnus-static-publisher'); ?></option>
                        </select>
                        <?php submit_button(__('Apply', 'ragnus-static-publisher'), 'action', 'bulk_submit', false, [
                            'data-ragstat-confirm' => __('The selected ZIP files will be permanently deleted. Should we continue?', 'ragnus-static-publisher'),
                            'data-ragstat-confirm-action' => 'delete',
                        ]); ?>
                    </div>
                    <br class="clear">
                </div>
                <div class="ragstat-files-table-wrap">
                <table class="widefat striped ragstat-files-table">
                    <thead><tr><td class="manage-column check-column"><input id="cb-select-all-1" type="checkbox"><label for="cb-select-all-1"><span class="screen-reader-text"><?php esc_html_e('Select All', 'ragnus-static-publisher'); ?></span></label></td><th class="ragstat-number-column"><?php esc_html_e('Order', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Job ID', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('URL Count', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Creation Date', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Creation Time', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Actions', 'ragnus-static-publisher'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($visible_archives as $archive_index => $archive) : ?>
                        <?php
                        $archive_id = (string) $archive['id'];
                        $download_url = wp_nonce_url(
                            add_query_arg(['action' => 'ragnus_static_download_archive', 'archive_id' => $archive_id], admin_url('admin-post.php')),
                            'ragnus_static_download_archive_' . $archive_id
                        );
                        ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" name="archive_ids[]" value="<?php echo esc_attr($archive_id); ?>"><span class="screen-reader-text"><?php echo esc_html(sprintf(__('Select %s', 'ragnus-static-publisher'), (string) $archive['job_id'])); ?></span></th>
                            <td class="ragstat-number-column"><?php echo esc_html((string) ($archive_offset + $archive_index + 1)); ?></td>
                            <td><code><?php echo esc_html((string) $archive['job_id']); ?></code></td>
                            <td><?php echo $archive['url_count'] === null ? '—' : esc_html((string) $archive['url_count']); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $archive['created_at'])); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $archive['created_at'])); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($download_url); ?>"><?php esc_html_e('Download', 'ragnus-static-publisher'); ?></a> <button class="button button-small button-link-delete" type="submit" name="delete_archive" value="<?php echo esc_attr($archive_id); ?>" data-ragstat-confirm="<?php echo esc_attr__('This ZIP file will be permanently deleted. Do you want to continue?', 'ragnus-static-publisher'); ?>" data-ragstat-clear-bulk-action><?php esc_html_e('Delete', 'ragnus-static-publisher'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($total_pages > 1) : ?>
                    <?php
                    $pagination_base = str_replace(
                        '999999999',
                        '%#%',
                        add_query_arg('zip_page', 999999999, self::deploy_page_url('zip'))
                    );
                    $pagination = paginate_links([
                        'base' => $pagination_base,
                        'format' => '',
                        'current' => $current_page,
                        'total' => $total_pages,
                        'type' => 'list',
                        'prev_text' => __('‹ Previous', 'ragnus-static-publisher'),
                        'next_text' => __('Next ›', 'ragnus-static-publisher'),
                    ]);
                    ?>
                    <?php if (is_string($pagination)) : ?>
                        <nav class="ragstat-zip-pagination" aria-label="<?php echo esc_attr__('ZIP Files', 'ragnus-static-publisher'); ?>">
                            <?php echo wp_kses_post($pagination); ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <form class="ragstat-cleanup-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ragnus_static_cleanup_exports">
            <?php wp_nonce_field('ragnus_static_cleanup_exports'); ?>
            <?php submit_button(__('Delete Old Files', 'ragnus-static-publisher'), 'delete', 'submit', false, [
                'data-ragstat-confirm' => __('All old ZIP files except the latest ZIP will be deleted. Should we continue?', 'ragnus-static-publisher'),
            ]); ?>
            <p class="description"><?php esc_html_e('The latest ZIP file is preserved; Previous ZIP files and their temporary build folders are permanently deleted.', 'ragnus-static-publisher'); ?></p>
        </form>
        <?php
    }

    private static function render_deploy_tab(array $archives, array $settings, string $requested_deploy_tab): void
    {
        $has_archive = $archives !== [];
        $github_configured = (string) ($settings['deployment_webhook_url'] ?? '') !== '';
        $sftp_configured = SFTP_Deployer::configured($settings);
        $deploy_tabs = [
            'zip' => [__('ZIP File', 'ragnus-static-publisher'), 'dashicons-media-archive'],
            'github' => [__('GitHub', 'ragnus-static-publisher'), 'github'],
            'cloudflare' => [__('Cloudflare', 'ragnus-static-publisher'), 'cloudflare'],
            'sftp' => [__('SFTP', 'ragnus-static-publisher'), 'dashicons-upload'],
            'auto-deploy' => [__('Auto Deploy', 'ragnus-static-publisher'), 'dashicons-update'],
        ];
        $current_deploy_tab = isset($deploy_tabs[$requested_deploy_tab]) ? $requested_deploy_tab : 'zip';
        ?>
        <div class="ragstat-deploy-header">
            <div>
                <h2><?php esc_html_e('Deploy', 'ragnus-static-publisher'); ?></h2>
            </div>
        </div>
        <div class="ragstat-deploy-layout">
            <nav class="ragstat-deploy-tabs" aria-label="<?php echo esc_attr__('Deploy', 'ragnus-static-publisher'); ?>">
                <?php foreach ($deploy_tabs as $deploy_tab => [$label, $icon]) : ?>
                    <a class="ragstat-deploy-tab <?php echo $current_deploy_tab === $deploy_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::deploy_page_url($deploy_tab)); ?>" <?php echo $current_deploy_tab === $deploy_tab ? 'aria-current="page"' : ''; ?>>
                        <?php if ($icon === 'github') : ?>
                            <?php self::render_github_icon('ragstat-deploy-tab__github-icon'); ?>
                        <?php elseif ($icon === 'cloudflare') : ?>
                            <?php self::render_cloudflare_icon('ragstat-deploy-tab__cloudflare-icon'); ?>
                        <?php else : ?>
                            <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span><?php echo esc_html($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="ragstat-deploy-panel">
                <?php if ($current_deploy_tab === 'zip') : ?>
                    <section class="ragstat-deploy-card" aria-labelledby="ragstat-deploy-zip-title">
                        <span class="ragstat-deploy-card__icon dashicons dashicons-media-archive" aria-hidden="true"></span>
                        <div class="ragstat-deploy-card__content">
                            <h3 id="ragstat-deploy-zip-title"><?php esc_html_e('ZIP File', 'ragnus-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Download the created static site package and manually install it on the desired server.', 'ragnus-static-publisher'); ?></p>
                        </div>
                        <div class="ragstat-deploy-card__footer">
                            <span class="ragstat-deploy-status <?php echo $has_archive ? 'is-ready' : 'is-pending'; ?>"><?php echo $has_archive ? __('ZIP ready', 'ragnus-static-publisher') : __('Create static site first', 'ragnus-static-publisher'); ?></span>
                        </div>
                        <div class="ragstat-deploy-card__files">
                            <?php self::render_zip_files($archives); ?>
                        </div>
                    </section>
                    <?php self::render_zip_settings($settings); ?>
                <?php elseif ($current_deploy_tab === 'github') : ?>
                    <section class="ragstat-deploy-card" aria-labelledby="ragstat-deploy-github-title">
                        <span class="ragstat-deploy-card__icon ragstat-deploy-card__github-icon" aria-hidden="true">
                            <?php self::render_github_icon('ragstat-github-icon'); ?>
                        </span>
                        <div class="ragstat-deploy-card__content">
                            <h3 id="ragstat-deploy-github-title"><?php esc_html_e('GitHub', 'ragnus-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Automatically trigger the GitHub Actions flow via webhook when the export is complete.', 'ragnus-static-publisher'); ?></p>
                        </div>
                        <div class="ragstat-deploy-card__footer">
                            <span class="ragstat-deploy-status <?php echo $github_configured ? 'is-ready' : 'is-pending'; ?>"><?php echo $github_configured ? __('Webhook configured', 'ragnus-static-publisher') : __('Configuration required', 'ragnus-static-publisher'); ?></span>
                        </div>
                    </section>
                    <?php self::render_deploy_settings($settings); ?>
                <?php elseif ($current_deploy_tab === 'cloudflare') : ?>
                    <?php
                    $deployment = Plugin::public_deployment_status();
                    $deployment_state = (string) ($deployment['state'] ?? '');
                    $deployment_labels = [
                        'waiting' => __('Waiting', 'ragnus-static-publisher'),
                        'dispatched' => __('Queued', 'ragnus-static-publisher'),
                        'deploying' => __('Running', 'ragnus-static-publisher'),
                        'completed' => __('Completed', 'ragnus-static-publisher'),
                        'failed' => __('Failed', 'ragnus-static-publisher'),
                    ];
                    $export_state = (string) (Plugin::public_status()['state'] ?? '');
                    $export_active = in_array($export_state, ['queued', 'running'], true);
                    ?>
                    <section class="ragstat-deploy-card" aria-labelledby="ragstat-deploy-cloudflare-title">
                        <span class="ragstat-deploy-card__icon ragstat-deploy-card__cloudflare-icon" aria-hidden="true">
                            <?php self::render_cloudflare_icon('ragstat-cloudflare-icon'); ?>
                        </span>
                        <div class="ragstat-deploy-card__content">
                            <h3 id="ragstat-deploy-cloudflare-title"><?php esc_html_e('Cloudflare', 'ragnus-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Publish static files to Cloudflare Workers Static Assets with a GitHub Actions workflow.', 'ragnus-static-publisher'); ?></p>
                        </div>
                        <div class="ragstat-deploy-card__footer">
                            <span class="ragstat-deploy-status <?php echo $github_configured ? 'is-ready' : 'is-pending'; ?>"><?php echo $github_configured ? esc_html($deployment_labels[$deployment_state] ?? __('Ready to deploy', 'ragnus-static-publisher')) : esc_html__('Configuration required', 'ragnus-static-publisher'); ?></span>
                            <a class="button button-primary" href="<?php echo esc_url('https://dash.cloudflare.com/'); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open Cloudflare', 'ragnus-static-publisher'); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                        </div>
                    </section>
                    <section class="ragstat-deploy-card ragstat-cloudflare-deployment-details" aria-labelledby="ragstat-cloudflare-deployment-title">
                        <div class="ragstat-deploy-card__content">
                            <h3 id="ragstat-cloudflare-deployment-title"><?php esc_html_e('Cloudflare Deploy', 'ragnus-static-publisher'); ?></h3>
                            <table class="widefat striped ragstat-status-table">
                                <tbody>
                                <tr><th><?php esc_html_e('Status', 'ragnus-static-publisher'); ?></th><td><?php echo esc_html($deployment_labels[$deployment_state] ?? __('Not deployed yet', 'ragnus-static-publisher')); ?></td></tr>
                                <tr><th><?php esc_html_e('Job ID', 'ragnus-static-publisher'); ?></th><td><code><?php echo esc_html((string) ($deployment['job_id'] ?? '—')); ?></code></td></tr>
                                <tr><th><?php esc_html_e('Last Cloudflare Deploy', 'ragnus-static-publisher'); ?></th><td><?php echo esc_html((string) ($deployment['updated_display'] ?? '—')); ?></td></tr>
                                <?php if (! empty($deployment['deployment_url'])) : ?><tr><th><?php esc_html_e('Deployment URL', 'ragnus-static-publisher'); ?></th><td><a href="<?php echo esc_url((string) $deployment['deployment_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) $deployment['deployment_url']); ?></a></td></tr><?php endif; ?>
                                <?php if (! empty($deployment['error'])) : ?><tr><th><?php esc_html_e('Error', 'ragnus-static-publisher'); ?></th><td><?php echo esc_html((string) $deployment['error']); ?></td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ragstat-deploy-card__footer">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="ragnus_static_export">
                                <?php wp_nonce_field('ragnus_static_export'); ?>
                                <?php submit_button($deployment_state === '' ? __('Deploy to Cloudflare', 'ragnus-static-publisher') : __('Deploy Again', 'ragnus-static-publisher'), 'primary', 'submit', false, (! $github_configured || $export_active) ? ['disabled' => 'disabled'] : []); ?>
                            </form>
                        </div>
                    </section>
                <?php elseif ($current_deploy_tab === 'sftp') : ?>
                    <section class="ragstat-deploy-card" aria-labelledby="ragstat-deploy-sftp-title">
                        <span class="ragstat-deploy-card__icon dashicons dashicons-upload" aria-hidden="true"></span>
                        <div class="ragstat-deploy-card__content">
                            <h3 id="ragstat-deploy-sftp-title"><?php esc_html_e('SFTP', 'ragnus-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Upload the generated static files directly to a remote server over an encrypted SFTP connection.', 'ragnus-static-publisher'); ?></p>
                        </div>
                        <div class="ragstat-deploy-card__footer">
                            <span class="ragstat-deploy-status <?php echo $sftp_configured && SFTP_Deployer::available() ? 'is-ready' : 'is-pending'; ?>">
                                <?php echo $sftp_configured ? (SFTP_Deployer::available() ? esc_html__('SFTP configured', 'ragnus-static-publisher') : esc_html__('SFTP unavailable on this server', 'ragnus-static-publisher')) : esc_html__('Configuration required', 'ragnus-static-publisher'); ?>
                            </span>
                        </div>
                    </section>
                    <?php self::render_sftp_settings($settings, $has_archive); ?>
                <?php else : ?>
                    <?php self::render_automation_settings($settings); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_github_icon(string $class_name): void
    {
        ?>
        <svg class="<?php echo esc_attr($class_name); ?>" data-ragstat-github-icon viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M12 .7a11.3 11.3 0 0 0-3.57 22c.57.1.78-.24.78-.55v-2.17c-3.16.69-3.83-1.34-3.83-1.34-.52-1.31-1.26-1.66-1.26-1.66-1.03-.7.08-.69.08-.69 1.14.08 1.74 1.17 1.74 1.17 1.01 1.73 2.66 1.23 3.3.94.1-.73.4-1.23.72-1.51-2.52-.29-5.17-1.26-5.17-5.59 0-1.23.44-2.24 1.17-3.03-.12-.29-.51-1.44.11-2.99 0 0 .95-.31 3.11 1.16a10.75 10.75 0 0 1 5.67 0c2.16-1.47 3.11-1.16 3.11-1.16.62 1.55.23 2.7.11 2.99.73.79 1.17 1.8 1.17 3.03 0 4.34-2.66 5.3-5.19 5.58.41.35.77 1.04.77 2.1v3.11c0 .31.2.66.78.55A11.3 11.3 0 0 0 12 .7Z"/>
        </svg>
        <?php
    }

    private static function render_cloudflare_icon(string $class_name): void
    {
        ?>
        <svg class="<?php echo esc_attr($class_name); ?>" data-ragstat-cloudflare-icon viewBox="54 3 50 23" role="img" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M88.1 24c.3-1 .2-2-.3-2.6-.5-.6-1.2-1-2.1-1.1l-17.4-.2c-.1 0-.2-.1-.3-.1-.1-.1-.1-.2 0-.3.1-.2.2-.3.4-.3l17.5-.2c2.1-.1 4.3-1.8 5.1-3.8l1-2.6c0-.1.1-.2 0-.3-1.1-5.1-5.7-8.9-11.1-8.9-5 0-9.3 3.2-10.8 7.7-1-.7-2.2-1.1-3.6-1-2.4.2-4.3 2.2-4.6 4.6-.1.6 0 1.2.1 1.8-3.9.1-7.1 3.3-7.1 7.3 0 .4 0 .7.1 1.1 0 .2.2.3.3.3h32.1c.2 0 .4-.1.4-.3l.3-1.1z"/>
            <path fill="currentColor" d="M93.6 12.8h-.5c-.1 0-.2.1-.3.2l-.7 2.4c-.3 1-.2 2 .3 2.6.5.6 1.2 1 2.1 1.1l3.7.2c.1 0 .2.1.3.1.1.1.1.2 0 .3-.1.2-.2.3-.4.3l-3.8.2c-2.1.1-4.3 1.8-5.1 3.8l-.2.9c-.1.1 0 .3.2.3h13.2c.2 0 .3-.1.3-.3.2-.8.4-1.7.4-2.6 0-5.2-4.3-9.5-9.5-9.5"/>
        </svg>
        <?php
    }

    private static function render_activity_tab(): void
    {
        $requested_page = isset($_GET['log_page']) ? absint($_GET['log_page']) : 1;
        $search = isset($_GET['log_search']) ? sanitize_text_field(wp_unslash((string) $_GET['log_search'])) : '';
        $activity = Activity_Log::page($requested_page, $search);
        $entries = $activity['entries'];
        ?>
        <h2><?php esc_html_e('Activity Logs', 'ragnus-static-publisher'); ?></h2>
        <?php if ($activity['job_id'] !== '') : ?>
            <p class="ragstat-activity-meta">
                <span><?php esc_html_e('Records of the last static transaction:', 'ragnus-static-publisher'); ?> <code><?php echo esc_html((string) $activity['job_id']); ?></code></span>
                <span><?php esc_html_e('Number of Records:', 'ragnus-static-publisher'); ?> <strong><?php echo esc_html((string) $activity['job_total']); ?></strong></span>
            </p>
        <?php endif; ?>
        <form class="ragstat-activity-search" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="ragnus-static-publisher">
            <input type="hidden" name="tab" value="activity">
            <label class="screen-reader-text" for="ragstat-log-search"><?php esc_html_e('Search Log Records', 'ragnus-static-publisher'); ?></label>
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <input id="ragstat-log-search" type="search" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Search source or static URL...', 'ragnus-static-publisher'); ?>">
            <button class="button button-primary" type="submit"><?php esc_html_e('Search', 'ragnus-static-publisher'); ?></button>
            <?php if ($search !== '') : ?>
                <a class="button" href="<?php echo esc_url(self::admin_page_url('activity')); ?>"><?php esc_html_e('Clear Search', 'ragnus-static-publisher'); ?></a>
            <?php endif; ?>
        </form>
        <?php if ($entries === []) : ?>
            <div class="ragstat-empty-state">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <p><?php echo esc_html($search === '' ? __('No export activity has been recorded yet.', 'ragnus-static-publisher') : __('No log entries matched your search.', 'ragnus-static-publisher')); ?></p>
            </div>
        <?php else : ?>
            <table class="widefat striped ragstat-activity-table">
                <thead><tr><th><?php esc_html_e('Date', 'ragnus-static-publisher'); ?></th><th class="ragstat-time-column"><?php esc_html_e('Time', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Source URL', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Static URL', 'ragnus-static-publisher'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <?php
                    $timestamp = strtotime((string) ($entry['time'] ?? ''));
                    ?>
                    <tr>
                        <td><?php echo $timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format'), $timestamp)); ?></td>
                        <td class="ragstat-time-column"><?php echo $timestamp === false ? '—' : esc_html(wp_date((string) get_option('time_format'), $timestamp)); ?></td>
                        <td><?php if (($entry['source_url'] ?? '') === '') : ?>—<?php else : ?><code><?php echo esc_html((string) $entry['source_url']); ?></code><?php endif; ?></td>
                        <td><?php if (($entry['static_path'] ?? '') === '') : ?>—<?php else : ?><code><?php echo esc_html((string) $entry['static_path']); ?></code><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($activity['total_pages'] > 1) : ?>
                <?php
                $pagination_url = $search === ''
                    ? self::admin_page_url('activity')
                    : add_query_arg('log_search', $search, self::admin_page_url('activity'));
                ?>
                <nav class="ragstat-pagination" aria-label="<?php echo esc_attr__('Activity log pages', 'ragnus-static-publisher'); ?>">
                    <span class="ragstat-pagination__summary"><?php echo esc_html(sprintf(__('%d records', 'ragnus-static-publisher'), (int) $activity['total'])); ?></span>
                    <?php
                    echo wp_kses_post((string) paginate_links([
                        'base' => add_query_arg('log_page', '%#%', $pagination_url),
                        'format' => '',
                        'current' => (int) $activity['page'],
                        'total' => (int) $activity['total_pages'],
                        'mid_size' => 2,
                        'end_size' => 1,
                        'prev_text' => __('‹ Previous', 'ragnus-static-publisher'),
                        'next_text' => __('Next ›', 'ragnus-static-publisher'),
                        'type' => 'list',
                    ]));
                    ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    private static function render_settings_tab(array $settings, string $requested_settings_tab): void
    {
        $settings_tabs = [
            'general' => __('General', 'ragnus-static-publisher'),
            'headless' => __('Headless CMS', 'ragnus-static-publisher'),
            'multilingual' => __('Multilingual', 'ragnus-static-publisher'),
        ];
        $current_settings_tab = isset($settings_tabs[$requested_settings_tab]) ? $requested_settings_tab : 'general';
        ?>
        <div class="ragstat-settings-header">
            <h2><?php esc_html_e('Static Site', 'ragnus-static-publisher'); ?></h2>
            <p><?php esc_html_e('Manage static generation settings.', 'ragnus-static-publisher'); ?></p>
        </div>
        <div class="ragstat-settings-layout">
            <nav class="ragstat-settings-tabs" aria-label="<?php echo esc_attr__('Static Site subsections', 'ragnus-static-publisher'); ?>">
                <?php foreach ($settings_tabs as $settings_tab => $label) : ?>
                    <a class="ragstat-settings-tab <?php echo $current_settings_tab === $settings_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::settings_page_url($settings_tab)); ?>" <?php echo $current_settings_tab === $settings_tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="ragstat-settings-panel">
                <?php if ($current_settings_tab === 'general') : ?>
                    <?php self::render_general_settings($settings); ?>
                <?php elseif ($current_settings_tab === 'headless') : ?>
                    <?php self::render_headless_settings($settings); ?>
                <?php else : ?>
                    <?php self::render_language_settings(Plugin::language_settings()); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_seo_tab(string $requested_seo_tab, array $settings): void
    {
        $seo_tabs = [
            'plugins' => [__('SEO Plugins', 'ragnus-static-publisher'), 'dashicons-admin-plugins'],
            'audit' => [__('SEO Audit', 'ragnus-static-publisher'), 'dashicons-yes-alt'],
            'sitemaps' => [__('Sitemaps', 'ragnus-static-publisher'), 'dashicons-networking'],
            'redirects' => [__('Redirects', 'ragnus-static-publisher'), 'dashicons-randomize'],
            'indexing' => [__('Indexing', 'ragnus-static-publisher'), 'dashicons-visibility'],
            'performance' => [__('Performance', 'ragnus-static-publisher'), 'dashicons-performance'],
        ];
        $current_seo_tab = isset($seo_tabs[$requested_seo_tab]) ? $requested_seo_tab : 'plugins';
        ?>
        <div class="ragstat-seo-header">
            <h2><?php esc_html_e('SEO', 'ragnus-static-publisher'); ?></h2>
            <p><?php esc_html_e('Manage SEO plugin output and multilingual search engine signals in the static site.', 'ragnus-static-publisher'); ?></p>
        </div>
        <div class="ragstat-seo-layout">
            <nav class="ragstat-seo-tabs" aria-label="<?php echo esc_attr__('SEO', 'ragnus-static-publisher'); ?>">
                <?php foreach ($seo_tabs as $seo_tab => [$label, $icon]) : ?>
                    <a class="ragstat-seo-tab <?php echo $current_seo_tab === $seo_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::seo_page_url($seo_tab)); ?>" <?php echo $current_seo_tab === $seo_tab ? 'aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <span><?php echo esc_html($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="ragstat-seo-panel">
                <?php if ($current_seo_tab === 'plugins') : ?>
                    <?php self::render_seo_plugins(Plugin::seo_plugin_settings()); ?>
                <?php else : ?>
                    <?php self::render_seo_toolkit_settings($current_seo_tab, $settings); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_seo_toolkit_settings(string $section, array $settings): void
    {
        $option_name = Plugin::SEO_SETTINGS_KEY;
        $titles = [
            'audit' => [__('SEO Audit', 'ragnus-static-publisher'), __('Validate metadata, canonical URLs, structured data, images, links, and multilingual signals after every export.', 'ragnus-static-publisher')],
            'sitemaps' => [__('Advanced Sitemaps', 'ragnus-static-publisher'), __('Generate an indexable canonical sitemap with reliable modification dates, images, and language alternatives.', 'ragnus-static-publisher')],
            'redirects' => [__('Redirects', 'ragnus-static-publisher'), __('Export custom redirects and WordPress old slugs while reporting redirect chains and loops.', 'ragnus-static-publisher')],
            'indexing' => [__('Indexing Controls', 'ragnus-static-publisher'), __('Control indexing for HTML and non-HTML files and optionally notify IndexNow after automatic deployment.', 'ragnus-static-publisher')],
            'performance' => [__('Performance Audit', 'ragnus-static-publisher'), __('Report large files, large HTML documents, and render-blocking resources in the static package.', 'ragnus-static-publisher')],
        ];
        [$title, $description] = $titles[$section];
        ?>
        <form class="ragstat-settings-form ragstat-seo-toolkit-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static_seo'); ?>
            <input type="hidden" name="<?php echo esc_attr($option_name); ?>[_section]" value="<?php echo esc_attr($section); ?>">
            <section class="ragstat-language-card">
                <div class="ragstat-language-card__heading"><span class="dashicons dashicons-search" aria-hidden="true"></span><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p></div></div>
                <div class="ragstat-seo-toolkit-fields">
                    <?php if ($section === 'audit') : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'audit_enabled', __('Create SEO audit report', 'ragnus-static-publisher'), __('Writes JSON and export diagnostics for every generated page.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'audit_html_report', __('Include readable HTML report', 'ragnus-static-publisher'), __('Adds ragnus-seo-report.html to the ZIP with noindex protection.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'canonical_fallback', __('Add missing canonical URLs', 'ragnus-static-publisher'), __('Uses the final static URL only when the page has no canonical element.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'schema_validation', __('Validate JSON-LD structured data', 'ragnus-static-publisher'), __('Reports invalid JSON-LD without inventing content or schema properties.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'multilingual_validation', __('Validate multilingual signals', 'ragnus-static-publisher'), __('Checks missing and non-reciprocal hreflang targets.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'image_audit', __('Audit image SEO', 'ragnus-static-publisher'), __('Reports missing alt attributes and explicit image dimensions.', 'ragnus-static-publisher')); ?>
                    <?php elseif ($section === 'sitemaps') : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'advanced_sitemap', __('Generate Ragnus sitemap', 'ragnus-static-publisher'), __('Creates ragnus-sitemap.xml and adds it to robots.txt.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_lastmod', __('Include accurate last modified dates', 'ragnus-static-publisher'), __('Uses the WordPress content modification time when available.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_images', __('Include images', 'ragnus-static-publisher'), __('Adds discoverable page images with absolute static URLs.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_hreflang', __('Include language alternatives', 'ragnus-static-publisher'), __('Adds existing hreflang relationships to sitemap entries.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_video', __('Generate video sitemap', 'ragnus-static-publisher'), __('Creates a separate sitemap only for videos with complete title, description, thumbnail, and upload date metadata.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_news', __('Generate Google News sitemap', 'ragnus-static-publisher'), __('Creates a separate sitemap for posts published during the last two days. Enable only for eligible news sites.', 'ragnus-static-publisher')); ?>
                    <?php elseif ($section === 'redirects') : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'redirect_old_slugs', __('Redirect WordPress old slugs', 'ragnus-static-publisher'), __('Creates permanent redirects for published content with saved old slugs.', 'ragnus-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'redirect_import_plugins', __('Import redirect plugin rules', 'ragnus-static-publisher'), __('Imports compatible non-regex redirects from Redirection and Rank Math.', 'ragnus-static-publisher')); ?>
                        <label class="ragstat-seo-toolkit-field"><strong><?php esc_html_e('Custom Redirect Rules', 'ragnus-static-publisher'); ?></strong><textarea name="<?php echo esc_attr($option_name); ?>[redirect_rules]" rows="10" placeholder="/old-path/ /new-path/ 301"><?php echo esc_textarea((string) $settings['redirect_rules']); ?></textarea><span><?php esc_html_e('Enter one source, target, and optional status code per line. Supported codes: 301, 302, 303, 307, 308.', 'ragnus-static-publisher'); ?></span></label>
                    <?php elseif ($section === 'indexing') : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'site_noindex', __('Noindex the entire static output', 'ragnus-static-publisher'), __('Use only for staging or private static deployments. This blocks indexing through HTML and response headers.', 'ragnus-static-publisher')); ?>
                        <label class="ragstat-seo-toolkit-field"><strong><?php esc_html_e('Noindex Paths', 'ragnus-static-publisher'); ?></strong><textarea name="<?php echo esc_attr($option_name); ?>[noindex_paths]" rows="7" placeholder="/private/&#10;/landing-draft/*"><?php echo esc_textarea((string) $settings['noindex_paths']); ?></textarea><span><?php esc_html_e('One path prefix or wildcard pattern per line.', 'ragnus-static-publisher'); ?></span></label>
                        <label class="ragstat-seo-toolkit-field"><strong><?php esc_html_e('X-Robots-Tag Rules', 'ragnus-static-publisher'); ?></strong><textarea name="<?php echo esc_attr($option_name); ?>[x_robots_rules]" rows="7" placeholder="*.pdf|noindex"><?php echo esc_textarea((string) $settings['x_robots_rules']); ?></textarea><span><?php esc_html_e('Use pattern|directives format for non-HTML files.', 'ragnus-static-publisher'); ?></span></label>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'indexnow_enabled', __('Notify IndexNow after automatic deployment', 'ragnus-static-publisher'), __('Submits only added, changed, or deleted URLs after webhook or automatic SFTP deployment.', 'ragnus-static-publisher')); ?>
                        <label class="ragstat-seo-toolkit-field"><strong><?php esc_html_e('IndexNow Key', 'ragnus-static-publisher'); ?></strong><input type="password" autocomplete="new-password" name="<?php echo esc_attr($option_name); ?>[indexnow_key]" value="" placeholder="<?php echo (string) $settings['indexnow_key'] !== '' ? esc_attr__('Configured — leave blank to keep', 'ragnus-static-publisher') : ''; ?>"><span><?php esc_html_e('Use an 8–128 character key containing letters, numbers, or hyphens.', 'ragnus-static-publisher'); ?></span></label>
                    <?php else : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'performance_audit', __('Create performance report', 'ragnus-static-publisher'), __('Adds ragnus-performance-report.json to the static package.', 'ragnus-static-publisher')); ?>
                        <div class="ragstat-seo-toolkit-grid">
                            <label class="ragstat-seo-toolkit-field"><strong><?php esc_html_e('Large HTML Threshold (KB)', 'ragnus-static-publisher'); ?></strong><input type="number" min="50" max="5000" name="<?php echo esc_attr($option_name); ?>[large_html_kb]" value="<?php echo esc_attr((string) $settings['large_html_kb']); ?>"></label>
                            <label class="ragstat-seo-toolkit-field"><strong><?php esc_html_e('Large Asset Threshold (KB)', 'ragnus-static-publisher'); ?></strong><input type="number" min="100" max="20000" name="<?php echo esc_attr($option_name); ?>[large_asset_kb]" value="<?php echo esc_attr((string) $settings['large_asset_kb']); ?>"></label>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php submit_button(__('Save SEO Settings', 'ragnus-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_seo_setting_toggle(string $option_name, array $settings, string $key, string $label, string $description): void
    {
        $field_id = 'ragstat-seo-' . str_replace('_', '-', $key);
        ?>
        <div class="ragstat-hide-toggle-row">
            <div><label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label><p><?php echo esc_html($description); ?></p></div>
            <label class="ragstat-switch" aria-label="<?php echo esc_attr($label); ?>"><input id="<?php echo esc_attr($field_id); ?>" type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked((string) ($settings[$key] ?? '0'), '1'); ?>><span aria-hidden="true"></span></label>
        </div>
        <?php
    }

    private static function render_seo_plugins(array $settings): void
    {
        $option_name = Plugin::SEO_PLUGIN_SETTINGS_KEY;
        $metadata_outputs = static function (string $prefix): array {
            return [
                $prefix . '_metadata_pages' => [__('Page Metadata', 'ragnus-static-publisher'), __('Keep SEO metadata on WordPress pages.', 'ragnus-static-publisher')],
                $prefix . '_metadata_posts' => [__('Post Metadata', 'ragnus-static-publisher'), __('Keep SEO metadata on WordPress posts.', 'ragnus-static-publisher')],
                $prefix . '_metadata_custom_post_types' => [__('Custom Post Type Metadata', 'ragnus-static-publisher'), __('Keep SEO metadata on public custom post types such as products and portfolios.', 'ragnus-static-publisher')],
                $prefix . '_metadata_archives' => [__('Archive & Taxonomy Metadata', 'ragnus-static-publisher'), __('Keep SEO metadata on the posts page, archives, categories, tags, author pages and other listing views.', 'ragnus-static-publisher')],
            ];
        };
        $additional_outputs = static function (string $prefix, string $label) use ($metadata_outputs): array {
            return array_merge($metadata_outputs($prefix), [
                $prefix . '_schema' => [__('Schema Structured Data', 'ragnus-static-publisher'), sprintf(__('Keep %s JSON-LD data, convert its URLs to the live domain, and adapt SearchAction to static search.', 'ragnus-static-publisher'), $label)],
                $prefix . '_sitemaps' => [__('XML Sitemaps', 'ragnus-static-publisher'), sprintf(__('Copy %s sitemap and its linked sitemap files into the static package.', 'ragnus-static-publisher'), $label)],
                $prefix . '_robots' => [__('Robots.txt', 'ragnus-static-publisher'), sprintf(__('Copy %s robots.txt rules and point sitemap declarations to the live static domain.', 'ragnus-static-publisher'), $label)],
            ]);
        };
        $plugins = [
            [
                'id' => 'rank-math',
                'prefix' => 'rank_math',
                'label' => 'Rank Math SEO',
                'active' => defined('RANK_MATH_VERSION'),
                'version' => defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : '',
                'description' => __('Choose which Rank Math outputs will be included in the generated static site.', 'ragnus-static-publisher'),
                'outputs' => array_merge($metadata_outputs('rank_math'), [
                    'rank_math_schema' => [__('Schema Structured Data', 'ragnus-static-publisher'), __('Keep Rank Math JSON-LD data, convert its URLs to the live domain, and adapt SearchAction to static search.', 'ragnus-static-publisher')],
                    'rank_math_sitemaps' => [__('XML Sitemaps', 'ragnus-static-publisher'), __('Copy the Rank Math sitemap index, child sitemaps and sitemap stylesheet into the static package.', 'ragnus-static-publisher')],
                    'rank_math_robots' => [__('Robots.txt', 'ragnus-static-publisher'), __('Copy Rank Math robots.txt rules and point the sitemap declaration to the live static domain.', 'ragnus-static-publisher')],
                ]),
            ],
            [
                'id' => 'aioseo',
                'prefix' => 'aioseo',
                'label' => 'All in One SEO',
                'active' => defined('AIOSEO_VERSION') || function_exists('aioseo'),
                'version' => defined('AIOSEO_VERSION') ? (string) AIOSEO_VERSION : '',
                'description' => __('Choose which All in One SEO outputs will be included in the generated static site.', 'ragnus-static-publisher'),
                'outputs' => array_merge($metadata_outputs('aioseo'), [
                    'aioseo_schema' => [__('Schema Structured Data', 'ragnus-static-publisher'), __('Keep All in One SEO JSON-LD data, convert its URLs to the live domain, and adapt SearchAction to static search.', 'ragnus-static-publisher')],
                    'aioseo_sitemaps' => [__('XML Sitemaps', 'ragnus-static-publisher'), __('Copy the All in One SEO sitemap index, child sitemaps and sitemap stylesheet into the static package.', 'ragnus-static-publisher')],
                    'aioseo_robots' => [__('Robots.txt', 'ragnus-static-publisher'), __('Copy All in One SEO robots.txt rules and point sitemap declarations to the live static domain.', 'ragnus-static-publisher')],
                ]),
            ],
            [
                'id' => 'seopress',
                'prefix' => 'seopress',
                'label' => 'SEOPress',
                'active' => defined('SEOPRESS_VERSION'),
                'version' => defined('SEOPRESS_VERSION') ? (string) SEOPRESS_VERSION : '',
                'description' => __('Choose which SEOPress outputs will be included in the generated static site.', 'ragnus-static-publisher'),
                'outputs' => array_merge($metadata_outputs('seopress'), [
                    'seopress_schema' => [__('Schema Structured Data', 'ragnus-static-publisher'), __('Keep SEOPress JSON-LD data, convert its URLs to the live domain, and adapt SearchAction to static search.', 'ragnus-static-publisher')],
                    'seopress_sitemaps' => [__('XML Sitemaps', 'ragnus-static-publisher'), __('Copy the SEOPress sitemap index, child sitemaps and sitemap stylesheets into the static package.', 'ragnus-static-publisher')],
                    'seopress_robots' => [__('Robots.txt', 'ragnus-static-publisher'), __('Copy SEOPress robots.txt rules and point sitemap declarations to the live static domain.', 'ragnus-static-publisher')],
                ]),
            ],
            [
                'id' => 'surerank',
                'prefix' => 'surerank',
                'label' => 'SureRank SEO',
                'active' => defined('SURERANK_VERSION'),
                'version' => defined('SURERANK_VERSION') ? (string) SURERANK_VERSION : '',
                'description' => sprintf(__('Choose which %s outputs will be included in the generated static site.', 'ragnus-static-publisher'), 'SureRank SEO'),
                'outputs' => $additional_outputs('surerank', 'SureRank SEO'),
            ],
            [
                'id' => 'seo-framework',
                'prefix' => 'seo_framework',
                'label' => 'The SEO Framework',
                'active' => defined('THE_SEO_FRAMEWORK_VERSION'),
                'version' => defined('THE_SEO_FRAMEWORK_VERSION') ? (string) THE_SEO_FRAMEWORK_VERSION : '',
                'description' => sprintf(__('Choose which %s outputs will be included in the generated static site.', 'ragnus-static-publisher'), 'The SEO Framework'),
                'outputs' => $additional_outputs('seo_framework', 'The SEO Framework'),
            ],
            [
                'id' => 'yoast',
                'prefix' => 'yoast',
                'label' => 'Yoast SEO',
                'active' => defined('WPSEO_VERSION'),
                'version' => defined('WPSEO_VERSION') ? (string) WPSEO_VERSION : '',
                'description' => sprintf(__('Choose which %s outputs will be included in the generated static site.', 'ragnus-static-publisher'), 'Yoast SEO'),
                'outputs' => $additional_outputs('yoast', 'Yoast SEO'),
            ],
        ];
        $plugins = array_merge(
            array_values(array_filter($plugins, static fn (array $plugin): bool => (bool) $plugin['active'])),
            array_values(array_filter($plugins, static fn (array $plugin): bool => ! $plugin['active']))
        );
        $active_plugins = array_values(array_filter($plugins, static fn (array $plugin): bool => (bool) $plugin['active']));
        ?>
        <form class="ragstat-settings-form ragstat-seo-plugins-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static_seo_plugins'); ?>
            <?php if (count($active_plugins) === 1) : ?>
                <div class="ragstat-seo-plugin-overview is-ready">
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <span><strong><?php esc_html_e('Detected SEO plugin:', 'ragnus-static-publisher'); ?></strong> <?php echo esc_html((string) $active_plugins[0]['label']); ?></span>
                </div>
            <?php elseif (count($active_plugins) > 1) : ?>
                <div class="ragstat-seo-plugin-overview is-warning">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <span><?php esc_html_e('Multiple active SEO plugins were detected. Review their output settings to prevent duplicate metadata.', 'ragnus-static-publisher'); ?></span>
                </div>
            <?php else : ?>
                <div class="ragstat-seo-plugin-overview">
                    <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                    <span><?php esc_html_e('No active SEO plugin was detected. Saved settings will be used when a supported plugin is activated.', 'ragnus-static-publisher'); ?></span>
                </div>
            <?php endif; ?>
            <?php foreach ($plugins as $index => $plugin) : ?>
                <?php self::render_seo_plugin_card($plugin, $option_name, $settings, (bool) $plugin['active'] && $index === 0); ?>
            <?php endforeach; ?>
            <div class="ragstat-seo-save-bar">
                <?php submit_button(__('Save SEO Plugin Settings', 'ragnus-static-publisher')); ?>
            </div>
        </form>
        <?php
    }

    private static function render_seo_plugin_card(array $plugin, string $option_name, array $settings, bool $expanded): void
    {
        $id = (string) $plugin['id'];
        $prefix = (string) $plugin['prefix'];
        $label = (string) $plugin['label'];
        $active = (bool) $plugin['active'];
        $version = (string) $plugin['version'];
        $outputs = (array) $plugin['outputs'];
        $selected = count(array_filter(array_keys($outputs), static fn (string $key): bool => (string) ($settings[$key] ?? '0') === '1'));
        $panel_id = 'ragstat-' . $id . '-settings';
        $metadata_outputs = array_filter($outputs, static fn (string $key): bool => str_contains($key, '_metadata_'), ARRAY_FILTER_USE_KEY);
        $technical_outputs = array_diff_key($outputs, $metadata_outputs);
        ?>
        <section class="ragstat-seo-plugin-card <?php echo $expanded ? 'is-expanded' : ''; ?>" aria-labelledby="ragstat-<?php echo esc_attr($id); ?>-title" data-ragstat-seo-card>
            <div class="ragstat-seo-plugin-card__heading">
                <button class="ragstat-seo-plugin-card__toggle" type="button" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr($panel_id); ?>" data-ragstat-seo-toggle>
                    <span class="ragstat-seo-plugin-card__icon" aria-hidden="true">
                        <?php self::render_seo_plugin_icon($id); ?>
                    </span>
                    <span class="ragstat-seo-plugin-card__identity">
                        <strong id="ragstat-<?php echo esc_attr($id); ?>-title"><?php echo esc_html($label); ?></strong>
                        <small><?php echo esc_html((string) $plugin['description']); ?></small>
                    </span>
                    <span class="dashicons dashicons-arrow-down-alt2 ragstat-seo-plugin-card__chevron" aria-hidden="true"></span>
                </button>
                <div class="ragstat-seo-plugin-card__summary">
                    <span class="ragstat-deploy-status <?php echo $active ? 'is-ready' : 'is-pending'; ?>">
                        <?php echo $active && $version !== '' ? esc_html(sprintf(__('Active — version %s', 'ragnus-static-publisher'), $version)) : ($active ? esc_html__('Active', 'ragnus-static-publisher') : esc_html__('Not active', 'ragnus-static-publisher')); ?>
                    </span>
                    <span class="ragstat-seo-output-count" data-ragstat-output-count><strong><?php echo esc_html((string) $selected); ?></strong> / <?php echo esc_html((string) count($outputs)); ?></span>
                    <label class="ragstat-seo-plugin-enable">
                        <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($prefix); ?>_enabled]" value="1" data-ragstat-seo-master <?php checked((string) ($settings[$prefix . '_enabled'] ?? '0'), '1'); ?>>
                        <span><?php echo esc_html(sprintf(__('Include %s outputs in the static site', 'ragnus-static-publisher'), $label)); ?></span>
                    </label>
                </div>
            </div>
            <div class="ragstat-seo-plugin-card__body" id="<?php echo esc_attr($panel_id); ?>" data-ragstat-seo-panel <?php echo $expanded ? '' : 'hidden'; ?>>
                <p class="ragstat-seo-plugin-card__master-note"><?php echo esc_html(sprintf(__('When disabled, %s metadata and schema outputs are removed and its sitemap and robots files are not exported.', 'ragnus-static-publisher'), $label)); ?></p>
                <?php self::render_seo_output_group(__('Metadata', 'ragnus-static-publisher'), $metadata_outputs, $option_name, $settings); ?>
                <?php self::render_seo_output_group(__('Technical SEO', 'ragnus-static-publisher'), $technical_outputs, $option_name, $settings); ?>
                <?php if (! $active) : ?>
                    <p class="ragstat-language-card__note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php echo esc_html(sprintf(__('These settings are preserved, but no %s output will be exported until the plugin is active.', 'ragnus-static-publisher'), $label)); ?></p>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Render monochrome marks sourced from each plugin's official SVG artwork.
     */
    private static function render_seo_plugin_icon(string $plugin_id): void
    {
        $icons = [
            'rank-math' => '<svg viewBox="0 0 462.03 462.03" focusable="false"><path d="m462 234.84-76.17 3.43 13.43 21-127 81.18-126-52.93-146.26 60.97 10.14 24.34 136.1-56.71 128.57 54 138.69-88.61 13.43 21z"/><path d="M54.1 312.78l92.18-38.41 4.49 1.89v-54.58H54.1zm210.9-223.57v235.05l7.26 3 89.43-57.05v-181zM159.56 280l96.67 40.62V155.43h-96.67z"/></svg>',
            'aioseo' => '<svg viewBox="0 0 20 20" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M10 20a10 10 0 1 0 0-20 10 10 0 0 0 0 20ZM8.408 3.66a.54.54 0 0 0-.617-.222 6.7 6.7 0 0 0-.767.326.55.55 0 0 0-.28.603l.171.868a.62.62 0 0 1-.22.594c-.274.227-.526.484-.752.769a.6.6 0 0 1-.582.227l-.85-.172a.54.54 0 0 0-.59.288 7.8 7.8 0 0 0-.316.784.55.55 0 0 0 .219.629l.722.49a.63.63 0 0 1 .256.579 5.5 5.5 0 0 0 .003 1.087.63.63 0 0 1-.255.58l-.72.491a.55.55 0 0 0-.218.63c.09.267.197.529.32.783a.54.54 0 0 0 .589.286l.85-.175a.6.6 0 0 1 .582.225c.223.28.475.537.754.768a.62.62 0 0 1 .222.593l-.168.868a.55.55 0 0 0 .283.602c.247.123.504.23.768.323.38.133.913-.344 1.307-.697a.93.93 0 0 0 .315-.684v-1.452a2.7 2.7 0 0 1-2.03-2.627V9.432c0-.117.093-.212.208-.212h.721V7.703a.372.372 0 1 1 .743 0V9.22h1.95V7.703a.372.372 0 1 1 .743 0V9.22h.721c.115 0 .208.095.208.212v1.541a2.7 2.7 0 0 1-2.14 2.652v1.467c0 .268.124.519.324.693.401.35.944.823 1.322.689.262-.092.518-.201.767-.326a.55.55 0 0 0 .28-.602l-.171-.868a.62.62 0 0 1 .22-.594c.274-.228.526-.485.752-.77a.6.6 0 0 1 .582-.226l.85.171a.54.54 0 0 0 .59-.288c.12-.253.226-.515.316-.784a.55.55 0 0 0-.219-.629l-.722-.49a.63.63 0 0 1-.256-.578 5.5 5.5 0 0 0-.003-1.087.63.63 0 0 1 .255-.58l.72-.492a.55.55 0 0 0 .218-.63 7 7 0 0 0-.32-.783.54.54 0 0 0-.589-.285l-.85.175a.6.6 0 0 1-.582-.225 5.4 5.4 0 0 0-.754-.768.62.62 0 0 1-.222-.594l.168-.868a.55.55 0 0 0-.283-.602 7.3 7.3 0 0 0-.768-.323.54.54 0 0 0-.616.224l-.48.737a.6.6 0 0 1-.567.261 5.2 5.2 0 0 0-1.065.003.6.6 0 0 1-.568-.259l-.482-.735Z"/></svg>',
            'seopress' => '<svg viewBox="0 0 50 50" focusable="false"><g transform="translate(-10.312 25.104) rotate(-45)"><path d="M38.121 32.49h-7.178a.777.777 0 0 0 0 1.551h7.178a.777.777 0 0 0 0-1.551ZM30.943 1.641h7.178a.777.777 0 0 0 0-1.551h-7.178a.777.777 0 0 0 0 1.551Z" transform="translate(-16.332 -2.549)"/><path d="M46.264.11a6.5 6.5 0 0 0-6.45 5.722h-8.831a.777.777 0 0 0 0 1.551h8.831A6.5 6.5 0 1 0 46.264.11Zm0 11.406a4.906 4.906 0 1 1 4.906-4.907 4.906 4.906 0 0 1-4.906 4.907Z" transform="translate(-16.357 18.394)"/></g></svg>',
            'surerank' => '<svg viewBox="0 0 36 36" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.519 35.5c9.675 0 17.519-7.835 17.519-17.5S27.194.5 17.519.5 0 8.335 0 18s7.844 17.5 17.519 17.5Zm.075-26.25c-1.406 0-3.353.804-4.348 1.795l-2.701 2.692H24.01l4.503-4.487H17.594Zm4.175 15.705c-.995.991-2.942 1.795-4.348 1.795H6.502l4.503-4.487H24.47l-2.701 2.692Zm4.376-8.974H8.298l-.843.841c-1.996 1.795-1.404 3.197 1.392 3.197h17.895l.843-.841c1.977-1.784 1.356-3.197-1.44-3.197Z"/></svg>',
            'seo-framework' => '<svg viewBox="55 55 165 165" focusable="false"><path d="M202.307 159.892H180.58c-.52 0-.693-.116-.693-.693v-21.727c0-.578-.173-.693-.693-.693H160.47c-.52 0-.693.116-.693.693v21.727c0 .52-.116.693-.693.693h-21.727c-.578 0-.693.173-.693.693v18.723c0 .52.116.693.693.693h21.727c.52 0 .693.116.693.693v21.727c0 .578.173.693.693.693h18.723c.52 0 .693-.116.693-.693v-21.727c0-.52.116-.693.693-.693h21.727c.578 0 .693-.173.693-.693v-18.723c.001-.52-.115-.693-.692-.693Z"/><path d="m200.564 96.085-26.143-23.924a1.5 1.5 0 0 0-1.011-.392H79.499A7.5 7.5 0 0 0 72 79.268v116.465a7.5 7.5 0 0 0 7.499 7.499h70.974v-12.944c0-.809 0-.809-.867-.809H86.562c-.867 0-.751.058-.751-.751V86.447c0-.867-.116-.809.809-.809h82.229c.404 0 .693.058.982.347l19.185 17.567c.405.347.52.693.52 1.156v45.766h12.713c.751 0 .751 0 .751-.809v-48.047c0-2.105-.884-4.113-2.436-5.533Z"/></svg>',
            'yoast' => '<svg viewBox="45 0 425 500" focusable="false"><path d="M74.4 337.3v34.9c21.6-.9 38.5-8 52.8-22.5s27.4-38 39.9-72.9l92.6-248h-44.8L140.3 236l-37-116.2h-41l54.4 139.8a57.54 57.54 0 0 1 0 41.8c-5.5 14.2-15.4 30.9-42.3 35.9Z"/><circle cx="368.33" cy="124.68" r="97.34"/><path d="M294.78 254.75a63.6 63.6 0 1 0-62.84 110.6 63.6 63.6 0 0 0 62.84-110.6ZM222.31 450.07A38.18 38.18 0 1 0 146 450a38.18 38.18 0 0 0 76.31.07Z"/></svg>',
        ];

        // All paths intentionally inherit one UI color instead of brand colors.
        echo $icons[$plugin_id] ?? $icons['rank-math']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function render_seo_output_group(string $legend, array $outputs, string $option_name, array $settings): void
    {
        ?>
        <fieldset class="ragstat-seo-output-group">
            <legend><?php echo esc_html($legend); ?></legend>
            <div class="ragstat-seo-output-list">
                <?php foreach ($outputs as $key => [$output_label, $description]) : ?>
                    <label class="ragstat-seo-output-option">
                        <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($key); ?>]" value="1" data-ragstat-seo-output <?php checked((string) ($settings[$key] ?? '0'), '1'); ?>>
                        <span><strong><?php echo esc_html($output_label); ?> <span class="dashicons dashicons-info-outline" aria-hidden="true"></span></strong><small><?php echo esc_html($description); ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    private static function render_general_settings(array $settings): void
    {
        ?>
        <form class="ragstat-settings-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="general">
            <table class="form-table" role="presentation">
                <tr><th><label for="ragstat-target"><?php esc_html_e('Live Site Address', 'ragnus-static-publisher'); ?></label></th><td><input class="regular-text" id="ragstat-target" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[target_url]" value="<?php echo esc_attr((string) $settings['target_url']); ?>"><p class="description"><?php esc_html_e('Example: https://example.com', 'ragnus-static-publisher'); ?></p></td></tr>
                <tr><th><label for="ragstat-limit"><?php esc_html_e('Most URLs', 'ragnus-static-publisher'); ?></label></th><td><input id="ragstat-limit" type="number" min="10" max="20000" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[maximum_urls]" value="<?php echo esc_attr((string) $settings['maximum_urls']); ?>"></td></tr>
                <tr><th><label for="ragstat-excluded"><?php esc_html_e('Excluded Paths', 'ragnus-static-publisher'); ?></label></th><td><textarea class="large-text code" rows="7" id="ragstat-excluded" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[excluded_paths]"><?php echo esc_textarea((string) $settings['excluded_paths']); ?></textarea><p class="description"><?php esc_html_e('One path prefix per line.', 'ragnus-static-publisher'); ?></p></td></tr>
            </table>
            <?php submit_button(__('Save General Settings', 'ragnus-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_headless_settings(array $settings): void
    {
        $option_name = Plugin::SETTINGS_KEY;
        ?>
        <form class="ragstat-settings-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static'); ?>
            <input type="hidden" name="<?php echo esc_attr($option_name); ?>[_section]" value="headless">
            <div class="notice notice-info inline ragstat-headless-notice">
                <p><strong><?php esc_html_e('Headless + Static Publisher', 'ragnus-static-publisher'); ?></strong></p>
                <p><?php esc_html_e('Visitors cannot open the WordPress theme frontend. Signed internal export requests can still render the homepage, pages, design assets, and menus for the static site.', 'ragnus-static-publisher'); ?></p>
            </div>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e('Headless CMS Mode', 'ragnus-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_enabled]" value="1" <?php checked((string) ($settings['headless_enabled'] ?? '0'), '1'); ?>> <?php esc_html_e('Protect the WordPress theme frontend', 'ragnus-static-publisher'); ?></label><p class="description"><?php esc_html_e('WordPress Admin, REST API, media files, cron, and signed static export requests remain available.', 'ragnus-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="ragstat-headless-behavior"><?php esc_html_e('Visitor Response', 'ragnus-static-publisher'); ?></label></th>
                    <td><select id="ragstat-headless-behavior" name="<?php echo esc_attr($option_name); ?>[headless_frontend_behavior]">
                        <option value="404" <?php selected((string) ($settings['headless_frontend_behavior'] ?? '404'), '404'); ?>><?php esc_html_e('404 Not Found (recommended)', 'ragnus-static-publisher'); ?></option>
                        <option value="redirect" <?php selected((string) ($settings['headless_frontend_behavior'] ?? '404'), 'redirect'); ?>><?php esc_html_e('307 Redirect to frontend', 'ragnus-static-publisher'); ?></option>
                        <option value="410" <?php selected((string) ($settings['headless_frontend_behavior'] ?? '404'), '410'); ?>><?php esc_html_e('410 Gone', 'ragnus-static-publisher'); ?></option>
                    </select></td>
                </tr>
                <tr>
                    <th><label for="ragstat-headless-frontend-url"><?php esc_html_e('Frontend Address', 'ragnus-static-publisher'); ?></label></th>
                    <td><input class="regular-text" id="ragstat-headless-frontend-url" type="url" name="<?php echo esc_attr($option_name); ?>[headless_frontend_url]" value="<?php echo esc_attr((string) ($settings['headless_frontend_url'] ?? '')); ?>" placeholder="https://www.example.com"><p class="description"><?php esc_html_e('Required only when visitors should be redirected. It must use a different origin than WordPress.', 'ragnus-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Redirect Paths', 'ragnus-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_preserve_path]" value="1" <?php checked((string) ($settings['headless_preserve_path'] ?? '1'), '1'); ?>> <?php esc_html_e('Preserve the requested path when redirecting', 'ragnus-static-publisher'); ?></label><p class="description"><?php esc_html_e('Example: /about/ redirects to the frontend /about/. Query parameters are not forwarded.', 'ragnus-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Editor Preview', 'ragnus-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_allow_authenticated_preview]" value="1" <?php checked((string) ($settings['headless_allow_authenticated_preview'] ?? '1'), '1'); ?>> <?php esc_html_e('Allow signed-in editors to view the WordPress theme', 'ragnus-static-publisher'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('GraphQL', 'ragnus-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_allow_graphql]" value="1" <?php checked((string) ($settings['headless_allow_graphql'] ?? '0'), '1'); ?>> <?php esc_html_e('Allow the /graphql endpoint when WPGraphQL is installed', 'ragnus-static-publisher'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('CMS Indexing', 'ragnus-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_noindex]" value="1" <?php checked((string) ($settings['headless_noindex'] ?? '1'), '1'); ?>> <?php esc_html_e('Block indexing on the WordPress CMS origin', 'ragnus-static-publisher'); ?></label><p class="description"><?php esc_html_e('Returns noindex headers, disables the CMS sitemap, and disallows crawling through robots.txt without changing the generated static site.', 'ragnus-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Legacy Publishing', 'ragnus-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_disable_xmlrpc]" value="1" <?php checked((string) ($settings['headless_disable_xmlrpc'] ?? '1'), '1'); ?>> <?php esc_html_e('Disable XML-RPC requests', 'ragnus-static-publisher'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Comments and Pingbacks', 'ragnus-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_disable_comments]" value="1" <?php checked((string) ($settings['headless_disable_comments'] ?? '1'), '1'); ?>> <?php esc_html_e('Close comments and pingbacks on the CMS origin', 'ragnus-static-publisher'); ?></label></td>
                </tr>
            </table>
            <?php submit_button(__('Save Headless CMS Settings', 'ragnus-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_zip_settings(array $settings): void
    {
        ?>
        <form class="ragstat-settings-form ragstat-zip-settings-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="zip">
            <table class="form-table" role="presentation">
                <tr><th><label for="ragstat-archive-retention"><?php esc_html_e('Number of ZIPs to Store', 'ragnus-static-publisher'); ?></label></th><td><input id="ragstat-archive-retention" type="number" min="1" max="100" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[archive_retention]" value="<?php echo esc_attr((string) $settings['archive_retention']); ?>"><p class="description"><?php esc_html_e('Determines how many last successful export archives will be stored. Default: 5.', 'ragnus-static-publisher'); ?></p></td></tr>
            </table>
            <?php submit_button(__('Save ZIP Settings', 'ragnus-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_automation_settings(array $settings): void
    {
        $auto_export_groups = [
            __('Contents', 'ragnus-static-publisher') => [
                'auto_export_post_created' => [__('When a new article is published', 'ragnus-static-publisher'), __('When a new blog post is published for the first time.', 'ragnus-static-publisher')],
                'auto_export_post_updated' => [__('When the current post is updated', 'ragnus-static-publisher'), __('When a live blog post is changed or removed.', 'ragnus-static-publisher')],
                'auto_export_page_created' => [__('When the new page is published', 'ragnus-static-publisher'), __('When a new page is published for the first time.', 'ragnus-static-publisher')],
                'auto_export_page_updated' => [__('When the current page is updated', 'ragnus-static-publisher'), __('When a published page is changed or removed.', 'ragnus-static-publisher')],
                'auto_export_custom_content' => [__('When custom content types change', 'ragnus-static-publisher'), __('When product, portfolio, etc. special content in the publication changes.', 'ragnus-static-publisher')],
            ],
            __('Structure and Design', 'ragnus-static-publisher') => [
                'auto_export_taxonomy' => [__('When the category or tag changes', 'ragnus-static-publisher'), __('When categories, tags, or custom classifications are changed.', 'ragnus-static-publisher')],
                'auto_export_media' => [__('When the media changes', 'ragnus-static-publisher'), __('When an image or other media file is added, updated, or deleted.', 'ragnus-static-publisher')],
                'auto_export_menu' => [__('When the menu changes', 'ragnus-static-publisher'), __('When the structure or links of navigation menus are changed.', 'ragnus-static-publisher')],
                'auto_export_widgets' => [__('When components change', 'ragnus-static-publisher'), __('When widget and widget layouts are updated.', 'ragnus-static-publisher')],
                'auto_export_theme' => [__('When the theme changes', 'ragnus-static-publisher'), __('When the theme is changed, updated, or customizer settings are saved.', 'ragnus-static-publisher')],
                'auto_export_site_settings' => [__('When site settings change', 'ragnus-static-publisher'), __('When the site name, description, homepage, reading or permalink settings are changed.', 'ragnus-static-publisher')],
            ],
        ];
        ?>
        <form class="ragstat-settings-form ragstat-automation-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="automation">
            <section class="ragstat-auto-export-card" aria-labelledby="ragstat-auto-export-title">
                <div class="ragstat-auto-export-card__heading">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <div>
                        <h3 id="ragstat-auto-export-title"><?php esc_html_e('Automatic Static Site Creation and Deploy', 'ragnus-static-publisher'); ?></h3>
                        <p><?php esc_html_e('After the selected changes, the static site is created; If the deployment webhook is set, the deploy flow is triggered automatically.', 'ragnus-static-publisher'); ?></p>
                    </div>
                    <label class="ragstat-switch" aria-label="<?php echo esc_attr__('Enable automatic static site generation', 'ragnus-static-publisher'); ?>">
                        <input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[auto_export]" value="1" <?php checked((string) $settings['auto_export'], '1'); ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>
                <div class="ragstat-auto-export-groups">
                    <?php foreach ($auto_export_groups as $group_label => $triggers) : ?>
                        <fieldset class="ragstat-auto-export-group">
                            <legend><?php echo esc_html($group_label); ?></legend>
                            <?php foreach ($triggers as $trigger => [$label, $description]) : ?>
                                <label class="ragstat-auto-export-option">
                                    <input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[<?php echo esc_attr($trigger); ?>]" value="1" <?php checked((string) ($settings[$trigger] ?? '0'), '1'); ?>>
                                    <span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($description); ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <p class="ragstat-auto-export-card__note"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e('Changes made consecutively are combined for 60 seconds and run as a single export and deploy.', 'ragnus-static-publisher'); ?></p>
            </section>
            <?php submit_button(__('Save Auto Deploy Settings', 'ragnus-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_deploy_settings(array $settings): void
    {
        ?>
        <form class="ragstat-settings-form ragstat-deploy-settings-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="deploy">
            <h3><?php esc_html_e('GitHub Deployment Webhook', 'ragnus-static-publisher'); ?></h3>
            <table class="form-table" role="presentation">
                <tr><th><label for="ragstat-webhook"><?php esc_html_e('Deployment Webhook', 'ragnus-static-publisher'); ?></label></th><td><input class="large-text code" id="ragstat-webhook" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_url]" value="<?php echo esc_attr((string) $settings['deployment_webhook_url']); ?>"><p class="description"><?php esc_html_e('For GitHub: https://api.github.com/repos/OWNER/REPOSITORY/dispatches', 'ragnus-static-publisher'); ?></p></td></tr>
                <tr><th><label for="ragstat-webhook-token"><?php esc_html_e('Webhook Bearer Token', 'ragnus-static-publisher'); ?></label></th><td><input class="regular-text" id="ragstat-webhook-token" type="password" autocomplete="new-password" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_token]" value="" placeholder="<?php echo $settings['deployment_webhook_token'] !== '' ? esc_attr__('Leave blank to keep the saved token', 'ragnus-static-publisher') : ''; ?>"><p class="description"><?php esc_html_e('If using GitHub, use a fine-grained token limited to this repository with Contents: write permission.', 'ragnus-static-publisher'); ?></p></td></tr>
            </table>
            <?php submit_button(__('Save Deploy Settings', 'ragnus-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_sftp_settings(array $settings, bool $has_archive): void
    {
        $available = SFTP_Deployer::available();
        $configured = SFTP_Deployer::configured($settings);
        $status = SFTP_Deployer::status();
        $notice = isset($_GET['sftp_notice']) ? sanitize_key(wp_unslash((string) $_GET['sftp_notice'])) : '';
        $status_message = (string) ($status['message'] ?? '');
        $status_time = is_string($status['updated_at'] ?? null) ? strtotime((string) $status['updated_at']) : false;
        ?>
        <?php if ($notice !== '' && $status_message !== '') : ?>
            <div class="notice <?php echo $notice === 'error' ? 'notice-error' : 'notice-success'; ?> inline is-dismissible ragstat-sftp-notice" role="status"><p><?php echo esc_html($status_message); ?></p></div>
        <?php endif; ?>
        <?php if (! $available) : ?>
            <div class="notice notice-error inline ragstat-sftp-notice" role="alert"><p><?php esc_html_e('The SFTP client is unavailable. Reinstall the complete plugin package before using this deployment method.', 'ragnus-static-publisher'); ?></p></div>
        <?php endif; ?>

        <form class="ragstat-settings-form ragstat-sftp-settings-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="sftp">
            <h3><?php esc_html_e('SFTP Connection', 'ragnus-static-publisher'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="ragstat-sftp-host"><?php esc_html_e('SFTP Host', 'ragnus-static-publisher'); ?></label></th>
                    <td><input class="regular-text code" id="ragstat-sftp-host" type="text" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_host]" value="<?php echo esc_attr((string) $settings['sftp_host']); ?>" placeholder="sftp.example.com"><p class="description"><?php esc_html_e('Enter only the hostname or IP address; do not include sftp://.', 'ragnus-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="ragstat-sftp-port"><?php esc_html_e('Port', 'ragnus-static-publisher'); ?></label></th>
                    <td><input id="ragstat-sftp-port" type="number" min="1" max="65535" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_port]" value="<?php echo esc_attr((string) $settings['sftp_port']); ?>"></td>
                </tr>
                <tr>
                    <th><label for="ragstat-sftp-username"><?php esc_html_e('Username', 'ragnus-static-publisher'); ?></label></th>
                    <td><input class="regular-text" id="ragstat-sftp-username" type="text" autocomplete="username" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_username]" value="<?php echo esc_attr((string) $settings['sftp_username']); ?>"></td>
                </tr>
                <tr>
                    <th><label for="ragstat-sftp-password"><?php esc_html_e('Password', 'ragnus-static-publisher'); ?></label></th>
                    <td><input class="regular-text" id="ragstat-sftp-password" type="password" autocomplete="new-password" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_password]" value="" placeholder="<?php echo (string) $settings['sftp_password'] !== '' ? esc_attr__('Leave blank to keep the saved password', 'ragnus-static-publisher') : ''; ?>"><p class="description"><?php esc_html_e('The password is encrypted using the WordPress security keys and is never shown again.', 'ragnus-static-publisher'); ?></p><?php if ((string) $settings['sftp_password'] !== '') : ?><label class="ragstat-sftp-clear-secret"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_clear_password]" value="1"> <?php esc_html_e('Delete saved password', 'ragnus-static-publisher'); ?></label><?php endif; ?></td>
                </tr>
                <tr>
                    <th><label for="ragstat-sftp-path"><?php esc_html_e('Remote Directory', 'ragnus-static-publisher'); ?></label></th>
                    <td><input class="regular-text code" id="ragstat-sftp-path" type="text" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_remote_path]" value="<?php echo esc_attr((string) $settings['sftp_remote_path']); ?>" placeholder="/public_html"><p class="description"><?php esc_html_e('Static files are uploaded into this directory. Existing files with the same path are overwritten; unrelated remote files are preserved.', 'ragnus-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="ragstat-sftp-fingerprint"><?php esc_html_e('Server MD5 Fingerprint', 'ragnus-static-publisher'); ?></label></th>
                    <td><input class="regular-text code" id="ragstat-sftp-fingerprint" type="text" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_host_fingerprint]" value="<?php echo esc_attr((string) $settings['sftp_host_fingerprint']); ?>" placeholder="0123456789abcdef0123456789abcdef"><p class="description"><?php esc_html_e('Recommended. Verify this value with your hosting provider to prevent connecting to the wrong server.', 'ragnus-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="ragstat-sftp-timeout"><?php esc_html_e('Per-file Timeout', 'ragnus-static-publisher'); ?></label></th>
                    <td><input id="ragstat-sftp-timeout" type="number" min="10" max="600" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_timeout]" value="<?php echo esc_attr((string) $settings['sftp_timeout']); ?>"> <?php esc_html_e('seconds', 'ragnus-static-publisher'); ?></td>
                </tr>
            </table>
            <label class="ragstat-sftp-auto-deploy"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_auto_deploy]" value="1" <?php checked((string) $settings['sftp_auto_deploy'], '1'); ?>> <span><strong><?php esc_html_e('Upload automatically after every successful export', 'ragnus-static-publisher'); ?></strong><small><?php esc_html_e('An SFTP failure is recorded separately and does not delete the successfully generated ZIP file.', 'ragnus-static-publisher'); ?></small></span></label>
            <?php submit_button(__('Save SFTP Settings', 'ragnus-static-publisher')); ?>
        </form>

        <div class="ragstat-sftp-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ragnus_static_sftp_test">
                <?php wp_nonce_field('ragnus_static_sftp_test'); ?>
                <?php submit_button(__('Test Connection', 'ragnus-static-publisher'), 'secondary', 'submit', false, ! $available || ! $configured ? ['disabled' => 'disabled'] : []); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ragnus_static_sftp_deploy">
                <?php wp_nonce_field('ragnus_static_sftp_deploy'); ?>
                <?php submit_button(__('Upload Latest Static Site', 'ragnus-static-publisher'), 'primary', 'submit', false, ! $available || ! $configured || ! $has_archive ? ['disabled' => 'disabled'] : []); ?>
            </form>
        </div>
        <?php if ($status_message !== '' && $notice === '') : ?>
            <div class="ragstat-sftp-last-status"><strong><?php esc_html_e('Last SFTP Status', 'ragnus-static-publisher'); ?>:</strong> <?php echo esc_html($status_message); ?><?php if ($status_time !== false) : ?> <span>— <?php echo esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $status_time)); ?></span><?php endif; ?></div>
        <?php endif; ?>
        <?php
    }

    private static function render_language_settings(array $settings): void
    {
        ?>
        <form class="ragstat-settings-form ragstat-language-settings-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static_languages'); ?>
            <section class="ragstat-language-card" aria-labelledby="ragstat-language-title">
                <div class="ragstat-language-card__heading">
                    <span class="dashicons dashicons-translation" aria-hidden="true"></span>
                    <div>
                        <h3 id="ragstat-language-title"><?php esc_html_e('Redirection by Browser Language', 'ragnus-static-publisher'); ?></h3>
                        <p><?php esc_html_e('When the visitor arrives at the main address, first the saved preference is used, then the language sequence of the browser.', 'ragnus-static-publisher'); ?></p>
                    </div>
                    <label class="ragstat-switch" aria-label="<?php echo esc_attr__('Enable browser language redirection', 'ragnus-static-publisher'); ?>">
                        <input type="checkbox" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[enabled]" value="1" <?php checked((string) ($settings['enabled'] ?? '0'), '1'); ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>
                <div class="ragstat-language-grid">
                    <div>
                        <label for="ragstat-supported-languages"><?php esc_html_e('Supported Language Codes', 'ragnus-static-publisher'); ?></label>
                        <textarea id="ragstat-supported-languages" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[supported_languages]" rows="6" spellcheck="false"><?php echo esc_textarea((string) ($settings['supported_languages'] ?? '')); ?></textarea>
                        <p><?php esc_html_e('Write one language per line. These codes in WordPress', 'ragnus-static-publisher'); ?> <code>/tr/</code>, <code>/en/</code> <?php esc_html_e('There should be equivalents like this.', 'ragnus-static-publisher'); ?></p>
                    </div>
                    <div>
                        <label for="ragstat-default-language"><?php esc_html_e('Default Language', 'ragnus-static-publisher'); ?></label>
                        <input id="ragstat-default-language" type="text" maxlength="20" spellcheck="false" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[default_language]" value="<?php echo esc_attr((string) ($settings['default_language'] ?? Language_Routing::site_language())); ?>">
                        <p><?php esc_html_e('If the browser language is not supported, this language is opened.', 'ragnus-static-publisher'); ?></p>

                        <label for="ragstat-language-cookie-days"><?php esc_html_e('Language Preference Period', 'ragnus-static-publisher'); ?></label>
                        <div class="ragstat-input-with-suffix">
                            <input id="ragstat-language-cookie-days" type="number" min="1" max="3650" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[cookie_days]" value="<?php echo esc_attr((string) ($settings['cookie_days'] ?? 365)); ?>">
                            <span><?php esc_html_e('day', 'ragnus-static-publisher'); ?></span>
                        </div>
                        <p><?php esc_html_e('The language that the user explicitly visited is preserved for subsequent logins.', 'ragnus-static-publisher'); ?></p>
                    </div>
                </div>
                <p class="ragstat-language-card__note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e('The redirect is only for the site', 'ragnus-static-publisher'); ?> <code>/</code> <?php esc_html_e('It works at. Direct links containing languages ​​are not changed.', 'ragnus-static-publisher'); ?></p>
            </section>
            <?php submit_button(__('Save Language Settings', 'ragnus-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_search_tab(array $settings, string $requested_search_tab): void
    {
        $option_name = Plugin::SEARCH_SETTINGS_KEY;
        $search_tabs = [
            'static' => [__('Static Search', 'ragnus-static-publisher'), 'dashicons-search'],
            'selectors' => [__('Indexing Selectors', 'ragnus-static-publisher'), 'dashicons-filter'],
            'fuse' => [__('Fuse.js', 'ragnus-static-publisher'), 'dashicons-chart-bar'],
        ];
        $current_search_tab = isset($search_tabs[$requested_search_tab]) ? $requested_search_tab : 'static';
        ?>
        <div class="ragstat-search-header">
            <div>
                <h2><?php esc_html_e('Search', 'ragnus-static-publisher'); ?></h2>
                <p><?php esc_html_e('Configure static site search that doesn\'t require a server, powered by Fuse.js.', 'ragnus-static-publisher'); ?></p>
            </div>
            <span class="ragstat-search-badge">Fuse.js 7.3.0</span>
        </div>
        <div class="ragstat-search-layout">
            <nav class="ragstat-search-tabs" aria-label="<?php echo esc_attr__('Search', 'ragnus-static-publisher'); ?>">
                <?php foreach ($search_tabs as $search_tab => [$label, $icon]) : ?>
                    <a class="ragstat-search-tab <?php echo $current_search_tab === $search_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::search_page_url($search_tab)); ?>" <?php echo $current_search_tab === $search_tab ? 'aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <span><?php echo esc_html($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="ragstat-search-panel">
                <form class="ragstat-search-settings" method="post" action="options.php">
                    <?php settings_fields('ragnus_static_search'); ?>
                    <input type="hidden" name="<?php echo esc_attr($option_name); ?>[_section]" value="<?php echo esc_attr($current_search_tab); ?>">

                    <?php if ($current_search_tab === 'static') : ?>
                        <section class="ragstat-search-card">
                            <div class="ragstat-search-card__heading">
                                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                <div><h3><?php esc_html_e('Static Search', 'ragnus-static-publisher'); ?></h3><p><?php esc_html_e('The search page, index and necessary Fuse.js files are added to the export ZIP.', 'ragnus-static-publisher'); ?></p></div>
                                <label class="ragstat-switch" aria-label="<?php echo esc_attr__('Enable static search', 'ragnus-static-publisher'); ?>">
                                    <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[enabled]" value="1" <?php checked((string) $settings['enabled'], '1'); ?>>
                                    <span aria-hidden="true"></span>
                                </label>
                            </div>
                            <div class="ragstat-search-grid">
                                <div><label for="ragstat-search-path"><?php esc_html_e('Search Page Path', 'ragnus-static-publisher'); ?></label><div class="ragstat-input-group is-compact"><span>/</span><input id="ragstat-search-path" type="text" name="<?php echo esc_attr($option_name); ?>[page_path]" value="<?php echo esc_attr((string) $settings['page_path']); ?>" required><span>/</span></div><p><?php esc_html_e('Example:', 'ragnus-static-publisher'); ?> <code>/search/</code></p></div>
                                <div><label for="ragstat-search-limit"><?php esc_html_e('Number of Results to Show', 'ragnus-static-publisher'); ?></label><input id="ragstat-search-limit" type="number" min="5" max="100" name="<?php echo esc_attr($option_name); ?>[result_limit]" value="<?php echo esc_attr((string) $settings['result_limit']); ?>"></div>
                                <div><label for="ragstat-search-min-chars"><?php esc_html_e('Minimum Search Characters', 'ragnus-static-publisher'); ?></label><input id="ragstat-search-min-chars" type="number" min="1" max="10" name="<?php echo esc_attr($option_name); ?>[min_chars]" value="<?php echo esc_attr((string) $settings['min_chars']); ?>"></div>
                                <div><label for="ragstat-search-content-limit"><?php esc_html_e('Content Character Limit', 'ragnus-static-publisher'); ?></label><input id="ragstat-search-content-limit" type="number" min="500" max="20000" step="500" name="<?php echo esc_attr($option_name); ?>[content_limit]" value="<?php echo esc_attr((string) $settings['content_limit']); ?>"><p><?php esc_html_e('Maximum text length to be indexed from each page.', 'ragnus-static-publisher'); ?></p></div>
                                <div><label for="ragstat-search-threshold"><?php esc_html_e('Blur Threshold', 'ragnus-static-publisher'); ?></label><input id="ragstat-search-threshold" type="number" min="0.1" max="0.8" step="0.05" name="<?php echo esc_attr($option_name); ?>[threshold]" value="<?php echo esc_attr((string) $settings['threshold']); ?>"><p><?php esc_html_e('A lower value gives a more precise result, a higher value gives a more tolerant result.', 'ragnus-static-publisher'); ?></p></div>
                                <div><label for="ragstat-search-token-match"><?php esc_html_e('Word Matching', 'ragnus-static-publisher'); ?></label><select id="ragstat-search-token-match" name="<?php echo esc_attr($option_name); ?>[token_match]"><option value="all" <?php selected($settings['token_match'], 'all'); ?>><?php esc_html_e('Match all words', 'ragnus-static-publisher'); ?></option><option value="any" <?php selected($settings['token_match'], 'any'); ?>><?php esc_html_e('Match any word', 'ragnus-static-publisher'); ?></option></select></div>
                            </div>
                        </section>
                    <?php elseif ($current_search_tab === 'selectors') : ?>
                        <section class="ragstat-search-card">
                            <div class="ragstat-search-card__heading">
                                <span class="dashicons dashicons-filter" aria-hidden="true"></span>
                                <div><h3><?php esc_html_e('Indexing Selectors', 'ragnus-static-publisher'); ?></h3><p><?php esc_html_e('Determine which fields to take from the page and post HTML with the CSS selector.', 'ragnus-static-publisher'); ?></p></div>
                            </div>
                            <div class="ragstat-search-selectors">
                                <div><label for="ragstat-title-selector"><?php esc_html_e('CSS Selector For Title', 'ragnus-static-publisher'); ?></label><input id="ragstat-title-selector" type="text" name="<?php echo esc_attr($option_name); ?>[title_selector]" value="<?php echo esc_attr((string) $settings['title_selector']); ?>" required><p><?php esc_html_e('Example:', 'ragnus-static-publisher'); ?> <code>title</code>, <code>h1.entry-title</code> <?php esc_html_e('or', 'ragnus-static-publisher'); ?> <code>meta[property="og:title"]</code></p></div>
                                <div><label for="ragstat-content-selector"><?php esc_html_e('CSS Selector for Content', 'ragnus-static-publisher'); ?></label><input id="ragstat-content-selector" type="text" name="<?php echo esc_attr($option_name); ?>[content_selector]" value="<?php echo esc_attr((string) $settings['content_selector']); ?>" required><p><?php esc_html_e('Example:', 'ragnus-static-publisher'); ?> <code>body</code>, <code>.entry-content</code> <?php esc_html_e('or', 'ragnus-static-publisher'); ?> <code>#main</code></p></div>
                                <div><label for="ragstat-excerpt-selector"><?php esc_html_e('CSS Selector for Summary', 'ragnus-static-publisher'); ?></label><input id="ragstat-excerpt-selector" type="text" name="<?php echo esc_attr($option_name); ?>[excerpt_selector]" value="<?php echo esc_attr((string) $settings['excerpt_selector']); ?>" required><p><?php esc_html_e('If the field is not found, a short summary is automatically generated from the content.', 'ragnus-static-publisher'); ?></p></div>
                                <div><label for="ragstat-search-excludes"><?php esc_html_e('URLs to Exclude from Indexing', 'ragnus-static-publisher'); ?></label><textarea id="ragstat-search-excludes" rows="6" name="<?php echo esc_attr($option_name); ?>[exclude_urls]"><?php echo esc_textarea((string) $settings['exclude_urls']); ?></textarea><p><?php esc_html_e('You can type the full URL, URL fragment, or word per line.', 'ragnus-static-publisher'); ?></p></div>
                            </div>
                        </section>
                    <?php else : ?>
                        <section class="ragstat-search-card">
                            <div class="ragstat-search-card__heading">
                                <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                                <div><h3><?php esc_html_e('Fuse.js', 'ragnus-static-publisher'); ?></h3><p><?php esc_html_e('Select the fields to search and determine their impact on the result ranking.', 'ragnus-static-publisher'); ?></p></div>
                            </div>
                            <div class="ragstat-search-fields">
                                <?php
                                $index_fields = [
                                    'title' => [__('Title', 'ragnus-static-publisher'), __('Shows title matches at the top.', 'ragnus-static-publisher')],
                                    'excerpt' => [__('Summary', 'ragnus-static-publisher'), __('Searches in the text of the tagline and summary.', 'ragnus-static-publisher')],
                                    'content' => [__('Contents', 'ragnus-static-publisher'), __('It searches in the main text of the page and article.', 'ragnus-static-publisher')],
                                    'taxonomies' => [__('Category and Tags', 'ragnus-static-publisher'), __('WordPress adds category and tag names to the index.', 'ragnus-static-publisher')],
                                ];
                                foreach ($index_fields as $field => [$label, $description]) :
                                    $weight_key = $field === 'taxonomies' ? 'taxonomy_weight' : $field . '_weight';
                                    ?>
                                    <div class="ragstat-search-field-row">
                                        <label class="ragstat-search-field-check"><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[index_<?php echo esc_attr($field); ?>]" value="1" <?php checked((string) $settings['index_' . $field], '1'); ?>><span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($description); ?></small></span></label>
                                        <label class="ragstat-search-weight"><?php esc_html_e('Weight', 'ragnus-static-publisher'); ?> <input type="number" min="0.1" max="10" step="0.1" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($weight_key); ?>]" value="<?php echo esc_attr((string) $settings[$weight_key]); ?>"></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php submit_button(__('Save Search Settings', 'ragnus-static-publisher')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_hide_tab(array $settings, string $requested_hide_tab): void
    {
        $option_name = Plugin::HIDE_SETTINGS_KEY;
        $hide_tabs = [
            'directory' => [__('Directory', 'ragnus-static-publisher'), 'dashicons-portfolio'],
            'traces' => [__('Traces', 'ragnus-static-publisher'), 'dashicons-hidden'],
            'static-outputs' => [__('Static Outputs', 'ragnus-static-publisher'), 'dashicons-shield'],
        ];
        $current_hide_tab = isset($hide_tabs[$requested_hide_tab]) ? $requested_hide_tab : 'directory';
        ?>
        <h2><?php esc_html_e('Hide', 'ragnus-static-publisher'); ?></h2>
        <p class="ragstat-hide-intro"><?php esc_html_e('Specify which WordPress-specific directory and path names will be used in static output. Source WordPress files are not modified.', 'ragnus-static-publisher'); ?></p>
        <div class="ragstat-hide-layout">
            <nav class="ragstat-hide-tabs" aria-label="<?php echo esc_attr__('Hide', 'ragnus-static-publisher'); ?>">
                <?php foreach ($hide_tabs as $hide_tab => [$hide_tab_label, $hide_tab_icon]) : ?>
                    <a class="ragstat-hide-tab <?php echo $current_hide_tab === $hide_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::hide_page_url($hide_tab)); ?>" <?php echo $current_hide_tab === $hide_tab ? 'aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr($hide_tab_icon); ?>" aria-hidden="true"></span>
                        <span><?php echo esc_html($hide_tab_label); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="ragstat-hide-panel">
                <form class="ragstat-hide-form" method="post" action="options.php">
                    <?php settings_fields('ragnus_static_hide'); ?>
                    <input type="hidden" name="<?php echo esc_attr($option_name); ?>[_section]" value="<?php echo esc_attr($current_hide_tab); ?>">

            <?php if ($current_hide_tab === 'directory') : ?>
            <div class="ragstat-hide-field">
                <label for="ragstat-hide-wp-content"><?php esc_html_e('WP-Content Directory', 'ragnus-static-publisher'); ?></label>
                <input id="ragstat-hide-wp-content" type="text" name="<?php echo esc_attr($option_name); ?>[wp_content_directory]" value="<?php echo esc_attr((string) $settings['wp_content_directory']); ?>" required>
                <p><?php esc_html_e('In the static output', 'ragnus-static-publisher'); ?> <code>wp-content</code> <?php esc_html_e('The name to replace the directory.', 'ragnus-static-publisher'); ?></p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-wp-includes"><?php esc_html_e('WP-Includes Directory', 'ragnus-static-publisher'); ?></label>
                <input id="ragstat-hide-wp-includes" type="text" name="<?php echo esc_attr($option_name); ?>[wp_includes_directory]" value="<?php echo esc_attr((string) $settings['wp_includes_directory']); ?>" required>
                <p><?php esc_html_e('In the static output', 'ragnus-static-publisher'); ?> <code>wp-includes</code> <?php esc_html_e('The name to replace the directory.', 'ragnus-static-publisher'); ?></p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-uploads"><?php esc_html_e('Uploads Directory', 'ragnus-static-publisher'); ?></label>
                <div class="ragstat-input-group"><span data-ragstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="ragstat-hide-uploads" type="text" name="<?php echo esc_attr($option_name); ?>[uploads_directory]" value="<?php echo esc_attr((string) $settings['uploads_directory']); ?>" required></div>
                <p><?php esc_html_e('In the static output', 'ragnus-static-publisher'); ?> <code>wp-content/uploads</code> <?php esc_html_e('The subdirectory to use in place of the path.', 'ragnus-static-publisher'); ?></p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-plugins"><?php esc_html_e('Plugins Directory', 'ragnus-static-publisher'); ?></label>
                <div class="ragstat-input-group"><span data-ragstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="ragstat-hide-plugins" type="text" name="<?php echo esc_attr($option_name); ?>[plugins_directory]" value="<?php echo esc_attr((string) $settings['plugins_directory']); ?>" required></div>
                <p><?php esc_html_e('In the static output', 'ragnus-static-publisher'); ?> <code>wp-content/plugins</code> <?php esc_html_e('The subdirectory to use in place of the path.', 'ragnus-static-publisher'); ?></p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-themes"><?php esc_html_e('Themes Directory', 'ragnus-static-publisher'); ?></label>
                <div class="ragstat-input-group"><span data-ragstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="ragstat-hide-themes" type="text" name="<?php echo esc_attr($option_name); ?>[themes_directory]" value="<?php echo esc_attr((string) $settings['themes_directory']); ?>" required></div>
                <p><?php esc_html_e('In the static output', 'ragnus-static-publisher'); ?> <code>wp-content/themes</code> <?php esc_html_e('The subdirectory to use in place of the path.', 'ragnus-static-publisher'); ?></p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-style"><?php esc_html_e('Theme Style File', 'ragnus-static-publisher'); ?></label>
                <div class="ragstat-input-group is-compact"><input id="ragstat-hide-style" type="text" name="<?php echo esc_attr($option_name); ?>[theme_style_name]" value="<?php echo esc_attr((string) $settings['theme_style_name']); ?>" required><span>.css</span></div>
                <p><?php esc_html_e('in active theme', 'ragnus-static-publisher'); ?> <code>style.css</code> <?php esc_html_e('The name of the file in the static output.', 'ragnus-static-publisher'); ?></p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-author"><?php esc_html_e('Author URL', 'ragnus-static-publisher'); ?></label>
                <input id="ragstat-hide-author" type="text" name="<?php echo esc_attr($option_name); ?>[author_url]" value="<?php echo esc_attr((string) $settings['author_url']); ?>" required>
                <p><?php esc_html_e('In the static output', 'ragnus-static-publisher'); ?> <code>/author/</code> <?php esc_html_e('The path to be used instead of the path.', 'ragnus-static-publisher'); ?></p>
            </div>
            <?php endif; ?>

            <?php if ($current_hide_tab === 'traces') : ?>
            <section class="ragstat-hide-options" aria-labelledby="ragstat-hide-traces-title">
                <div class="ragstat-hide-options__header">
                    <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                    <div>
                        <h3 id="ragstat-hide-traces-title"><?php esc_html_e('Traces', 'ragnus-static-publisher'); ?></h3>
                        <p><?php esc_html_e('Removes selected WordPress identifiers from static HTML output.', 'ragnus-static-publisher'); ?></p>
                    </div>
                </div>
                <?php
                $hide_toggles = [
                    'hide_wordpress_version' => [__('Hide WordPress Version', 'ragnus-static-publisher'), __('Removes asset version parameters that specify the WordPress core version.', 'ragnus-static-publisher')],
                    'hide_generator_meta' => [__('Hide WordPress Generator Meta Tag', 'ragnus-static-publisher'), __('It removes the generator meta tag that describes the WordPress version.', 'ragnus-static-publisher')],
                    'hide_wordpress_dns_prefetch' => [__('Hide WordPress DNS Prefetch Connection', 'ragnus-static-publisher'), __('Removes DNS prefetch connections for WordPress services.', 'ragnus-static-publisher')],
                    'hide_rsd_header' => [__('Hide RSD Header Link', 'ragnus-static-publisher'), __('Really Simple Removes the Discovery link from static HTML.', 'ragnus-static-publisher')],
                ];
                foreach ($hide_toggles as $key => [$label, $description]) :
                    $field_id = 'ragstat-' . str_replace('_', '-', $key);
                    ?>
                    <div class="ragstat-hide-toggle-row">
                        <div>
                            <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label>
                            <p><?php echo esc_html($description); ?></p>
                        </div>
                        <label class="ragstat-switch" aria-label="<?php echo esc_attr($label); ?>">
                            <input id="<?php echo esc_attr($field_id); ?>" type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked((string) ($settings[$key] ?? '0'), '1'); ?>>
                            <span aria-hidden="true"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if ($current_hide_tab === 'static-outputs') : ?>
            <section class="ragstat-hide-options" aria-labelledby="ragstat-disable-features-title">
                <div class="ragstat-hide-options__header">
                    <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                    <div>
                        <h3 id="ragstat-disable-features-title"><?php esc_html_e('Static Outputs', 'ragnus-static-publisher'); ?></h3>
                        <p><?php esc_html_e('Cleans up unused WordPress links and scripts on the static site.', 'ragnus-static-publisher'); ?></p>
                    </div>
                </div>
                <?php
                $disable_toggles = [
                    'disable_xml_rpc' => [__('Disable XML-RPC Links', 'ragnus-static-publisher'), __('Removes XML-RPC and pingback discovery connections.', 'ragnus-static-publisher')],
                    'disable_embed_scripts' => [__('Disable Embed Scripts', 'ragnus-static-publisher'), __('Removes WordPress embed scripts from static HTML.', 'ragnus-static-publisher')],
                    'disable_db_debug' => [__('Disable Frontend DB Debug Information', 'ragnus-static-publisher'), __('It only prevents database error details from being shown in export requests.', 'ragnus-static-publisher')],
                    'disable_wlw_manifest' => [__('Disable WLW Manifest Link', 'ragnus-static-publisher'), __('Windows Live Writer removes the manifest link.', 'ragnus-static-publisher')],
                    'disable_emojis' => [__('Disable Emoji Scripts', 'ragnus-static-publisher'), __('Removes WordPress emoji scripts and styles from static HTML.', 'ragnus-static-publisher')],
                ];
                foreach ($disable_toggles as $key => [$label, $description]) :
                    $field_id = 'ragstat-' . str_replace('_', '-', $key);
                    ?>
                    <div class="ragstat-hide-toggle-row">
                        <div>
                            <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label>
                            <p><?php echo esc_html($description); ?></p>
                        </div>
                        <label class="ragstat-switch" aria-label="<?php echo esc_attr($label); ?>">
                            <input id="<?php echo esc_attr($field_id); ?>" type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked((string) ($settings[$key] ?? '0'), '1'); ?>>
                            <span aria-hidden="true"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

                    <?php submit_button(__('Save Hide Settings', 'ragnus-static-publisher')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_diagnostics_tab(): void
    {
        $report = Diagnostics::report();
        $groups = $report['groups'];
        $total = 0;
        $passed = 0;
        foreach ($groups as $checks) {
            $total += count($checks);
            foreach ($checks as $check) {
                if ($check['passed']) {
                    $passed++;
                }
            }
        }
        $checked_timestamp = strtotime((string) ($report['checked_at'] ?? ''));
        ?>
        <div class="ragstat-diagnostics-header">
            <div>
                <h2><?php esc_html_e('Diagnostics', 'ragnus-static-publisher'); ?></h2>
                <p class="ragstat-diagnostics-summary"><strong><?php echo esc_html(sprintf(__('%1$d of %2$d checks passed.', 'ragnus-static-publisher'), $passed, $total)); ?></strong> <?php esc_html_e('Last checked:', 'ragnus-static-publisher'); ?> <?php echo $checked_timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $checked_timestamp)); ?></p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ragnus_static_refresh_diagnostics">
                <?php wp_nonce_field('ragnus_static_refresh_diagnostics'); ?>
                <?php submit_button(__('Check Again', 'ragnus-static-publisher'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php if (isset($_GET['checked']) && sanitize_key(wp_unslash((string) $_GET['checked'])) === '1') : ?>
            <div class="ragstat-inline-alert is-success" role="status">
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <span><?php esc_html_e('Diagnostics checks have been updated.', 'ragnus-static-publisher'); ?></span>
            </div>
        <?php endif; ?>
        <?php foreach ($groups as $group_label => $checks) : ?>
            <section class="ragstat-diagnostics-card" aria-labelledby="ragstat-diagnostics-<?php echo esc_attr(sanitize_title($group_label)); ?>">
                <h2 id="ragstat-diagnostics-<?php echo esc_attr(sanitize_title($group_label)); ?>"><?php echo esc_html($group_label); ?></h2>
                <table class="ragstat-diagnostics-table" role="presentation">
                    <tbody>
                    <?php foreach ($checks as $check) : ?>
                        <tr>
                            <td class="ragstat-diagnostic-icon-cell">
                                <span class="ragstat-diagnostic-icon <?php echo $check['passed'] ? 'is-passed' : 'is-failed'; ?>" aria-label="<?php echo esc_attr($check['passed'] ? __('Passed', 'ragnus-static-publisher') : __('Failed', 'ragnus-static-publisher')); ?>">
                                    <span class="dashicons <?php echo $check['passed'] ? 'dashicons-yes' : 'dashicons-no-alt'; ?>" aria-hidden="true"></span>
                                </span>
                            </td>
                            <th scope="row"><?php echo esc_html((string) $check['label']); ?></th>
                            <td><?php echo esc_html((string) $check['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endforeach; ?>
        <?php
    }

    private static function render_about_tab(): void
    {
        ?>
        <h2><?php esc_html_e('About', 'ragnus-static-publisher'); ?></h2>
        <section class="ragstat-about-card" aria-labelledby="ragstat-about-title">
            <div class="ragstat-about-card__intro">
                <span class="ragstat-about-card__icon dashicons dashicons-media-document" aria-hidden="true"></span>
                <div>
                    <h3 id="ragstat-about-title">Ragnus Static Publisher</h3>
                    <p><?php esc_html_e('It was developed to convert your WordPress site into static files and prepare them for publication processes.', 'ragnus-static-publisher'); ?></p>
                </div>
            </div>
            <dl class="ragstat-about-details">
                <div>
                    <dt><?php esc_html_e('Version Number', 'ragnus-static-publisher'); ?></dt>
                    <dd><code><?php echo esc_html(RAGSTAT_VERSION); ?></code></dd>
                </div>
                <div>
                    <dt><?php esc_html_e('Support Email', 'ragnus-static-publisher'); ?></dt>
                    <dd><a href="<?php echo esc_url('mailto:info@ragnus.co'); ?>">info@ragnus.co</a></dd>
                </div>
                <div>
                    <dt><?php esc_html_e('Plugin Website', 'ragnus-static-publisher'); ?></dt>
                    <dd>
                        <a href="<?php echo esc_url('https://ragnus.co/'); ?>" target="_blank" rel="noopener noreferrer">
                            ragnus.co
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                        </a>
                    </dd>
                </div>
            </dl>
        </section>
        <?php
    }
}
