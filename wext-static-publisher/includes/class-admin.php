<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

final class Admin
{
    public static function menu(): void
    {
        add_menu_page(
            'Wext Static Publisher',
            'Static Publisher',
            'manage_options',
            'wext-static-publisher',
            [self::class, 'render'],
            'dashicons-media-document',
            58
        );
    }

    public static function settings(): void
    {
        register_setting('wext_static', Plugin::SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => [],
        ]);
        register_setting('wext_static_hide', Plugin::HIDE_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_hide_settings'],
            'default' => Plugin::hide_defaults(),
        ]);
        register_setting('wext_static_search', Plugin::SEARCH_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_search_settings'],
            'default' => Plugin::search_defaults(),
        ]);
        register_setting('wext_static_languages', Plugin::LANGUAGE_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [Language_Routing::class, 'sanitize'],
            'default' => Language_Routing::defaults(),
        ]);
        register_setting('wext_static_seo_plugins', Plugin::SEO_PLUGIN_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_seo_plugin_settings'],
            'default' => Plugin::seo_plugin_defaults(),
        ]);
        register_setting('wext_static_seo', Plugin::SEO_SETTINGS_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_seo_settings'],
            'default' => Plugin::seo_defaults(),
        ]);
    }

    public static function enqueue_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'toplevel_page_wext-static-publisher') {
            return;
        }

        $style_path = WEXTSTAT_DIR . 'assets/admin.css';
        $script_path = WEXTSTAT_DIR . 'assets/admin.js';
        $style_version = is_readable($style_path) ? (string) filemtime($style_path) : WEXTSTAT_VERSION;
        $script_version = is_readable($script_path) ? (string) filemtime($script_path) : WEXTSTAT_VERSION;

        wp_enqueue_style(
            'wext-static-publisher-admin',
            plugins_url('assets/admin.css', WEXTSTAT_FILE),
            [],
            $style_version
        );

        wp_enqueue_script(
            'wext-static-publisher-admin',
            plugins_url('assets/admin.js', WEXTSTAT_FILE),
            [],
            $script_version,
            true
        );
        $cloudflare_configured = Plugin::cloudflare_deployment_configured();
        wp_localize_script('wext-static-publisher-admin', 'WextStaticPublisherAdmin', [
            'statusUrl' => rest_url('wext-static/v1/exports/latest'),
            'nonce' => wp_create_nonce('wp_rest'),
            'runnerUrl' => admin_url('admin-ajax.php'),
            'runnerNonce' => wp_create_nonce('wext_static_run_pending'),
            'pollInterval' => 2000,
            'i18n' => [
                'queued' => __('Queued', 'wext-static-publisher'),
                'running' => __('Running', 'wext-static-publisher'),
                'completed' => __('Completed', 'wext-static-publisher'),
                'failed' => __('Failed', 'wext-static-publisher'),
                'notRun' => __('Not run yet', 'wext-static-publisher'),
                'progressLabel' => __('Static rendering progress', 'wext-static-publisher'),
                'retry' => __('Retry', 'wext-static-publisher'),
                'create' => __('Create Static Site', 'wext-static-publisher'),
                'deployCloudflare' => __('Deploy to Cloudflare', 'wext-static-publisher'),
                'waiting' => __('Waiting', 'wext-static-publisher'),
                'notDeployed' => __('Not deployed yet', 'wext-static-publisher'),
                'pollError' => __('Progress information is unavailable. Check your internet connection or WordPress REST API access.', 'wext-static-publisher'),
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
                    __('Enter an HTTP(S) frontend address on a different origin than WordPress.', 'wext-static-publisher'),
                    'error'
                );
                $frontend_url = (string) ($current['headless_frontend_url'] ?? '');
            }
            if ($behavior === 'redirect' && $frontend_url === '') {
                add_settings_error(
                    Plugin::SETTINGS_KEY,
                    'headless-redirect-url-error',
                    __('A frontend address is required for redirect behavior. The 404 behavior was selected instead.', 'wext-static-publisher'),
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
            if (Plugin::license_active()) {
                $sanitized['auto_export'] = isset($value['auto_export']) ? '1' : '0';
                foreach (array_keys(Plugin::auto_export_trigger_defaults()) as $trigger) {
                    $sanitized[$trigger] = isset($value[$trigger]) ? '1' : '0';
                }
            } else {
                add_settings_error(Plugin::SETTINGS_KEY, 'license-required-automation', __('An active Wext license is required to use Auto Deploy.', 'wext-static-publisher'), 'error');
            }
        }

        $deploy_submitted = $section === 'deploy'
            || ($section === 'all' && (array_key_exists('deployment_webhook_url', $value) || array_key_exists('deployment_webhook_token', $value)));
        if ($deploy_submitted) {
            if (Plugin::license_active()) {
                $submitted_token = sanitize_text_field((string) ($value['deployment_webhook_token'] ?? ''));
                $sanitized['deployment_webhook_url'] = esc_url_raw((string) ($value['deployment_webhook_url'] ?? ''));
                $sanitized['deployment_webhook_token'] = $submitted_token !== '' ? $submitted_token : (string) $current['deployment_webhook_token'];
                $sanitized['deployment_mode'] = 'advanced';
            } else {
                add_settings_error(Plugin::SETTINGS_KEY, 'license-required-github', __('An active Wext license is required to use GitHub deployment.', 'wext-static-publisher'), 'error');
            }
        }

        if (in_array($section, ['sftp', 'all'], true)) {
            $sanitized['sftp_auto_deploy'] = isset($value['sftp_auto_deploy']) ? '1' : '0';
            $sanitized['sftp_host'] = SFTP_Deployer::sanitize_host((string) ($value['sftp_host'] ?? ''));
            $sanitized['sftp_port'] = max(1, min(65535, absint($value['sftp_port'] ?? 22)));
            $sanitized['sftp_username'] = sanitize_text_field((string) ($value['sftp_username'] ?? ''));
            $submitted_remote_path = (string) ($value['sftp_remote_path'] ?? '/public_html');
            $remote_path = SFTP_Deployer::sanitize_remote_path($submitted_remote_path);
            if ($remote_path === '') {
                add_settings_error(Plugin::SETTINGS_KEY, 'sftp-path-error', __('The SFTP remote directory cannot contain .. path segments.', 'wext-static-publisher'), 'error');
                $remote_path = (string) $current['sftp_remote_path'];
            }
            $sanitized['sftp_remote_path'] = $remote_path;

            $submitted_fingerprint = trim((string) ($value['sftp_host_fingerprint'] ?? ''));
            $fingerprint = SFTP_Deployer::sanitize_fingerprint($submitted_fingerprint);
            if ($submitted_fingerprint !== '' && $fingerprint === '') {
                add_settings_error(Plugin::SETTINGS_KEY, 'sftp-fingerprint-error', __('Enter the SFTP server fingerprint as 32 hexadecimal MD5 characters.', 'wext-static-publisher'), 'error');
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
                add_settings_error(Plugin::SETTINGS_KEY, 'sftp-auto-error', __('Complete the SFTP connection information before enabling automatic upload.', 'wext-static-publisher'), 'error');
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
        if (! Plugin::license_active()) {
            add_settings_error(Plugin::SEO_PLUGIN_SETTINGS_KEY, 'license-required-seo', __('An active Wext license is required to use SEO features.', 'wext-static-publisher'), 'error');
            return Plugin::seo_plugin_settings();
        }
        $sanitized = [];
        foreach (array_keys(Plugin::seo_plugin_defaults()) as $key) {
            $sanitized[$key] = isset($value[$key]) ? '1' : '0';
        }
        return $sanitized;
    }

    public static function sanitize_seo_settings(array $value): array
    {
        if (! Plugin::license_active()) {
            add_settings_error(Plugin::SEO_SETTINGS_KEY, 'license-required-seo', __('An active Wext license is required to use SEO features.', 'wext-static-publisher'), 'error');
            return Plugin::seo_settings();
        }
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
                add_settings_error(Plugin::SEO_SETTINGS_KEY, 'indexnow-key-error', __('IndexNow key must be 8–128 characters and contain only letters, numbers, or hyphens.', 'wext-static-publisher'), 'error');
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
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
        }
        check_admin_referer('wext_static_export');
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
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
        }
        check_admin_referer('wext_static_download');

        $archive = Archive_Manager::latest();
        if ($archive === null || ! is_readable((string) $archive['path'])) {
            wp_die(__('No downloadable exports found.', 'wext-static-publisher'), 404);
        }

        self::send_file((string) $archive['path'], (string) $archive['id'] . '.zip');
    }

    public static function run_pending_export(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not authorized for this operation.', 'wext-static-publisher')], 403);
        }
        check_ajax_referer('wext_static_run_pending', 'nonce');

        $status = Plugin::status();
        $job_id = sanitize_file_name((string) ($status['job_id'] ?? ''));
        if (($status['state'] ?? '') !== 'queued' || $job_id === '') {
            wp_send_json_success(['status' => Plugin::public_status()]);
        }
        if (get_transient(Plugin::LOCK_KEY)) {
            wp_send_json_error(['message' => __('The export process is already running.', 'wext-static-publisher')], 409);
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
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
        }
        check_admin_referer('wext_static_refresh_diagnostics');
        Diagnostics::refresh();
        wp_safe_redirect(add_query_arg('checked', '1', self::admin_page_url('diagnostics')));
        exit;
    }

    public static function test_sftp_connection(): void
    {
        self::authorize_sftp_action('wext_static_sftp_test');
        try {
            SFTP_Deployer::test_connection();
            self::redirect_sftp('connection-success');
        } catch (\Throwable $error) {
            self::redirect_sftp('error');
        }
    }

    public static function deploy_latest_with_sftp(): void
    {
        self::authorize_sftp_action('wext_static_sftp_deploy');
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

    public static function activate_managed_license(): void
    {
        self::authorize_managed_action('wext_static_license_activate');
        $license_key = isset($_POST['license_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['license_key']))
            : '';
        try {
            Managed_Deployer::activate_license($license_key);
            self::redirect_managed('license-activated', 'success', 'about');
        } catch (\Throwable $error) {
            self::redirect_managed($error->getMessage(), 'error', 'about');
        }
    }

    public static function deactivate_managed_license(): void
    {
        self::authorize_managed_action('wext_static_license_deactivate');
        try {
            Managed_Deployer::deactivate_license();
            self::redirect_managed('license-detached', 'success', 'about');
        } catch (\Throwable $error) {
            self::redirect_managed($error->getMessage(), 'error', 'about');
        }
    }

    public static function connect_managed_cloudflare(): void
    {
        self::authorize_managed_action('wext_static_managed_connect');
        if (! Plugin::license_active()) {
            self::redirect_managed('license-required-cloudflare', 'error', 'about');
        }
        try {
            $url = Managed_Deployer::authorization_url();
            wp_redirect($url, 302, 'Wext Static Publisher');
            exit;
        } catch (\Throwable $error) {
            self::redirect_managed($error->getMessage(), 'error');
        }
    }

    public static function complete_managed_cloudflare(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
        }
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash((string) $_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';
        if (! Plugin::license_active()) {
            self::redirect_managed('license-required-cloudflare', 'error', 'about');
        }
        try {
            Managed_Deployer::complete_connection($state, $code);
            self::redirect_managed('cloudflare-connected', 'success');
        } catch (\Throwable $error) {
            self::redirect_managed($error->getMessage(), 'error');
        }
    }

    public static function disconnect_managed_cloudflare(): void
    {
        self::authorize_managed_action('wext_static_managed_disconnect');
        $provider_revoke = Managed_Deployer::disconnect();
        self::redirect_managed(
            $provider_revoke === 'pending' ? 'cloudflare-disconnected-pending' : 'cloudflare-disconnected',
            $provider_revoke === 'pending' ? 'warning' : 'success'
        );
    }

    private static function authorize_managed_action(string $nonce_action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
        }
        check_admin_referer($nonce_action);
    }

    private static function redirect_managed(string $message, string $type, string $tab = 'cloudflare'): void
    {
        $notice_key = 'wext_static_managed_notice_' . get_current_user_id();
        set_transient($notice_key, [
            'message' => sanitize_text_field($message),
            'type' => in_array($type, ['success', 'warning', 'error'], true) ? $type : 'error',
        ], MINUTE_IN_SECONDS);
        wp_safe_redirect($tab === 'about' ? self::about_page_url('license') : self::deploy_page_url('cloudflare'));
        exit;
    }

    private static function managed_notice(): array
    {
        $notice_key = 'wext_static_managed_notice_' . get_current_user_id();
        $notice = get_transient($notice_key);
        delete_transient($notice_key);
        if (! is_array($notice)) {
            return [];
        }
        $messages = [
            'license-activated' => __('The Wext license is active. You can now connect Cloudflare.', 'wext-static-publisher'),
            'license-detached' => __('The Wext license was detached from this site. You can use it on this domain or another domain while it remains valid.', 'wext-static-publisher'),
            'cloudflare-connected' => __('Cloudflare is connected to the Wext deployment service.', 'wext-static-publisher'),
            'cloudflare-disconnected' => __('Cloudflare was disconnected.', 'wext-static-publisher'),
            'cloudflare-disconnected-pending' => __('The local connection was removed. Cloudflare token revocation is pending in the service.', 'wext-static-publisher'),
            'license-required-cloudflare' => __('An active Wext license is required to use Cloudflare deployment.', 'wext-static-publisher'),
        ];
        $message = (string) ($notice['message'] ?? '');
        return [
            'message' => $messages[$message] ?? $message,
            'type' => (string) ($notice['type'] ?? 'error'),
        ];
    }

    private static function authorize_sftp_action(string $nonce_action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
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
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
        }

        $archive_id = isset($_GET['archive_id']) ? sanitize_file_name(wp_unslash((string) $_GET['archive_id'])) : '';
        check_admin_referer('wext_static_download_archive_' . $archive_id);
        $archive = Archive_Manager::find($archive_id);
        if ($archive === null || ! is_readable((string) $archive['path'])) {
            wp_die(__('No downloadable ZIP file found.', 'wext-static-publisher'), 404);
        }

        self::send_file((string) $archive['path'], $archive_id . '.zip');
    }

    public static function archive_bulk_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
        }
        check_admin_referer('wext_static_archive_bulk');

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
                self::send_file($bundle, 'wext-static-selected-exports.zip', true);
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
            wp_die(__('You are not authorized for this operation.', 'wext-static-publisher'), 403);
        }
        check_admin_referer('wext_static_cleanup_exports');

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
        $requested_about_tab = isset($_GET['about_tab']) ? sanitize_key(wp_unslash((string) $_GET['about_tab'])) : 'about';
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
            'main' => __('Main', 'wext-static-publisher'),
            'deploy' => __('Deploy', 'wext-static-publisher'),
            'settings' => __('Static Site', 'wext-static-publisher'),
            'seo' => __('SEO', 'wext-static-publisher'),
            'search' => __('Search', 'wext-static-publisher'),
            'hide' => __('Hide', 'wext-static-publisher'),
            'diagnostics' => __('Diagnostics', 'wext-static-publisher'),
            'activity' => __('Activity Logs', 'wext-static-publisher'),
            'about' => __('About', 'wext-static-publisher'),
        ];
        $current_tab = isset($tabs[$requested_tab]) ? $requested_tab : 'main';
        $license_active = Plugin::license_active();
        $status = Plugin::public_status();
        $archives = Archive_Manager::archives();
        ?>
        <div class="wrap wextstat-admin">
            <hr class="wp-header-end">
            <header class="wextstat-admin-header">
                <span class="wextstat-admin-header__icon dashicons dashicons-media-document" aria-hidden="true"></span>
                <div>
                    <h1>Wext Static Publisher</h1>
                    <p><?php esc_html_e('Generate a static copy of your WordPress site. Cloudflare credentials are not kept in this plugin.', 'wext-static-publisher'); ?></p>
                </div>
            </header>
            <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php echo esc_attr__('Static Publisher sections', 'wext-static-publisher'); ?>">
                <?php foreach ($tabs as $tab_id => $tab_label) : ?>
                    <a class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?> <?php echo $tab_id === 'seo' && ! $license_active ? 'is-locked' : ''; ?>" href="<?php echo esc_url(self::admin_page_url($tab_id)); ?>" <?php echo $current_tab === $tab_id ? 'aria-current="page"' : ''; ?>><?php echo esc_html($tab_label); ?><?php if ($tab_id === 'seo' && ! $license_active) : ?><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php endif; ?></a>
                <?php endforeach; ?>
                <a class="wextstat-license-badge <?php echo $license_active ? 'is-pro' : 'is-upgrade'; ?>" href="<?php echo esc_url(self::about_page_url('license')); ?>">
                    <?php echo $license_active
                        ? esc_html(sprintf(__('Pro v%s', 'wext-static-publisher'), WEXTSTAT_VERSION))
                        : esc_html__('Upgrade to Pro', 'wext-static-publisher'); ?>
                </a>
            </nav>

            <main class="wextstat-tab-content">
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
                <?php self::render_about_tab($requested_about_tab); ?>
            <?php endif; ?>
            </main>
            <?php self::render_confirmation_modal(); ?>
        </div>
        <?php
    }

    private static function render_confirmation_modal(): void
    {
        ?>
        <div class="wextstat-confirm-modal" data-wextstat-confirm-modal hidden>
            <div class="wextstat-confirm-modal__backdrop" data-wextstat-confirm-cancel></div>
            <div class="wextstat-confirm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wextstat-confirm-title" aria-describedby="wextstat-confirm-message">
                <span class="wextstat-confirm-modal__icon dashicons dashicons-warning" aria-hidden="true"></span>
                <div class="wextstat-confirm-modal__content">
                    <h2 id="wextstat-confirm-title"><?php esc_html_e('Confirm Transaction', 'wext-static-publisher'); ?></h2>
                    <p id="wextstat-confirm-message"></p>
                </div>
                <div class="wextstat-confirm-modal__actions">
                    <button type="button" class="button" data-wextstat-confirm-cancel><?php esc_html_e('Cancel', 'wext-static-publisher'); ?></button>
                    <button type="button" class="button button-primary wextstat-confirm-modal__confirm" data-wextstat-confirm-accept><?php esc_html_e('Delete', 'wext-static-publisher'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function admin_page_url(string $tab): string
    {
        $url = admin_url('admin.php?page=wext-static-publisher');
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

    private static function about_page_url(string $about_tab): string
    {
        return add_query_arg('about_tab', $about_tab, self::admin_page_url('about'));
    }

    private static function render_main_tab(array $status, array $archives): void
    {
        $state = (string) ($status['state'] ?? '');
        $progress = max(0, min(100, absint($status['progress'] ?? 0)));
        $state_labels = [
            'queued' => __('Queued', 'wext-static-publisher'),
            'running' => __('Running', 'wext-static-publisher'),
            'completed' => __('Completed', 'wext-static-publisher'),
            'failed' => __('Failed', 'wext-static-publisher'),
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
            'waiting' => __('Waiting', 'wext-static-publisher'),
            'dispatched' => __('Queued', 'wext-static-publisher'),
            'deploying' => __('Running', 'wext-static-publisher'),
            'completed' => __('Completed', 'wext-static-publisher'),
            'failed' => __('Failed', 'wext-static-publisher'),
        ];
        ?>
        <div data-wextstat-status-root>
        <h2><?php esc_html_e('Publishing Status', 'wext-static-publisher'); ?></h2>
        <div id="wextstat-runtime-notice" class="notice notice-warning inline wextstat-runtime-notice" role="status" <?php echo $runtime_notice === '' ? 'hidden' : ''; ?>><p><?php echo esc_html($runtime_notice); ?></p></div>
        <table class="widefat striped wextstat-status-table">
            <tbody>
            <tr><th><?php esc_html_e('Status', 'wext-static-publisher'); ?></th><td id="wextstat-status-state"><?php echo esc_html($state_labels[$state] ?? __('Not run yet', 'wext-static-publisher')); ?></td></tr>
            <tr>
                <th><?php esc_html_e('Progress', 'wext-static-publisher'); ?></th>
                <td id="wextstat-progress-cell">
                    <?php if ($state === 'completed') : ?>
                        <span class="wextstat-progress-status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong><?php esc_html_e('Completed', 'wext-static-publisher'); ?></strong></span>
                    <?php elseif ($state === 'failed') : ?>
                        <span class="wextstat-progress-status"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><strong><?php esc_html_e('Failed', 'wext-static-publisher'); ?></strong></span>
                    <?php else : ?>
                        <div class="wextstat-progress <?php echo in_array($state, ['queued', 'running'], true) ? 'is-active' : ''; ?>" role="progressbar" aria-label="<?php echo esc_attr__('Static rendering progress', 'wext-static-publisher'); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $progress); ?>">
                            <div class="wextstat-progress__bar" style="width:<?php echo esc_attr((string) $progress); ?>%"></div>
                        </div>
                        <span class="wextstat-progress-percent"><?php echo esc_html((string) $progress); ?>%</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr><th><?php esc_html_e('Stage', 'wext-static-publisher'); ?></th><td id="wextstat-status-message"><?php echo esc_html((string) ($status['status_message'] ?? '—')); ?></td></tr>
            <tr><th><?php esc_html_e('Job ID', 'wext-static-publisher'); ?></th><td><code id="wextstat-job-id"><?php echo esc_html((string) ($status['job_id'] ?? '—')); ?></code></td></tr>
            <tr><th><?php esc_html_e('URL Count', 'wext-static-publisher'); ?></th><td id="wextstat-url-count"><?php echo esc_html((string) ($status['url_count'] ?? 0)); ?></td></tr>
            <tr id="wextstat-current-url-row" <?php echo empty($status['current_url']) ? 'hidden' : ''; ?>><th><?php esc_html_e('Current URL', 'wext-static-publisher'); ?></th><td><code id="wextstat-current-url"><?php echo esc_html((string) ($status['current_url'] ?? '')); ?></code></td></tr>
            <tr><th><?php esc_html_e('Last Static Build', 'wext-static-publisher'); ?></th><td id="wextstat-last-completed"><?php echo $last_completed_timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $last_completed_timestamp)); ?></td></tr>
            <tr id="wextstat-error-row" <?php echo empty($status['error']) ? 'hidden' : ''; ?>><th><?php esc_html_e('Error', 'wext-static-publisher'); ?></th><td id="wextstat-error"><?php echo esc_html((string) ($status['error'] ?? '')); ?></td></tr>
            </tbody>
        </table>

        <?php if ($cloudflare_configured) : ?>
            <h2><?php esc_html_e('Cloudflare Deploy', 'wext-static-publisher'); ?></h2>
            <table class="widefat striped wextstat-status-table wextstat-deployment-status-table">
                <tbody>
                <tr><th><?php esc_html_e('Status', 'wext-static-publisher'); ?></th><td id="wextstat-deployment-state"><?php echo esc_html($deployment_labels[$deployment_state] ?? __('Not deployed yet', 'wext-static-publisher')); ?></td></tr>
                <tr><th><?php esc_html_e('Job ID', 'wext-static-publisher'); ?></th><td><code id="wextstat-deployment-job-id"><?php echo esc_html((string) ($deployment['job_id'] ?? '—')); ?></code></td></tr>
                <tr><th><?php esc_html_e('Last Cloudflare Deploy', 'wext-static-publisher'); ?></th><td id="wextstat-deployment-updated"><?php echo esc_html((string) ($deployment['updated_display'] ?? '—')); ?></td></tr>
                <tr id="wextstat-deployment-url-row" <?php echo empty($deployment['deployment_url']) ? 'hidden' : ''; ?>><th><?php esc_html_e('Deployment URL', 'wext-static-publisher'); ?></th><td><a id="wextstat-deployment-url" href="<?php echo esc_url((string) ($deployment['deployment_url'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) ($deployment['deployment_url'] ?? '')); ?></a></td></tr>
                <tr id="wextstat-deployment-error-row" <?php echo empty($deployment['error']) ? 'hidden' : ''; ?>><th><?php esc_html_e('Error', 'wext-static-publisher'); ?></th><td id="wextstat-deployment-error"><?php echo esc_html((string) ($deployment['error'] ?? '')); ?></td></tr>
                </tbody>
            </table>
        <?php endif; ?>

        <form class="wextstat-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wext_static_export">
            <?php wp_nonce_field('wext_static_export'); ?>
            <?php submit_button($cloudflare_configured ? __('Deploy to Cloudflare', 'wext-static-publisher') : __('Create Static Site', 'wext-static-publisher'), 'primary', 'submit', false, $is_active ? ['disabled' => 'disabled'] : []); ?>
            <a id="wextstat-download" class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wext_static_download'), 'wext_static_download')); ?>" <?php echo $archives === [] ? 'hidden' : ''; ?>><?php esc_html_e('Download', 'wext-static-publisher'); ?></a>
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
        <h2><?php esc_html_e('ZIP Files', 'wext-static-publisher'); ?></h2>
        <?php if ($cleanup_status === 'success') : ?>
            <div class="notice notice-success inline is-dismissible wextstat-files-notice"><p><?php echo esc_html(sprintf(__('The old ZIP file %d has been deleted.', 'wext-static-publisher'), absint($_GET['deleted'] ?? 0))); ?></p></div>
        <?php elseif ($cleanup_status === 'partial') : ?>
            <div class="notice notice-warning inline is-dismissible wextstat-files-notice"><p><?php echo esc_html(sprintf(__('%1$d old ZIP file was deleted, %2$d file could not be deleted.', 'wext-static-publisher'), absint($_GET['deleted'] ?? 0), absint($_GET['failed'] ?? 0))); ?></p></div>
        <?php elseif ($cleanup_status === 'running') : ?>
            <div class="notice notice-warning inline is-dismissible wextstat-files-notice"><p><?php esc_html_e('Old files cannot be deleted while export is in progress.', 'wext-static-publisher'); ?></p></div>
        <?php endif; ?>

        <?php if ($archive_notice === 'deleted') : ?>
            <div class="notice notice-success inline is-dismissible wextstat-files-notice"><p><?php echo esc_html(sprintf(__('%d ZIP file has been deleted.', 'wext-static-publisher'), absint($_GET['deleted'] ?? 0))); ?></p></div>
        <?php elseif ($archive_notice === 'partial') : ?>
            <div class="notice notice-warning inline is-dismissible wextstat-files-notice"><p><?php echo esc_html(sprintf(__('ZIP file %1$d was deleted, file %2$d could not be deleted.', 'wext-static-publisher'), absint($_GET['deleted'] ?? 0), absint($_GET['failed'] ?? 0))); ?></p></div>
        <?php elseif ($archive_notice === 'no-selection') : ?>
            <div class="notice notice-warning inline is-dismissible wextstat-files-notice"><p><?php esc_html_e('Select at least one ZIP file to process.', 'wext-static-publisher'); ?></p></div>
        <?php elseif ($archive_notice === 'invalid-action') : ?>
            <div class="notice notice-warning inline is-dismissible wextstat-files-notice"><p><?php esc_html_e('Select a valid batch action.', 'wext-static-publisher'); ?></p></div>
        <?php elseif ($archive_notice === 'running') : ?>
            <div class="notice notice-warning inline is-dismissible wextstat-files-notice"><p><?php esc_html_e('ZIP files cannot be deleted while export is in progress.', 'wext-static-publisher'); ?></p></div>
        <?php endif; ?>

        <?php if ($archives === []) : ?>
            <p><?php esc_html_e('There is no ZIP file created yet.', 'wext-static-publisher'); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wext_static_archive_bulk">
                <input type="hidden" name="zip_page" value="<?php echo esc_attr((string) $current_page); ?>">
                <?php wp_nonce_field('wext_static_archive_bulk'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label class="screen-reader-text" for="wextstat-bulk-action"><?php esc_html_e('Select Batch Action', 'wext-static-publisher'); ?></label>
                        <select id="wextstat-bulk-action" name="archive_bulk_action">
                            <option value=""><?php esc_html_e('Batch Process', 'wext-static-publisher'); ?></option>
                            <option value="download"><?php esc_html_e('Download Selected', 'wext-static-publisher'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete Selected', 'wext-static-publisher'); ?></option>
                        </select>
                        <?php submit_button(__('Apply', 'wext-static-publisher'), 'action', 'bulk_submit', false, [
                            'data-wextstat-confirm' => __('The selected ZIP files will be permanently deleted. Should we continue?', 'wext-static-publisher'),
                            'data-wextstat-confirm-action' => 'delete',
                        ]); ?>
                    </div>
                    <br class="clear">
                </div>
                <div class="wextstat-files-table-wrap">
                <table class="widefat striped wextstat-files-table">
                    <thead><tr><td class="manage-column check-column"><input id="cb-select-all-1" type="checkbox"><label for="cb-select-all-1"><span class="screen-reader-text"><?php esc_html_e('Select All', 'wext-static-publisher'); ?></span></label></td><th class="wextstat-number-column"><?php esc_html_e('Order', 'wext-static-publisher'); ?></th><th><?php esc_html_e('Job ID', 'wext-static-publisher'); ?></th><th><?php esc_html_e('URL Count', 'wext-static-publisher'); ?></th><th><?php esc_html_e('Creation Date', 'wext-static-publisher'); ?></th><th><?php esc_html_e('Creation Time', 'wext-static-publisher'); ?></th><th><?php esc_html_e('Actions', 'wext-static-publisher'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($visible_archives as $archive_index => $archive) : ?>
                        <?php
                        $archive_id = (string) $archive['id'];
                        $download_url = wp_nonce_url(
                            add_query_arg(['action' => 'wext_static_download_archive', 'archive_id' => $archive_id], admin_url('admin-post.php')),
                            'wext_static_download_archive_' . $archive_id
                        );
                        ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" name="archive_ids[]" value="<?php echo esc_attr($archive_id); ?>"><span class="screen-reader-text"><?php echo esc_html(sprintf(__('Select %s', 'wext-static-publisher'), (string) $archive['job_id'])); ?></span></th>
                            <td class="wextstat-number-column"><?php echo esc_html((string) ($archive_offset + $archive_index + 1)); ?></td>
                            <td><code><?php echo esc_html((string) $archive['job_id']); ?></code></td>
                            <td><?php echo $archive['url_count'] === null ? '—' : esc_html((string) $archive['url_count']); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $archive['created_at'])); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $archive['created_at'])); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($download_url); ?>"><?php esc_html_e('Download', 'wext-static-publisher'); ?></a> <button class="button button-small button-link-delete" type="submit" name="delete_archive" value="<?php echo esc_attr($archive_id); ?>" data-wextstat-confirm="<?php echo esc_attr__('This ZIP file will be permanently deleted. Do you want to continue?', 'wext-static-publisher'); ?>" data-wextstat-clear-bulk-action><?php esc_html_e('Delete', 'wext-static-publisher'); ?></button></td>
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
                        'prev_text' => __('‹ Previous', 'wext-static-publisher'),
                        'next_text' => __('Next ›', 'wext-static-publisher'),
                    ]);
                    ?>
                    <?php if (is_string($pagination)) : ?>
                        <nav class="wextstat-zip-pagination" aria-label="<?php echo esc_attr__('ZIP Files', 'wext-static-publisher'); ?>">
                            <?php echo wp_kses_post($pagination); ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <form class="wextstat-cleanup-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wext_static_cleanup_exports">
            <?php wp_nonce_field('wext_static_cleanup_exports'); ?>
            <?php submit_button(__('Delete Old Files', 'wext-static-publisher'), 'delete', 'submit', false, [
                'data-wextstat-confirm' => __('All old ZIP files except the latest ZIP will be deleted. Should we continue?', 'wext-static-publisher'),
            ]); ?>
            <p class="description"><?php esc_html_e('The latest ZIP file is preserved; Previous ZIP files and their temporary build folders are permanently deleted.', 'wext-static-publisher'); ?></p>
        </form>
        <?php
    }

    private static function render_deploy_tab(array $archives, array $settings, string $requested_deploy_tab): void
    {
        $has_archive = $archives !== [];
        $license_active = Plugin::license_active();
        $licensed_tabs = ['github', 'cloudflare', 'auto-deploy'];
        $github_configured = (string) ($settings['deployment_webhook_url'] ?? '') !== '';
        $sftp_configured = SFTP_Deployer::configured($settings);
        $deploy_tabs = [
            'zip' => [__('ZIP File', 'wext-static-publisher'), 'dashicons-media-archive'],
            'github' => [__('GitHub', 'wext-static-publisher'), 'github'],
            'cloudflare' => [__('Cloudflare', 'wext-static-publisher'), 'cloudflare'],
            'sftp' => [__('SFTP', 'wext-static-publisher'), 'dashicons-upload'],
            'auto-deploy' => [__('Auto Deploy', 'wext-static-publisher'), 'dashicons-update'],
        ];
        $current_deploy_tab = isset($deploy_tabs[$requested_deploy_tab]) ? $requested_deploy_tab : 'zip';
        ?>
        <div class="wextstat-deploy-header">
            <div>
                <h2><?php esc_html_e('Deploy', 'wext-static-publisher'); ?></h2>
            </div>
        </div>
        <div class="wextstat-deploy-layout">
            <nav class="wextstat-deploy-tabs" aria-label="<?php echo esc_attr__('Deploy', 'wext-static-publisher'); ?>">
                <?php foreach ($deploy_tabs as $deploy_tab => [$label, $icon]) : ?>
                    <?php $deploy_tab_locked = ! $license_active && in_array($deploy_tab, $licensed_tabs, true); ?>
                    <a class="wextstat-deploy-tab <?php echo $current_deploy_tab === $deploy_tab ? 'is-active' : ''; ?> <?php echo $deploy_tab_locked ? 'is-locked' : ''; ?>" href="<?php echo esc_url(self::deploy_page_url($deploy_tab)); ?>" <?php echo $current_deploy_tab === $deploy_tab ? 'aria-current="page"' : ''; ?>>
                        <?php if ($icon === 'github') : ?>
                            <?php self::render_github_icon('wextstat-deploy-tab__github-icon'); ?>
                        <?php elseif ($icon === 'cloudflare') : ?>
                            <?php self::render_cloudflare_icon('wextstat-deploy-tab__cloudflare-icon'); ?>
                        <?php else : ?>
                            <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span><?php echo esc_html($label); ?></span>
                        <?php if ($deploy_tab_locked) : ?><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="wextstat-deploy-panel">
                <?php if (! $license_active && in_array($current_deploy_tab, $licensed_tabs, true)) : ?>
                    <?php self::render_license_required_card($deploy_tabs[$current_deploy_tab][0]); ?>
                <?php elseif ($current_deploy_tab === 'zip') : ?>
                    <section class="wextstat-deploy-card" aria-labelledby="wextstat-deploy-zip-title">
                        <span class="wextstat-deploy-card__icon dashicons dashicons-media-archive" aria-hidden="true"></span>
                        <div class="wextstat-deploy-card__content">
                            <h3 id="wextstat-deploy-zip-title"><?php esc_html_e('ZIP File', 'wext-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Download the created static site package and manually install it on the desired server.', 'wext-static-publisher'); ?></p>
                        </div>
                        <div class="wextstat-deploy-card__footer">
                            <span class="wextstat-deploy-status <?php echo $has_archive ? 'is-ready' : 'is-pending'; ?>"><?php echo $has_archive ? __('ZIP ready', 'wext-static-publisher') : __('Create static site first', 'wext-static-publisher'); ?></span>
                        </div>
                        <div class="wextstat-deploy-card__files">
                            <?php self::render_zip_files($archives); ?>
                        </div>
                    </section>
                    <?php self::render_zip_settings($settings); ?>
                <?php elseif ($current_deploy_tab === 'github') : ?>
                    <section class="wextstat-deploy-card" aria-labelledby="wextstat-deploy-github-title">
                        <span class="wextstat-deploy-card__icon wextstat-deploy-card__github-icon" aria-hidden="true">
                            <?php self::render_github_icon('wextstat-github-icon'); ?>
                        </span>
                        <div class="wextstat-deploy-card__content">
                            <h3 id="wextstat-deploy-github-title"><?php esc_html_e('GitHub', 'wext-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Automatically trigger the GitHub Actions flow via webhook when the export is complete.', 'wext-static-publisher'); ?></p>
                        </div>
                        <div class="wextstat-deploy-card__footer">
                            <span class="wextstat-deploy-status <?php echo $github_configured ? 'is-ready' : 'is-pending'; ?>"><?php echo $github_configured ? __('Webhook configured', 'wext-static-publisher') : __('Configuration required', 'wext-static-publisher'); ?></span>
                        </div>
                    </section>
                    <?php self::render_deploy_settings($settings); ?>
                <?php elseif ($current_deploy_tab === 'cloudflare') : ?>
                    <?php
                    $deployment = Plugin::public_deployment_status();
                    $managed_license = Managed_Deployer::public_license();
                    $managed_connection = Managed_Deployer::public_connection();
                    $managed_notice = self::managed_notice();
                    $deployment_state = (string) ($deployment['state'] ?? '');
                    $deployment_labels = [
                        'waiting' => __('Waiting', 'wext-static-publisher'),
                        'dispatched' => __('Queued', 'wext-static-publisher'),
                        'deploying' => __('Running', 'wext-static-publisher'),
                        'completed' => __('Completed', 'wext-static-publisher'),
                        'failed' => __('Failed', 'wext-static-publisher'),
                    ];
                    $export_state = (string) (Plugin::public_status()['state'] ?? '');
                    $export_active = in_array($export_state, ['queued', 'running'], true);
                    ?>
                    <?php if ($managed_notice !== [] && (string) ($managed_notice['message'] ?? '') !== '') : ?>
                        <div class="notice notice-<?php echo esc_attr((string) ($managed_notice['type'] ?? 'error')); ?> inline is-dismissible" role="status"><p><?php echo esc_html((string) $managed_notice['message']); ?></p></div>
                    <?php endif; ?>
                    <section class="wextstat-deploy-card" aria-labelledby="wextstat-deploy-cloudflare-title">
                        <span class="wextstat-deploy-card__icon wextstat-deploy-card__cloudflare-icon" aria-hidden="true">
                            <?php self::render_cloudflare_icon('wextstat-cloudflare-icon'); ?>
                        </span>
                        <div class="wextstat-deploy-card__content">
                            <h3 id="wextstat-deploy-cloudflare-title"><?php esc_html_e('Cloudflare', 'wext-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Connect your licensed site and publish static files through the Wext deployment service. Cloudflare credentials stay in the service.', 'wext-static-publisher'); ?></p>
                        </div>
                        <div class="wextstat-deploy-card__footer">
                            <span class="wextstat-deploy-status <?php echo ! empty($managed_connection['connected']) ? 'is-ready' : 'is-pending'; ?>"><?php echo ! empty($managed_connection['connected']) ? esc_html($deployment_labels[$deployment_state] ?? __('Ready to deploy', 'wext-static-publisher')) : esc_html__('License and connection required', 'wext-static-publisher'); ?></span>
                        </div>
                    </section>
                    <section class="wextstat-deploy-card" aria-labelledby="wextstat-cloudflare-connection-title">
                        <div class="wextstat-deploy-card__content">
                            <h3 id="wextstat-cloudflare-connection-title"><?php esc_html_e('Cloudflare Connection', 'wext-static-publisher'); ?></h3>
                            <?php if (empty($managed_connection['connected'])) : ?>
                                <p><?php esc_html_e('Cloudflare authorization and provider tokens are handled by Wext. The plugin stores only site-scoped encrypted service credentials.', 'wext-static-publisher'); ?></p>
                                <?php if (! empty($managed_license['active']) && empty($managed_license['credential_available'])) : ?>
                                    <p class="description"><?php esc_html_e('The short-lived activation credential expired. Enter the license key again to refresh it.', 'wext-static-publisher'); ?></p>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="wext_static_license_activate">
                                        <?php wp_nonce_field('wext_static_license_activate'); ?>
                                        <label for="wextstat-license-key-refresh"><?php esc_html_e('License key', 'wext-static-publisher'); ?></label>
                                        <input id="wextstat-license-key-refresh" class="regular-text" type="password" name="license_key" required minlength="16" autocomplete="off">
                                        <?php submit_button(__('Refresh License Connection', 'wext-static-publisher'), 'secondary', 'submit', false); ?>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="wext_static_managed_connect">
                                    <?php wp_nonce_field('wext_static_managed_connect'); ?>
                                    <?php submit_button(__('Connect Cloudflare', 'wext-static-publisher'), 'primary', 'submit', false, (empty($managed_license['credential_available']) || empty($managed_license['available'])) ? ['disabled' => 'disabled'] : []); ?>
                                </form>
                            <?php else : ?>
                                <table class="widefat striped wextstat-status-table">
                                    <tbody>
                                    <tr><th><?php esc_html_e('Account', 'wext-static-publisher'); ?></th><td><?php echo esc_html((string) ($managed_connection['account_label'] ?? '—')); ?></td></tr>
                                    <tr><th><?php esc_html_e('Domain', 'wext-static-publisher'); ?></th><td><code><?php echo esc_html((string) ($managed_connection['domain'] ?? '—')); ?></code></td></tr>
                                    <tr><th><?php esc_html_e('Deployment URL', 'wext-static-publisher'); ?></th><td><a href="<?php echo esc_url((string) ($managed_connection['deployment_url'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) ($managed_connection['deployment_url'] ?? '')); ?></a></td></tr>
                                    </tbody>
                                </table>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="wext_static_managed_disconnect">
                                    <?php wp_nonce_field('wext_static_managed_disconnect'); ?>
                                    <?php submit_button(__('Disconnect Cloudflare', 'wext-static-publisher'), 'secondary', 'submit', false, ['data-wextstat-confirm' => __('Disconnect Cloudflare from this site?', 'wext-static-publisher')]); ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </section>
                    <section class="wextstat-deploy-card wextstat-cloudflare-deployment-details" aria-labelledby="wextstat-cloudflare-deployment-title">
                        <div class="wextstat-deploy-card__content">
                            <h3 id="wextstat-cloudflare-deployment-title"><?php esc_html_e('Cloudflare Deploy', 'wext-static-publisher'); ?></h3>
                            <table class="widefat striped wextstat-status-table">
                                <tbody>
                                <tr><th><?php esc_html_e('Status', 'wext-static-publisher'); ?></th><td><?php echo esc_html($deployment_labels[$deployment_state] ?? __('Not deployed yet', 'wext-static-publisher')); ?></td></tr>
                                <tr><th><?php esc_html_e('Job ID', 'wext-static-publisher'); ?></th><td><code><?php echo esc_html((string) ($deployment['job_id'] ?? '—')); ?></code></td></tr>
                                <tr><th><?php esc_html_e('Last Cloudflare Deploy', 'wext-static-publisher'); ?></th><td><?php echo esc_html((string) ($deployment['updated_display'] ?? '—')); ?></td></tr>
                                <?php if (! empty($deployment['deployment_url'])) : ?><tr><th><?php esc_html_e('Deployment URL', 'wext-static-publisher'); ?></th><td><a href="<?php echo esc_url((string) $deployment['deployment_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) $deployment['deployment_url']); ?></a></td></tr><?php endif; ?>
                                <?php if (! empty($deployment['error'])) : ?><tr><th><?php esc_html_e('Error', 'wext-static-publisher'); ?></th><td><?php echo esc_html((string) $deployment['error']); ?></td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="wextstat-deploy-card__footer">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="wext_static_export">
                                <?php wp_nonce_field('wext_static_export'); ?>
                                <?php submit_button($deployment_state === '' ? __('Deploy to Cloudflare', 'wext-static-publisher') : __('Deploy Again', 'wext-static-publisher'), 'primary', 'submit', false, (empty($managed_connection['connected']) || $export_active) ? ['disabled' => 'disabled'] : []); ?>
                            </form>
                        </div>
                    </section>
                <?php elseif ($current_deploy_tab === 'sftp') : ?>
                    <section class="wextstat-deploy-card" aria-labelledby="wextstat-deploy-sftp-title">
                        <span class="wextstat-deploy-card__icon dashicons dashicons-upload" aria-hidden="true"></span>
                        <div class="wextstat-deploy-card__content">
                            <h3 id="wextstat-deploy-sftp-title"><?php esc_html_e('SFTP', 'wext-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Upload the generated static files directly to a remote server over an encrypted SFTP connection.', 'wext-static-publisher'); ?></p>
                        </div>
                        <div class="wextstat-deploy-card__footer">
                            <span class="wextstat-deploy-status <?php echo $sftp_configured && SFTP_Deployer::available() ? 'is-ready' : 'is-pending'; ?>">
                                <?php echo $sftp_configured ? (SFTP_Deployer::available() ? esc_html__('SFTP configured', 'wext-static-publisher') : esc_html__('SFTP unavailable on this server', 'wext-static-publisher')) : esc_html__('Configuration required', 'wext-static-publisher'); ?>
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
        <svg class="<?php echo esc_attr($class_name); ?>" data-wextstat-github-icon viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M12 .7a11.3 11.3 0 0 0-3.57 22c.57.1.78-.24.78-.55v-2.17c-3.16.69-3.83-1.34-3.83-1.34-.52-1.31-1.26-1.66-1.26-1.66-1.03-.7.08-.69.08-.69 1.14.08 1.74 1.17 1.74 1.17 1.01 1.73 2.66 1.23 3.3.94.1-.73.4-1.23.72-1.51-2.52-.29-5.17-1.26-5.17-5.59 0-1.23.44-2.24 1.17-3.03-.12-.29-.51-1.44.11-2.99 0 0 .95-.31 3.11 1.16a10.75 10.75 0 0 1 5.67 0c2.16-1.47 3.11-1.16 3.11-1.16.62 1.55.23 2.7.11 2.99.73.79 1.17 1.8 1.17 3.03 0 4.34-2.66 5.3-5.19 5.58.41.35.77 1.04.77 2.1v3.11c0 .31.2.66.78.55A11.3 11.3 0 0 0 12 .7Z"/>
        </svg>
        <?php
    }

    private static function render_cloudflare_icon(string $class_name): void
    {
        ?>
        <svg class="<?php echo esc_attr($class_name); ?>" data-wextstat-cloudflare-icon viewBox="54 3 50 23" role="img" aria-hidden="true" focusable="false">
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
        <h2><?php esc_html_e('Activity Logs', 'wext-static-publisher'); ?></h2>
        <?php if ($activity['job_id'] !== '') : ?>
            <p class="wextstat-activity-meta">
                <span><?php esc_html_e('Records of the last static transaction:', 'wext-static-publisher'); ?> <code><?php echo esc_html((string) $activity['job_id']); ?></code></span>
                <span><?php esc_html_e('Number of Records:', 'wext-static-publisher'); ?> <strong><?php echo esc_html((string) $activity['job_total']); ?></strong></span>
            </p>
        <?php endif; ?>
        <form class="wextstat-activity-search" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="wext-static-publisher">
            <input type="hidden" name="tab" value="activity">
            <label class="screen-reader-text" for="wextstat-log-search"><?php esc_html_e('Search Log Records', 'wext-static-publisher'); ?></label>
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <input id="wextstat-log-search" type="search" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Search source or static URL...', 'wext-static-publisher'); ?>">
            <button class="button button-primary" type="submit"><?php esc_html_e('Search', 'wext-static-publisher'); ?></button>
            <?php if ($search !== '') : ?>
                <a class="button" href="<?php echo esc_url(self::admin_page_url('activity')); ?>"><?php esc_html_e('Clear Search', 'wext-static-publisher'); ?></a>
            <?php endif; ?>
        </form>
        <?php if ($entries === []) : ?>
            <div class="wextstat-empty-state">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <p><?php echo esc_html($search === '' ? __('No export activity has been recorded yet.', 'wext-static-publisher') : __('No log entries matched your search.', 'wext-static-publisher')); ?></p>
            </div>
        <?php else : ?>
            <table class="widefat striped wextstat-activity-table">
                <thead><tr><th><?php esc_html_e('Date', 'wext-static-publisher'); ?></th><th class="wextstat-time-column"><?php esc_html_e('Time', 'wext-static-publisher'); ?></th><th><?php esc_html_e('Source URL', 'wext-static-publisher'); ?></th><th><?php esc_html_e('Static URL', 'wext-static-publisher'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <?php
                    $timestamp = strtotime((string) ($entry['time'] ?? ''));
                    ?>
                    <tr>
                        <td><?php echo $timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format'), $timestamp)); ?></td>
                        <td class="wextstat-time-column"><?php echo $timestamp === false ? '—' : esc_html(wp_date((string) get_option('time_format'), $timestamp)); ?></td>
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
                <nav class="wextstat-pagination" aria-label="<?php echo esc_attr__('Activity log pages', 'wext-static-publisher'); ?>">
                    <span class="wextstat-pagination__summary"><?php echo esc_html(sprintf(__('%d records', 'wext-static-publisher'), (int) $activity['total'])); ?></span>
                    <?php
                    echo wp_kses_post((string) paginate_links([
                        'base' => add_query_arg('log_page', '%#%', $pagination_url),
                        'format' => '',
                        'current' => (int) $activity['page'],
                        'total' => (int) $activity['total_pages'],
                        'mid_size' => 2,
                        'end_size' => 1,
                        'prev_text' => __('‹ Previous', 'wext-static-publisher'),
                        'next_text' => __('Next ›', 'wext-static-publisher'),
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
            'general' => __('General', 'wext-static-publisher'),
            'headless' => __('Headless CMS', 'wext-static-publisher'),
            'multilingual' => __('Multilingual', 'wext-static-publisher'),
        ];
        $current_settings_tab = isset($settings_tabs[$requested_settings_tab]) ? $requested_settings_tab : 'general';
        ?>
        <div class="wextstat-settings-header">
            <h2><?php esc_html_e('Static Site', 'wext-static-publisher'); ?></h2>
            <p><?php esc_html_e('Manage static generation settings.', 'wext-static-publisher'); ?></p>
        </div>
        <div class="wextstat-settings-layout">
            <nav class="wextstat-settings-tabs" aria-label="<?php echo esc_attr__('Static Site subsections', 'wext-static-publisher'); ?>">
                <?php foreach ($settings_tabs as $settings_tab => $label) : ?>
                    <a class="wextstat-settings-tab <?php echo $current_settings_tab === $settings_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::settings_page_url($settings_tab)); ?>" <?php echo $current_settings_tab === $settings_tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="wextstat-settings-panel">
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
        $license_active = Plugin::license_active();
        $seo_tabs = [
            'plugins' => [__('SEO Plugins', 'wext-static-publisher'), 'dashicons-admin-plugins'],
            'audit' => [__('SEO Audit', 'wext-static-publisher'), 'dashicons-yes-alt'],
            'sitemaps' => [__('Sitemaps', 'wext-static-publisher'), 'dashicons-networking'],
            'redirects' => [__('Redirects', 'wext-static-publisher'), 'dashicons-randomize'],
            'indexing' => [__('Indexing', 'wext-static-publisher'), 'dashicons-visibility'],
            'performance' => [__('Performance', 'wext-static-publisher'), 'dashicons-performance'],
        ];
        $current_seo_tab = isset($seo_tabs[$requested_seo_tab]) ? $requested_seo_tab : 'plugins';
        ?>
        <div class="wextstat-seo-header">
            <h2><?php esc_html_e('SEO', 'wext-static-publisher'); ?></h2>
            <p><?php esc_html_e('Manage SEO plugin output and multilingual search engine signals in the static site.', 'wext-static-publisher'); ?></p>
        </div>
        <div class="wextstat-seo-layout">
            <nav class="wextstat-seo-tabs" aria-label="<?php echo esc_attr__('SEO', 'wext-static-publisher'); ?>">
                <?php foreach ($seo_tabs as $seo_tab => [$label, $icon]) : ?>
                    <a class="wextstat-seo-tab <?php echo $current_seo_tab === $seo_tab ? 'is-active' : ''; ?> <?php echo ! $license_active ? 'is-locked' : ''; ?>" href="<?php echo esc_url(self::seo_page_url($seo_tab)); ?>" <?php echo $current_seo_tab === $seo_tab ? 'aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <span><?php echo esc_html($label); ?></span>
                        <?php if (! $license_active) : ?><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="wextstat-seo-panel">
                <?php if (! $license_active) : ?>
                    <?php self::render_license_required_card(__('SEO features', 'wext-static-publisher')); ?>
                <?php elseif ($current_seo_tab === 'plugins') : ?>
                    <?php self::render_seo_plugins(Plugin::seo_plugin_settings()); ?>
                <?php else : ?>
                    <?php self::render_seo_toolkit_settings($current_seo_tab, $settings); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_license_required_card(string $feature): void
    {
        ?>
        <section class="wextstat-deploy-card wextstat-license-required-card" aria-labelledby="wextstat-license-required-title">
            <span class="wextstat-deploy-card__icon dashicons dashicons-lock" aria-hidden="true"></span>
            <div class="wextstat-deploy-card__content">
                <h3 id="wextstat-license-required-title"><?php esc_html_e('Active license required', 'wext-static-publisher'); ?></h3>
                <p><?php echo esc_html(sprintf(__('An active Wext license is required to use %s.', 'wext-static-publisher'), $feature)); ?></p>
            </div>
            <div class="wextstat-deploy-card__footer">
                <a class="button button-primary" href="<?php echo esc_url(self::admin_page_url('about')); ?>"><?php esc_html_e('Open License Settings', 'wext-static-publisher'); ?></a>
            </div>
        </section>
        <?php
    }

    private static function render_seo_toolkit_settings(string $section, array $settings): void
    {
        $option_name = Plugin::SEO_SETTINGS_KEY;
        $titles = [
            'audit' => [__('SEO Audit', 'wext-static-publisher'), __('Validate metadata, canonical URLs, structured data, images, links, and multilingual signals after every export.', 'wext-static-publisher')],
            'sitemaps' => [__('Advanced Sitemaps', 'wext-static-publisher'), __('Generate an indexable canonical sitemap with reliable modification dates, images, and language alternatives.', 'wext-static-publisher')],
            'redirects' => [__('Redirects', 'wext-static-publisher'), __('Export custom redirects and WordPress old slugs while reporting redirect chains and loops.', 'wext-static-publisher')],
            'indexing' => [__('Indexing Controls', 'wext-static-publisher'), __('Control indexing for HTML and non-HTML files and optionally notify IndexNow after automatic deployment.', 'wext-static-publisher')],
            'performance' => [__('Performance Audit', 'wext-static-publisher'), __('Report large files, large HTML documents, and render-blocking resources in the static package.', 'wext-static-publisher')],
        ];
        [$title, $description] = $titles[$section];
        ?>
        <form class="wextstat-settings-form wextstat-seo-toolkit-form" method="post" action="options.php">
            <?php settings_fields('wext_static_seo'); ?>
            <input type="hidden" name="<?php echo esc_attr($option_name); ?>[_section]" value="<?php echo esc_attr($section); ?>">
            <section class="wextstat-language-card">
                <div class="wextstat-language-card__heading"><span class="dashicons dashicons-search" aria-hidden="true"></span><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p></div></div>
                <div class="wextstat-seo-toolkit-fields">
                    <?php if ($section === 'audit') : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'audit_enabled', __('Create SEO audit report', 'wext-static-publisher'), __('Writes JSON and export diagnostics for every generated page.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'audit_html_report', __('Include readable HTML report', 'wext-static-publisher'), __('Adds wext-seo-report.html to the ZIP with noindex protection.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'canonical_fallback', __('Add missing canonical URLs', 'wext-static-publisher'), __('Uses the final static URL only when the page has no canonical element.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'schema_validation', __('Validate JSON-LD structured data', 'wext-static-publisher'), __('Reports invalid JSON-LD without inventing content or schema properties.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'multilingual_validation', __('Validate multilingual signals', 'wext-static-publisher'), __('Checks missing and non-reciprocal hreflang targets.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'image_audit', __('Audit image SEO', 'wext-static-publisher'), __('Reports missing alt attributes and explicit image dimensions.', 'wext-static-publisher')); ?>
                    <?php elseif ($section === 'sitemaps') : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'advanced_sitemap', __('Generate Wext sitemap', 'wext-static-publisher'), __('Creates wext-sitemap.xml and adds it to robots.txt.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_lastmod', __('Include accurate last modified dates', 'wext-static-publisher'), __('Uses the WordPress content modification time when available.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_images', __('Include images', 'wext-static-publisher'), __('Adds discoverable page images with absolute static URLs.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_hreflang', __('Include language alternatives', 'wext-static-publisher'), __('Adds existing hreflang relationships to sitemap entries.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_video', __('Generate video sitemap', 'wext-static-publisher'), __('Creates a separate sitemap only for videos with complete title, description, thumbnail, and upload date metadata.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'sitemap_news', __('Generate Google News sitemap', 'wext-static-publisher'), __('Creates a separate sitemap for posts published during the last two days. Enable only for eligible news sites.', 'wext-static-publisher')); ?>
                    <?php elseif ($section === 'redirects') : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'redirect_old_slugs', __('Redirect WordPress old slugs', 'wext-static-publisher'), __('Creates permanent redirects for published content with saved old slugs.', 'wext-static-publisher')); ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'redirect_import_plugins', __('Import redirect plugin rules', 'wext-static-publisher'), __('Imports compatible non-regex redirects from Redirection and Rank Math.', 'wext-static-publisher')); ?>
                        <label class="wextstat-seo-toolkit-field"><strong><?php esc_html_e('Custom Redirect Rules', 'wext-static-publisher'); ?></strong><textarea name="<?php echo esc_attr($option_name); ?>[redirect_rules]" rows="10" placeholder="/old-path/ /new-path/ 301"><?php echo esc_textarea((string) $settings['redirect_rules']); ?></textarea><span><?php esc_html_e('Enter one source, target, and optional status code per line. Supported codes: 301, 302, 303, 307, 308.', 'wext-static-publisher'); ?></span></label>
                    <?php elseif ($section === 'indexing') : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'site_noindex', __('Noindex the entire static output', 'wext-static-publisher'), __('Use only for staging or private static deployments. This blocks indexing through HTML and response headers.', 'wext-static-publisher')); ?>
                        <label class="wextstat-seo-toolkit-field"><strong><?php esc_html_e('Noindex Paths', 'wext-static-publisher'); ?></strong><textarea name="<?php echo esc_attr($option_name); ?>[noindex_paths]" rows="7" placeholder="/private/&#10;/landing-draft/*"><?php echo esc_textarea((string) $settings['noindex_paths']); ?></textarea><span><?php esc_html_e('One path prefix or wildcard pattern per line.', 'wext-static-publisher'); ?></span></label>
                        <label class="wextstat-seo-toolkit-field"><strong><?php esc_html_e('X-Robots-Tag Rules', 'wext-static-publisher'); ?></strong><textarea name="<?php echo esc_attr($option_name); ?>[x_robots_rules]" rows="7" placeholder="*.pdf|noindex"><?php echo esc_textarea((string) $settings['x_robots_rules']); ?></textarea><span><?php esc_html_e('Use pattern|directives format for non-HTML files.', 'wext-static-publisher'); ?></span></label>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'indexnow_enabled', __('Notify IndexNow after automatic deployment', 'wext-static-publisher'), __('Submits only added, changed, or deleted URLs after webhook or automatic SFTP deployment.', 'wext-static-publisher')); ?>
                        <label class="wextstat-seo-toolkit-field"><strong><?php esc_html_e('IndexNow Key', 'wext-static-publisher'); ?></strong><input type="password" autocomplete="new-password" name="<?php echo esc_attr($option_name); ?>[indexnow_key]" value="" placeholder="<?php echo (string) $settings['indexnow_key'] !== '' ? esc_attr__('Configured — leave blank to keep', 'wext-static-publisher') : ''; ?>"><span><?php esc_html_e('Use an 8–128 character key containing letters, numbers, or hyphens.', 'wext-static-publisher'); ?></span></label>
                    <?php else : ?>
                        <?php self::render_seo_setting_toggle($option_name, $settings, 'performance_audit', __('Create performance report', 'wext-static-publisher'), __('Adds wext-performance-report.json to the static package.', 'wext-static-publisher')); ?>
                        <div class="wextstat-seo-toolkit-grid">
                            <label class="wextstat-seo-toolkit-field"><strong><?php esc_html_e('Large HTML Threshold (KB)', 'wext-static-publisher'); ?></strong><input type="number" min="50" max="5000" name="<?php echo esc_attr($option_name); ?>[large_html_kb]" value="<?php echo esc_attr((string) $settings['large_html_kb']); ?>"></label>
                            <label class="wextstat-seo-toolkit-field"><strong><?php esc_html_e('Large Asset Threshold (KB)', 'wext-static-publisher'); ?></strong><input type="number" min="100" max="20000" name="<?php echo esc_attr($option_name); ?>[large_asset_kb]" value="<?php echo esc_attr((string) $settings['large_asset_kb']); ?>"></label>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php submit_button(__('Save SEO Settings', 'wext-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_seo_setting_toggle(string $option_name, array $settings, string $key, string $label, string $description): void
    {
        $field_id = 'wextstat-seo-' . str_replace('_', '-', $key);
        ?>
        <div class="wextstat-hide-toggle-row">
            <div><label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label><p><?php echo esc_html($description); ?></p></div>
            <label class="wextstat-switch" aria-label="<?php echo esc_attr($label); ?>"><input id="<?php echo esc_attr($field_id); ?>" type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked((string) ($settings[$key] ?? '0'), '1'); ?>><span aria-hidden="true"></span></label>
        </div>
        <?php
    }

    private static function render_seo_plugins(array $settings): void
    {
        $option_name = Plugin::SEO_PLUGIN_SETTINGS_KEY;
        $metadata_outputs = static function (string $prefix): array {
            return [
                $prefix . '_metadata_pages' => [__('Page Metadata', 'wext-static-publisher'), __('Keep SEO metadata on WordPress pages.', 'wext-static-publisher')],
                $prefix . '_metadata_posts' => [__('Post Metadata', 'wext-static-publisher'), __('Keep SEO metadata on WordPress posts.', 'wext-static-publisher')],
                $prefix . '_metadata_custom_post_types' => [__('Custom Post Type Metadata', 'wext-static-publisher'), __('Keep SEO metadata on public custom post types such as products and portfolios.', 'wext-static-publisher')],
                $prefix . '_metadata_archives' => [__('Archive & Taxonomy Metadata', 'wext-static-publisher'), __('Keep SEO metadata on the posts page, archives, categories, tags, author pages and other listing views.', 'wext-static-publisher')],
            ];
        };
        $additional_outputs = static function (string $prefix, string $label) use ($metadata_outputs): array {
            return array_merge($metadata_outputs($prefix), [
                $prefix . '_schema' => [__('Schema Structured Data', 'wext-static-publisher'), sprintf(__('Keep %s JSON-LD data, convert its URLs to the live domain, and adapt SearchAction to static search.', 'wext-static-publisher'), $label)],
                $prefix . '_sitemaps' => [__('XML Sitemaps', 'wext-static-publisher'), sprintf(__('Copy %s sitemap and its linked sitemap files into the static package.', 'wext-static-publisher'), $label)],
                $prefix . '_robots' => [__('Robots.txt', 'wext-static-publisher'), sprintf(__('Copy %s robots.txt rules and point sitemap declarations to the live static domain.', 'wext-static-publisher'), $label)],
            ]);
        };
        $plugins = [
            [
                'id' => 'rank-math',
                'prefix' => 'rank_math',
                'label' => 'Rank Math SEO',
                'active' => defined('RANK_MATH_VERSION'),
                'version' => defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : '',
                'description' => __('Choose which Rank Math outputs will be included in the generated static site.', 'wext-static-publisher'),
                'outputs' => array_merge($metadata_outputs('rank_math'), [
                    'rank_math_schema' => [__('Schema Structured Data', 'wext-static-publisher'), __('Keep Rank Math JSON-LD data, convert its URLs to the live domain, and adapt SearchAction to static search.', 'wext-static-publisher')],
                    'rank_math_sitemaps' => [__('XML Sitemaps', 'wext-static-publisher'), __('Copy the Rank Math sitemap index, child sitemaps and sitemap stylesheet into the static package.', 'wext-static-publisher')],
                    'rank_math_robots' => [__('Robots.txt', 'wext-static-publisher'), __('Copy Rank Math robots.txt rules and point the sitemap declaration to the live static domain.', 'wext-static-publisher')],
                ]),
            ],
            [
                'id' => 'aioseo',
                'prefix' => 'aioseo',
                'label' => 'All in One SEO',
                'active' => defined('AIOSEO_VERSION') || function_exists('aioseo'),
                'version' => defined('AIOSEO_VERSION') ? (string) AIOSEO_VERSION : '',
                'description' => __('Choose which All in One SEO outputs will be included in the generated static site.', 'wext-static-publisher'),
                'outputs' => array_merge($metadata_outputs('aioseo'), [
                    'aioseo_schema' => [__('Schema Structured Data', 'wext-static-publisher'), __('Keep All in One SEO JSON-LD data, convert its URLs to the live domain, and adapt SearchAction to static search.', 'wext-static-publisher')],
                    'aioseo_sitemaps' => [__('XML Sitemaps', 'wext-static-publisher'), __('Copy the All in One SEO sitemap index, child sitemaps and sitemap stylesheet into the static package.', 'wext-static-publisher')],
                    'aioseo_robots' => [__('Robots.txt', 'wext-static-publisher'), __('Copy All in One SEO robots.txt rules and point sitemap declarations to the live static domain.', 'wext-static-publisher')],
                ]),
            ],
            [
                'id' => 'seopress',
                'prefix' => 'seopress',
                'label' => 'SEOPress',
                'active' => defined('SEOPRESS_VERSION'),
                'version' => defined('SEOPRESS_VERSION') ? (string) SEOPRESS_VERSION : '',
                'description' => __('Choose which SEOPress outputs will be included in the generated static site.', 'wext-static-publisher'),
                'outputs' => array_merge($metadata_outputs('seopress'), [
                    'seopress_schema' => [__('Schema Structured Data', 'wext-static-publisher'), __('Keep SEOPress JSON-LD data, convert its URLs to the live domain, and adapt SearchAction to static search.', 'wext-static-publisher')],
                    'seopress_sitemaps' => [__('XML Sitemaps', 'wext-static-publisher'), __('Copy the SEOPress sitemap index, child sitemaps and sitemap stylesheets into the static package.', 'wext-static-publisher')],
                    'seopress_robots' => [__('Robots.txt', 'wext-static-publisher'), __('Copy SEOPress robots.txt rules and point sitemap declarations to the live static domain.', 'wext-static-publisher')],
                ]),
            ],
            [
                'id' => 'surerank',
                'prefix' => 'surerank',
                'label' => 'SureRank SEO',
                'active' => defined('SURERANK_VERSION'),
                'version' => defined('SURERANK_VERSION') ? (string) SURERANK_VERSION : '',
                'description' => sprintf(__('Choose which %s outputs will be included in the generated static site.', 'wext-static-publisher'), 'SureRank SEO'),
                'outputs' => $additional_outputs('surerank', 'SureRank SEO'),
            ],
            [
                'id' => 'seo-framework',
                'prefix' => 'seo_framework',
                'label' => 'The SEO Framework',
                'active' => defined('THE_SEO_FRAMEWORK_VERSION'),
                'version' => defined('THE_SEO_FRAMEWORK_VERSION') ? (string) THE_SEO_FRAMEWORK_VERSION : '',
                'description' => sprintf(__('Choose which %s outputs will be included in the generated static site.', 'wext-static-publisher'), 'The SEO Framework'),
                'outputs' => $additional_outputs('seo_framework', 'The SEO Framework'),
            ],
            [
                'id' => 'yoast',
                'prefix' => 'yoast',
                'label' => 'Yoast SEO',
                'active' => defined('WPSEO_VERSION'),
                'version' => defined('WPSEO_VERSION') ? (string) WPSEO_VERSION : '',
                'description' => sprintf(__('Choose which %s outputs will be included in the generated static site.', 'wext-static-publisher'), 'Yoast SEO'),
                'outputs' => $additional_outputs('yoast', 'Yoast SEO'),
            ],
        ];
        $plugins = array_merge(
            array_values(array_filter($plugins, static fn (array $plugin): bool => (bool) $plugin['active'])),
            array_values(array_filter($plugins, static fn (array $plugin): bool => ! $plugin['active']))
        );
        $active_plugins = array_values(array_filter($plugins, static fn (array $plugin): bool => (bool) $plugin['active']));
        ?>
        <form class="wextstat-settings-form wextstat-seo-plugins-form" method="post" action="options.php">
            <?php settings_fields('wext_static_seo_plugins'); ?>
            <?php if (count($active_plugins) === 1) : ?>
                <div class="wextstat-seo-plugin-overview is-ready">
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <span><strong><?php esc_html_e('Detected SEO plugin:', 'wext-static-publisher'); ?></strong> <?php echo esc_html((string) $active_plugins[0]['label']); ?></span>
                </div>
            <?php elseif (count($active_plugins) > 1) : ?>
                <div class="wextstat-seo-plugin-overview is-warning">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <span><?php esc_html_e('Multiple active SEO plugins were detected. Review their output settings to prevent duplicate metadata.', 'wext-static-publisher'); ?></span>
                </div>
            <?php else : ?>
                <div class="wextstat-seo-plugin-overview">
                    <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                    <span><?php esc_html_e('No active SEO plugin was detected. Saved settings will be used when a supported plugin is activated.', 'wext-static-publisher'); ?></span>
                </div>
            <?php endif; ?>
            <?php foreach ($plugins as $index => $plugin) : ?>
                <?php self::render_seo_plugin_card($plugin, $option_name, $settings, (bool) $plugin['active'] && $index === 0); ?>
            <?php endforeach; ?>
            <div class="wextstat-seo-save-bar">
                <?php submit_button(__('Save SEO Plugin Settings', 'wext-static-publisher')); ?>
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
        $panel_id = 'wextstat-' . $id . '-settings';
        $metadata_outputs = array_filter($outputs, static fn (string $key): bool => str_contains($key, '_metadata_'), ARRAY_FILTER_USE_KEY);
        $technical_outputs = array_diff_key($outputs, $metadata_outputs);
        ?>
        <section class="wextstat-seo-plugin-card <?php echo $expanded ? 'is-expanded' : ''; ?>" aria-labelledby="wextstat-<?php echo esc_attr($id); ?>-title" data-wextstat-seo-card>
            <div class="wextstat-seo-plugin-card__heading">
                <button class="wextstat-seo-plugin-card__toggle" type="button" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr($panel_id); ?>" data-wextstat-seo-toggle>
                    <span class="wextstat-seo-plugin-card__icon" aria-hidden="true">
                        <?php self::render_seo_plugin_icon($id); ?>
                    </span>
                    <span class="wextstat-seo-plugin-card__identity">
                        <strong id="wextstat-<?php echo esc_attr($id); ?>-title"><?php echo esc_html($label); ?></strong>
                        <small><?php echo esc_html((string) $plugin['description']); ?></small>
                    </span>
                    <span class="dashicons dashicons-arrow-down-alt2 wextstat-seo-plugin-card__chevron" aria-hidden="true"></span>
                </button>
                <div class="wextstat-seo-plugin-card__summary">
                    <span class="wextstat-deploy-status <?php echo $active ? 'is-ready' : 'is-pending'; ?>">
                        <?php echo $active && $version !== '' ? esc_html(sprintf(__('Active — version %s', 'wext-static-publisher'), $version)) : ($active ? esc_html__('Active', 'wext-static-publisher') : esc_html__('Not active', 'wext-static-publisher')); ?>
                    </span>
                    <span class="wextstat-seo-output-count" data-wextstat-output-count><strong><?php echo esc_html((string) $selected); ?></strong> / <?php echo esc_html((string) count($outputs)); ?></span>
                    <label class="wextstat-seo-plugin-enable">
                        <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($prefix); ?>_enabled]" value="1" data-wextstat-seo-master <?php checked((string) ($settings[$prefix . '_enabled'] ?? '0'), '1'); ?>>
                        <span><?php echo esc_html(sprintf(__('Include %s outputs in the static site', 'wext-static-publisher'), $label)); ?></span>
                    </label>
                </div>
            </div>
            <div class="wextstat-seo-plugin-card__body" id="<?php echo esc_attr($panel_id); ?>" data-wextstat-seo-panel <?php echo $expanded ? '' : 'hidden'; ?>>
                <p class="wextstat-seo-plugin-card__master-note"><?php echo esc_html(sprintf(__('When disabled, %s metadata and schema outputs are removed and its sitemap and robots files are not exported.', 'wext-static-publisher'), $label)); ?></p>
                <?php self::render_seo_output_group(__('Metadata', 'wext-static-publisher'), $metadata_outputs, $option_name, $settings); ?>
                <?php self::render_seo_output_group(__('Technical SEO', 'wext-static-publisher'), $technical_outputs, $option_name, $settings); ?>
                <?php if (! $active) : ?>
                    <p class="wextstat-language-card__note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php echo esc_html(sprintf(__('These settings are preserved, but no %s output will be exported until the plugin is active.', 'wext-static-publisher'), $label)); ?></p>
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
        <fieldset class="wextstat-seo-output-group">
            <legend><?php echo esc_html($legend); ?></legend>
            <div class="wextstat-seo-output-list">
                <?php foreach ($outputs as $key => [$output_label, $description]) : ?>
                    <label class="wextstat-seo-output-option">
                        <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($key); ?>]" value="1" data-wextstat-seo-output <?php checked((string) ($settings[$key] ?? '0'), '1'); ?>>
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
        <form class="wextstat-settings-form" method="post" action="options.php">
            <?php settings_fields('wext_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="general">
            <table class="form-table" role="presentation">
                <tr><th><label for="wextstat-target"><?php esc_html_e('Live Site Address', 'wext-static-publisher'); ?></label></th><td><input class="regular-text" id="wextstat-target" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[target_url]" value="<?php echo esc_attr((string) $settings['target_url']); ?>"><p class="description"><?php esc_html_e('Example: https://example.com', 'wext-static-publisher'); ?></p></td></tr>
                <tr><th><label for="wextstat-limit"><?php esc_html_e('Most URLs', 'wext-static-publisher'); ?></label></th><td><input id="wextstat-limit" type="number" min="10" max="20000" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[maximum_urls]" value="<?php echo esc_attr((string) $settings['maximum_urls']); ?>"></td></tr>
                <tr><th><label for="wextstat-excluded"><?php esc_html_e('Excluded Paths', 'wext-static-publisher'); ?></label></th><td><textarea class="large-text code" rows="7" id="wextstat-excluded" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[excluded_paths]"><?php echo esc_textarea((string) $settings['excluded_paths']); ?></textarea><p class="description"><?php esc_html_e('One path prefix per line.', 'wext-static-publisher'); ?></p></td></tr>
            </table>
            <?php submit_button(__('Save General Settings', 'wext-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_headless_settings(array $settings): void
    {
        $option_name = Plugin::SETTINGS_KEY;
        ?>
        <form class="wextstat-settings-form" method="post" action="options.php">
            <?php settings_fields('wext_static'); ?>
            <input type="hidden" name="<?php echo esc_attr($option_name); ?>[_section]" value="headless">
            <div class="notice notice-info inline wextstat-headless-notice">
                <p><strong><?php esc_html_e('Headless + Static Publisher', 'wext-static-publisher'); ?></strong></p>
                <p><?php esc_html_e('Visitors cannot open the WordPress theme frontend. Signed internal export requests can still render the homepage, pages, design assets, and menus for the static site.', 'wext-static-publisher'); ?></p>
            </div>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e('Headless CMS Mode', 'wext-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_enabled]" value="1" <?php checked((string) ($settings['headless_enabled'] ?? '0'), '1'); ?>> <?php esc_html_e('Protect the WordPress theme frontend', 'wext-static-publisher'); ?></label><p class="description"><?php esc_html_e('WordPress Admin, REST API, media files, cron, and signed static export requests remain available.', 'wext-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="wextstat-headless-behavior"><?php esc_html_e('Visitor Response', 'wext-static-publisher'); ?></label></th>
                    <td><select id="wextstat-headless-behavior" name="<?php echo esc_attr($option_name); ?>[headless_frontend_behavior]">
                        <option value="404" <?php selected((string) ($settings['headless_frontend_behavior'] ?? '404'), '404'); ?>><?php esc_html_e('404 Not Found (recommended)', 'wext-static-publisher'); ?></option>
                        <option value="redirect" <?php selected((string) ($settings['headless_frontend_behavior'] ?? '404'), 'redirect'); ?>><?php esc_html_e('307 Redirect to frontend', 'wext-static-publisher'); ?></option>
                        <option value="410" <?php selected((string) ($settings['headless_frontend_behavior'] ?? '404'), '410'); ?>><?php esc_html_e('410 Gone', 'wext-static-publisher'); ?></option>
                    </select></td>
                </tr>
                <tr>
                    <th><label for="wextstat-headless-frontend-url"><?php esc_html_e('Frontend Address', 'wext-static-publisher'); ?></label></th>
                    <td><input class="regular-text" id="wextstat-headless-frontend-url" type="url" name="<?php echo esc_attr($option_name); ?>[headless_frontend_url]" value="<?php echo esc_attr((string) ($settings['headless_frontend_url'] ?? '')); ?>" placeholder="https://www.example.com"><p class="description"><?php esc_html_e('Required only when visitors should be redirected. It must use a different origin than WordPress.', 'wext-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Redirect Paths', 'wext-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_preserve_path]" value="1" <?php checked((string) ($settings['headless_preserve_path'] ?? '1'), '1'); ?>> <?php esc_html_e('Preserve the requested path when redirecting', 'wext-static-publisher'); ?></label><p class="description"><?php esc_html_e('Example: /about/ redirects to the frontend /about/. Query parameters are not forwarded.', 'wext-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Editor Preview', 'wext-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_allow_authenticated_preview]" value="1" <?php checked((string) ($settings['headless_allow_authenticated_preview'] ?? '1'), '1'); ?>> <?php esc_html_e('Allow signed-in editors to view the WordPress theme', 'wext-static-publisher'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('GraphQL', 'wext-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_allow_graphql]" value="1" <?php checked((string) ($settings['headless_allow_graphql'] ?? '0'), '1'); ?>> <?php esc_html_e('Allow the /graphql endpoint when WPGraphQL is installed', 'wext-static-publisher'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('CMS Indexing', 'wext-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_noindex]" value="1" <?php checked((string) ($settings['headless_noindex'] ?? '1'), '1'); ?>> <?php esc_html_e('Block indexing on the WordPress CMS origin', 'wext-static-publisher'); ?></label><p class="description"><?php esc_html_e('Returns noindex headers, disables the CMS sitemap, and disallows crawling through robots.txt without changing the generated static site.', 'wext-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Legacy Publishing', 'wext-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_disable_xmlrpc]" value="1" <?php checked((string) ($settings['headless_disable_xmlrpc'] ?? '1'), '1'); ?>> <?php esc_html_e('Disable XML-RPC requests', 'wext-static-publisher'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Comments and Pingbacks', 'wext-static-publisher'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[headless_disable_comments]" value="1" <?php checked((string) ($settings['headless_disable_comments'] ?? '1'), '1'); ?>> <?php esc_html_e('Close comments and pingbacks on the CMS origin', 'wext-static-publisher'); ?></label></td>
                </tr>
            </table>
            <?php submit_button(__('Save Headless CMS Settings', 'wext-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_zip_settings(array $settings): void
    {
        ?>
        <form class="wextstat-settings-form wextstat-zip-settings-form" method="post" action="options.php">
            <?php settings_fields('wext_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="zip">
            <table class="form-table" role="presentation">
                <tr><th><label for="wextstat-archive-retention"><?php esc_html_e('Number of ZIPs to Store', 'wext-static-publisher'); ?></label></th><td><input id="wextstat-archive-retention" type="number" min="1" max="100" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[archive_retention]" value="<?php echo esc_attr((string) $settings['archive_retention']); ?>"><p class="description"><?php esc_html_e('Determines how many last successful export archives will be stored. Default: 5.', 'wext-static-publisher'); ?></p></td></tr>
            </table>
            <?php submit_button(__('Save ZIP Settings', 'wext-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_automation_settings(array $settings): void
    {
        $auto_export_groups = [
            __('Contents', 'wext-static-publisher') => [
                'auto_export_post_created' => [__('When a new article is published', 'wext-static-publisher'), __('When a new blog post is published for the first time.', 'wext-static-publisher')],
                'auto_export_post_updated' => [__('When the current post is updated', 'wext-static-publisher'), __('When a live blog post is changed or removed.', 'wext-static-publisher')],
                'auto_export_page_created' => [__('When the new page is published', 'wext-static-publisher'), __('When a new page is published for the first time.', 'wext-static-publisher')],
                'auto_export_page_updated' => [__('When the current page is updated', 'wext-static-publisher'), __('When a published page is changed or removed.', 'wext-static-publisher')],
                'auto_export_custom_content' => [__('When custom content types change', 'wext-static-publisher'), __('When product, portfolio, etc. special content in the publication changes.', 'wext-static-publisher')],
            ],
            __('Structure and Design', 'wext-static-publisher') => [
                'auto_export_taxonomy' => [__('When the category or tag changes', 'wext-static-publisher'), __('When categories, tags, or custom classifications are changed.', 'wext-static-publisher')],
                'auto_export_media' => [__('When the media changes', 'wext-static-publisher'), __('When an image or other media file is added, updated, or deleted.', 'wext-static-publisher')],
                'auto_export_menu' => [__('When the menu changes', 'wext-static-publisher'), __('When the structure or links of navigation menus are changed.', 'wext-static-publisher')],
                'auto_export_widgets' => [__('When components change', 'wext-static-publisher'), __('When widget and widget layouts are updated.', 'wext-static-publisher')],
                'auto_export_theme' => [__('When the theme changes', 'wext-static-publisher'), __('When the theme is changed, updated, or customizer settings are saved.', 'wext-static-publisher')],
                'auto_export_site_settings' => [__('When site settings change', 'wext-static-publisher'), __('When the site name, description, homepage, reading or permalink settings are changed.', 'wext-static-publisher')],
            ],
        ];
        ?>
        <form class="wextstat-settings-form wextstat-automation-form" method="post" action="options.php">
            <?php settings_fields('wext_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="automation">
            <section class="wextstat-auto-export-card" aria-labelledby="wextstat-auto-export-title">
                <div class="wextstat-auto-export-card__heading">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <div>
                        <h3 id="wextstat-auto-export-title"><?php esc_html_e('Automatic Static Site Creation and Deploy', 'wext-static-publisher'); ?></h3>
                        <p><?php esc_html_e('After the selected changes, the static site is created; If the deployment webhook is set, the deploy flow is triggered automatically.', 'wext-static-publisher'); ?></p>
                    </div>
                    <label class="wextstat-switch" aria-label="<?php echo esc_attr__('Enable automatic static site generation', 'wext-static-publisher'); ?>">
                        <input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[auto_export]" value="1" <?php checked((string) $settings['auto_export'], '1'); ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>
                <div class="wextstat-auto-export-groups">
                    <?php foreach ($auto_export_groups as $group_label => $triggers) : ?>
                        <fieldset class="wextstat-auto-export-group">
                            <legend><?php echo esc_html($group_label); ?></legend>
                            <?php foreach ($triggers as $trigger => [$label, $description]) : ?>
                                <label class="wextstat-auto-export-option">
                                    <input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[<?php echo esc_attr($trigger); ?>]" value="1" <?php checked((string) ($settings[$trigger] ?? '0'), '1'); ?>>
                                    <span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($description); ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <p class="wextstat-auto-export-card__note"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e('Changes made consecutively are combined for 60 seconds and run as a single export and deploy.', 'wext-static-publisher'); ?></p>
            </section>
            <?php submit_button(__('Save Auto Deploy Settings', 'wext-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_deploy_settings(array $settings): void
    {
        ?>
        <form class="wextstat-settings-form wextstat-deploy-settings-form" method="post" action="options.php">
            <?php settings_fields('wext_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="deploy">
            <h3><?php esc_html_e('GitHub Deployment Webhook', 'wext-static-publisher'); ?></h3>
            <table class="form-table" role="presentation">
                <tr><th><label for="wextstat-webhook"><?php esc_html_e('Deployment Webhook', 'wext-static-publisher'); ?></label></th><td><input class="large-text code" id="wextstat-webhook" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_url]" value="<?php echo esc_attr((string) $settings['deployment_webhook_url']); ?>"><p class="description"><?php esc_html_e('For GitHub: https://api.github.com/repos/OWNER/REPOSITORY/dispatches', 'wext-static-publisher'); ?></p></td></tr>
                <tr><th><label for="wextstat-webhook-token"><?php esc_html_e('Webhook Bearer Token', 'wext-static-publisher'); ?></label></th><td><input class="regular-text" id="wextstat-webhook-token" type="password" autocomplete="new-password" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_token]" value="" placeholder="<?php echo $settings['deployment_webhook_token'] !== '' ? esc_attr__('Leave blank to keep the saved token', 'wext-static-publisher') : ''; ?>"><p class="description"><?php esc_html_e('If using GitHub, use a fine-grained token limited to this repository with Contents: write permission.', 'wext-static-publisher'); ?></p></td></tr>
            </table>
            <?php submit_button(__('Save Deploy Settings', 'wext-static-publisher')); ?>
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
            <div class="notice <?php echo $notice === 'error' ? 'notice-error' : 'notice-success'; ?> inline is-dismissible wextstat-sftp-notice" role="status"><p><?php echo esc_html($status_message); ?></p></div>
        <?php endif; ?>
        <?php if (! $available) : ?>
            <div class="notice notice-error inline wextstat-sftp-notice" role="alert"><p><?php esc_html_e('The SFTP client is unavailable. Reinstall the complete plugin package before using this deployment method.', 'wext-static-publisher'); ?></p></div>
        <?php endif; ?>

        <form class="wextstat-settings-form wextstat-sftp-settings-form" method="post" action="options.php">
            <?php settings_fields('wext_static'); ?>
            <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[_section]" value="sftp">
            <h3><?php esc_html_e('SFTP Connection', 'wext-static-publisher'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="wextstat-sftp-host"><?php esc_html_e('SFTP Host', 'wext-static-publisher'); ?></label></th>
                    <td><input class="regular-text code" id="wextstat-sftp-host" type="text" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_host]" value="<?php echo esc_attr((string) $settings['sftp_host']); ?>" placeholder="sftp.example.com"><p class="description"><?php esc_html_e('Enter only the hostname or IP address; do not include sftp://.', 'wext-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="wextstat-sftp-port"><?php esc_html_e('Port', 'wext-static-publisher'); ?></label></th>
                    <td><input id="wextstat-sftp-port" type="number" min="1" max="65535" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_port]" value="<?php echo esc_attr((string) $settings['sftp_port']); ?>"></td>
                </tr>
                <tr>
                    <th><label for="wextstat-sftp-username"><?php esc_html_e('Username', 'wext-static-publisher'); ?></label></th>
                    <td><input class="regular-text" id="wextstat-sftp-username" type="text" autocomplete="username" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_username]" value="<?php echo esc_attr((string) $settings['sftp_username']); ?>"></td>
                </tr>
                <tr>
                    <th><label for="wextstat-sftp-password"><?php esc_html_e('Password', 'wext-static-publisher'); ?></label></th>
                    <td><input class="regular-text" id="wextstat-sftp-password" type="password" autocomplete="new-password" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_password]" value="" placeholder="<?php echo (string) $settings['sftp_password'] !== '' ? esc_attr__('Leave blank to keep the saved password', 'wext-static-publisher') : ''; ?>"><p class="description"><?php esc_html_e('The password is encrypted using the WordPress security keys and is never shown again.', 'wext-static-publisher'); ?></p><?php if ((string) $settings['sftp_password'] !== '') : ?><label class="wextstat-sftp-clear-secret"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_clear_password]" value="1"> <?php esc_html_e('Delete saved password', 'wext-static-publisher'); ?></label><?php endif; ?></td>
                </tr>
                <tr>
                    <th><label for="wextstat-sftp-path"><?php esc_html_e('Remote Directory', 'wext-static-publisher'); ?></label></th>
                    <td><input class="regular-text code" id="wextstat-sftp-path" type="text" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_remote_path]" value="<?php echo esc_attr((string) $settings['sftp_remote_path']); ?>" placeholder="/public_html"><p class="description"><?php esc_html_e('Static files are uploaded into this directory. Existing files with the same path are overwritten; unrelated remote files are preserved.', 'wext-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="wextstat-sftp-fingerprint"><?php esc_html_e('Server MD5 Fingerprint', 'wext-static-publisher'); ?></label></th>
                    <td><input class="regular-text code" id="wextstat-sftp-fingerprint" type="text" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_host_fingerprint]" value="<?php echo esc_attr((string) $settings['sftp_host_fingerprint']); ?>" placeholder="0123456789abcdef0123456789abcdef"><p class="description"><?php esc_html_e('Recommended. Verify this value with your hosting provider to prevent connecting to the wrong server.', 'wext-static-publisher'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="wextstat-sftp-timeout"><?php esc_html_e('Per-file Timeout', 'wext-static-publisher'); ?></label></th>
                    <td><input id="wextstat-sftp-timeout" type="number" min="10" max="600" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_timeout]" value="<?php echo esc_attr((string) $settings['sftp_timeout']); ?>"> <?php esc_html_e('seconds', 'wext-static-publisher'); ?></td>
                </tr>
            </table>
            <label class="wextstat-sftp-auto-deploy"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[sftp_auto_deploy]" value="1" <?php checked((string) $settings['sftp_auto_deploy'], '1'); ?>> <span><strong><?php esc_html_e('Upload automatically after every successful export', 'wext-static-publisher'); ?></strong><small><?php esc_html_e('An SFTP failure is recorded separately and does not delete the successfully generated ZIP file.', 'wext-static-publisher'); ?></small></span></label>
            <?php submit_button(__('Save SFTP Settings', 'wext-static-publisher')); ?>
        </form>

        <div class="wextstat-sftp-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wext_static_sftp_test">
                <?php wp_nonce_field('wext_static_sftp_test'); ?>
                <?php submit_button(__('Test Connection', 'wext-static-publisher'), 'secondary', 'submit', false, ! $available || ! $configured ? ['disabled' => 'disabled'] : []); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wext_static_sftp_deploy">
                <?php wp_nonce_field('wext_static_sftp_deploy'); ?>
                <?php submit_button(__('Upload Latest Static Site', 'wext-static-publisher'), 'primary', 'submit', false, ! $available || ! $configured || ! $has_archive ? ['disabled' => 'disabled'] : []); ?>
            </form>
        </div>
        <?php if ($status_message !== '' && $notice === '') : ?>
            <div class="wextstat-sftp-last-status"><strong><?php esc_html_e('Last SFTP Status', 'wext-static-publisher'); ?>:</strong> <?php echo esc_html($status_message); ?><?php if ($status_time !== false) : ?> <span>— <?php echo esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $status_time)); ?></span><?php endif; ?></div>
        <?php endif; ?>
        <?php
    }

    private static function render_language_settings(array $settings): void
    {
        ?>
        <form class="wextstat-settings-form wextstat-language-settings-form" method="post" action="options.php">
            <?php settings_fields('wext_static_languages'); ?>
            <section class="wextstat-language-card" aria-labelledby="wextstat-language-title">
                <div class="wextstat-language-card__heading">
                    <span class="dashicons dashicons-translation" aria-hidden="true"></span>
                    <div>
                        <h3 id="wextstat-language-title"><?php esc_html_e('Redirection by Browser Language', 'wext-static-publisher'); ?></h3>
                        <p><?php esc_html_e('When the visitor arrives at the main address, first the saved preference is used, then the language sequence of the browser.', 'wext-static-publisher'); ?></p>
                    </div>
                    <label class="wextstat-switch" aria-label="<?php echo esc_attr__('Enable browser language redirection', 'wext-static-publisher'); ?>">
                        <input type="checkbox" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[enabled]" value="1" <?php checked((string) ($settings['enabled'] ?? '0'), '1'); ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>
                <div class="wextstat-language-grid">
                    <div>
                        <label for="wextstat-supported-languages"><?php esc_html_e('Supported Language Codes', 'wext-static-publisher'); ?></label>
                        <textarea id="wextstat-supported-languages" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[supported_languages]" rows="6" spellcheck="false"><?php echo esc_textarea((string) ($settings['supported_languages'] ?? '')); ?></textarea>
                        <p><?php esc_html_e('Write one language per line. These codes in WordPress', 'wext-static-publisher'); ?> <code>/tr/</code>, <code>/en/</code> <?php esc_html_e('There should be equivalents like this.', 'wext-static-publisher'); ?></p>
                    </div>
                    <div>
                        <label for="wextstat-default-language"><?php esc_html_e('Default Language', 'wext-static-publisher'); ?></label>
                        <input id="wextstat-default-language" type="text" maxlength="20" spellcheck="false" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[default_language]" value="<?php echo esc_attr((string) ($settings['default_language'] ?? Language_Routing::site_language())); ?>">
                        <p><?php esc_html_e('If the browser language is not supported, this language is opened.', 'wext-static-publisher'); ?></p>

                        <label for="wextstat-language-cookie-days"><?php esc_html_e('Language Preference Period', 'wext-static-publisher'); ?></label>
                        <div class="wextstat-input-with-suffix">
                            <input id="wextstat-language-cookie-days" type="number" min="1" max="3650" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[cookie_days]" value="<?php echo esc_attr((string) ($settings['cookie_days'] ?? 365)); ?>">
                            <span><?php esc_html_e('day', 'wext-static-publisher'); ?></span>
                        </div>
                        <p><?php esc_html_e('The language that the user explicitly visited is preserved for subsequent logins.', 'wext-static-publisher'); ?></p>
                    </div>
                </div>
                <p class="wextstat-language-card__note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e('The redirect is only for the site', 'wext-static-publisher'); ?> <code>/</code> <?php esc_html_e('It works at. Direct links containing languages ​​are not changed.', 'wext-static-publisher'); ?></p>
            </section>
            <?php submit_button(__('Save Language Settings', 'wext-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_search_tab(array $settings, string $requested_search_tab): void
    {
        $option_name = Plugin::SEARCH_SETTINGS_KEY;
        $search_tabs = [
            'static' => [__('Static Search', 'wext-static-publisher'), 'dashicons-search'],
            'selectors' => [__('Indexing Selectors', 'wext-static-publisher'), 'dashicons-filter'],
            'fuse' => [__('Fuse.js', 'wext-static-publisher'), 'dashicons-chart-bar'],
        ];
        $current_search_tab = isset($search_tabs[$requested_search_tab]) ? $requested_search_tab : 'static';
        ?>
        <div class="wextstat-search-header">
            <div>
                <h2><?php esc_html_e('Search', 'wext-static-publisher'); ?></h2>
                <p><?php esc_html_e('Configure static site search that doesn\'t require a server, powered by Fuse.js.', 'wext-static-publisher'); ?></p>
            </div>
            <span class="wextstat-search-badge">Fuse.js 7.3.0</span>
        </div>
        <div class="wextstat-search-layout">
            <nav class="wextstat-search-tabs" aria-label="<?php echo esc_attr__('Search', 'wext-static-publisher'); ?>">
                <?php foreach ($search_tabs as $search_tab => [$label, $icon]) : ?>
                    <a class="wextstat-search-tab <?php echo $current_search_tab === $search_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::search_page_url($search_tab)); ?>" <?php echo $current_search_tab === $search_tab ? 'aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <span><?php echo esc_html($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="wextstat-search-panel">
                <form class="wextstat-search-settings" method="post" action="options.php">
                    <?php settings_fields('wext_static_search'); ?>
                    <input type="hidden" name="<?php echo esc_attr($option_name); ?>[_section]" value="<?php echo esc_attr($current_search_tab); ?>">

                    <?php if ($current_search_tab === 'static') : ?>
                        <section class="wextstat-search-card">
                            <div class="wextstat-search-card__heading">
                                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                <div><h3><?php esc_html_e('Static Search', 'wext-static-publisher'); ?></h3><p><?php esc_html_e('The search page, index and necessary Fuse.js files are added to the export ZIP.', 'wext-static-publisher'); ?></p></div>
                                <label class="wextstat-switch" aria-label="<?php echo esc_attr__('Enable static search', 'wext-static-publisher'); ?>">
                                    <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[enabled]" value="1" <?php checked((string) $settings['enabled'], '1'); ?>>
                                    <span aria-hidden="true"></span>
                                </label>
                            </div>
                            <div class="wextstat-search-grid">
                                <div><label for="wextstat-search-path"><?php esc_html_e('Search Page Path', 'wext-static-publisher'); ?></label><div class="wextstat-input-group is-compact"><span>/</span><input id="wextstat-search-path" type="text" name="<?php echo esc_attr($option_name); ?>[page_path]" value="<?php echo esc_attr((string) $settings['page_path']); ?>" required><span>/</span></div><p><?php esc_html_e('Example:', 'wext-static-publisher'); ?> <code>/search/</code></p></div>
                                <div><label for="wextstat-search-limit"><?php esc_html_e('Number of Results to Show', 'wext-static-publisher'); ?></label><input id="wextstat-search-limit" type="number" min="5" max="100" name="<?php echo esc_attr($option_name); ?>[result_limit]" value="<?php echo esc_attr((string) $settings['result_limit']); ?>"></div>
                                <div><label for="wextstat-search-min-chars"><?php esc_html_e('Minimum Search Characters', 'wext-static-publisher'); ?></label><input id="wextstat-search-min-chars" type="number" min="1" max="10" name="<?php echo esc_attr($option_name); ?>[min_chars]" value="<?php echo esc_attr((string) $settings['min_chars']); ?>"></div>
                                <div><label for="wextstat-search-content-limit"><?php esc_html_e('Content Character Limit', 'wext-static-publisher'); ?></label><input id="wextstat-search-content-limit" type="number" min="500" max="20000" step="500" name="<?php echo esc_attr($option_name); ?>[content_limit]" value="<?php echo esc_attr((string) $settings['content_limit']); ?>"><p><?php esc_html_e('Maximum text length to be indexed from each page.', 'wext-static-publisher'); ?></p></div>
                                <div><label for="wextstat-search-threshold"><?php esc_html_e('Blur Threshold', 'wext-static-publisher'); ?></label><input id="wextstat-search-threshold" type="number" min="0.1" max="0.8" step="0.05" name="<?php echo esc_attr($option_name); ?>[threshold]" value="<?php echo esc_attr((string) $settings['threshold']); ?>"><p><?php esc_html_e('A lower value gives a more precise result, a higher value gives a more tolerant result.', 'wext-static-publisher'); ?></p></div>
                                <div><label for="wextstat-search-token-match"><?php esc_html_e('Word Matching', 'wext-static-publisher'); ?></label><select id="wextstat-search-token-match" name="<?php echo esc_attr($option_name); ?>[token_match]"><option value="all" <?php selected($settings['token_match'], 'all'); ?>><?php esc_html_e('Match all words', 'wext-static-publisher'); ?></option><option value="any" <?php selected($settings['token_match'], 'any'); ?>><?php esc_html_e('Match any word', 'wext-static-publisher'); ?></option></select></div>
                            </div>
                        </section>
                    <?php elseif ($current_search_tab === 'selectors') : ?>
                        <section class="wextstat-search-card">
                            <div class="wextstat-search-card__heading">
                                <span class="dashicons dashicons-filter" aria-hidden="true"></span>
                                <div><h3><?php esc_html_e('Indexing Selectors', 'wext-static-publisher'); ?></h3><p><?php esc_html_e('Determine which fields to take from the page and post HTML with the CSS selector.', 'wext-static-publisher'); ?></p></div>
                            </div>
                            <div class="wextstat-search-selectors">
                                <div><label for="wextstat-title-selector"><?php esc_html_e('CSS Selector For Title', 'wext-static-publisher'); ?></label><input id="wextstat-title-selector" type="text" name="<?php echo esc_attr($option_name); ?>[title_selector]" value="<?php echo esc_attr((string) $settings['title_selector']); ?>" required><p><?php esc_html_e('Example:', 'wext-static-publisher'); ?> <code>title</code>, <code>h1.entry-title</code> <?php esc_html_e('or', 'wext-static-publisher'); ?> <code>meta[property="og:title"]</code></p></div>
                                <div><label for="wextstat-content-selector"><?php esc_html_e('CSS Selector for Content', 'wext-static-publisher'); ?></label><input id="wextstat-content-selector" type="text" name="<?php echo esc_attr($option_name); ?>[content_selector]" value="<?php echo esc_attr((string) $settings['content_selector']); ?>" required><p><?php esc_html_e('Example:', 'wext-static-publisher'); ?> <code>body</code>, <code>.entry-content</code> <?php esc_html_e('or', 'wext-static-publisher'); ?> <code>#main</code></p></div>
                                <div><label for="wextstat-excerpt-selector"><?php esc_html_e('CSS Selector for Summary', 'wext-static-publisher'); ?></label><input id="wextstat-excerpt-selector" type="text" name="<?php echo esc_attr($option_name); ?>[excerpt_selector]" value="<?php echo esc_attr((string) $settings['excerpt_selector']); ?>" required><p><?php esc_html_e('If the field is not found, a short summary is automatically generated from the content.', 'wext-static-publisher'); ?></p></div>
                                <div><label for="wextstat-search-excludes"><?php esc_html_e('URLs to Exclude from Indexing', 'wext-static-publisher'); ?></label><textarea id="wextstat-search-excludes" rows="6" name="<?php echo esc_attr($option_name); ?>[exclude_urls]"><?php echo esc_textarea((string) $settings['exclude_urls']); ?></textarea><p><?php esc_html_e('You can type the full URL, URL fragment, or word per line.', 'wext-static-publisher'); ?></p></div>
                            </div>
                        </section>
                    <?php else : ?>
                        <section class="wextstat-search-card">
                            <div class="wextstat-search-card__heading">
                                <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                                <div><h3><?php esc_html_e('Fuse.js', 'wext-static-publisher'); ?></h3><p><?php esc_html_e('Select the fields to search and determine their impact on the result ranking.', 'wext-static-publisher'); ?></p></div>
                            </div>
                            <div class="wextstat-search-fields">
                                <?php
                                $index_fields = [
                                    'title' => [__('Title', 'wext-static-publisher'), __('Shows title matches at the top.', 'wext-static-publisher')],
                                    'excerpt' => [__('Summary', 'wext-static-publisher'), __('Searches in the text of the tagline and summary.', 'wext-static-publisher')],
                                    'content' => [__('Contents', 'wext-static-publisher'), __('It searches in the main text of the page and article.', 'wext-static-publisher')],
                                    'taxonomies' => [__('Category and Tags', 'wext-static-publisher'), __('WordPress adds category and tag names to the index.', 'wext-static-publisher')],
                                ];
                                foreach ($index_fields as $field => [$label, $description]) :
                                    $weight_key = $field === 'taxonomies' ? 'taxonomy_weight' : $field . '_weight';
                                    ?>
                                    <div class="wextstat-search-field-row">
                                        <label class="wextstat-search-field-check"><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[index_<?php echo esc_attr($field); ?>]" value="1" <?php checked((string) $settings['index_' . $field], '1'); ?>><span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($description); ?></small></span></label>
                                        <label class="wextstat-search-weight"><?php esc_html_e('Weight', 'wext-static-publisher'); ?> <input type="number" min="0.1" max="10" step="0.1" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($weight_key); ?>]" value="<?php echo esc_attr((string) $settings[$weight_key]); ?>"></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php submit_button(__('Save Search Settings', 'wext-static-publisher')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_hide_tab(array $settings, string $requested_hide_tab): void
    {
        $option_name = Plugin::HIDE_SETTINGS_KEY;
        $hide_tabs = [
            'directory' => [__('Directory', 'wext-static-publisher'), 'dashicons-portfolio'],
            'traces' => [__('Traces', 'wext-static-publisher'), 'dashicons-hidden'],
            'static-outputs' => [__('Static Outputs', 'wext-static-publisher'), 'dashicons-shield'],
        ];
        $current_hide_tab = isset($hide_tabs[$requested_hide_tab]) ? $requested_hide_tab : 'directory';
        ?>
        <h2><?php esc_html_e('Hide', 'wext-static-publisher'); ?></h2>
        <p class="wextstat-hide-intro"><?php esc_html_e('Specify which WordPress-specific directory and path names will be used in static output. Source WordPress files are not modified.', 'wext-static-publisher'); ?></p>
        <div class="wextstat-hide-layout">
            <nav class="wextstat-hide-tabs" aria-label="<?php echo esc_attr__('Hide', 'wext-static-publisher'); ?>">
                <?php foreach ($hide_tabs as $hide_tab => [$hide_tab_label, $hide_tab_icon]) : ?>
                    <a class="wextstat-hide-tab <?php echo $current_hide_tab === $hide_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::hide_page_url($hide_tab)); ?>" <?php echo $current_hide_tab === $hide_tab ? 'aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr($hide_tab_icon); ?>" aria-hidden="true"></span>
                        <span><?php echo esc_html($hide_tab_label); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="wextstat-hide-panel">
                <form class="wextstat-hide-form" method="post" action="options.php">
                    <?php settings_fields('wext_static_hide'); ?>
                    <input type="hidden" name="<?php echo esc_attr($option_name); ?>[_section]" value="<?php echo esc_attr($current_hide_tab); ?>">

            <?php if ($current_hide_tab === 'directory') : ?>
            <div class="wextstat-hide-field">
                <label for="wextstat-hide-wp-content"><?php esc_html_e('WP-Content Directory', 'wext-static-publisher'); ?></label>
                <input id="wextstat-hide-wp-content" type="text" name="<?php echo esc_attr($option_name); ?>[wp_content_directory]" value="<?php echo esc_attr((string) $settings['wp_content_directory']); ?>" required>
                <p><?php esc_html_e('In the static output', 'wext-static-publisher'); ?> <code>wp-content</code> <?php esc_html_e('The name to replace the directory.', 'wext-static-publisher'); ?></p>
            </div>

            <div class="wextstat-hide-field">
                <label for="wextstat-hide-wp-includes"><?php esc_html_e('WP-Includes Directory', 'wext-static-publisher'); ?></label>
                <input id="wextstat-hide-wp-includes" type="text" name="<?php echo esc_attr($option_name); ?>[wp_includes_directory]" value="<?php echo esc_attr((string) $settings['wp_includes_directory']); ?>" required>
                <p><?php esc_html_e('In the static output', 'wext-static-publisher'); ?> <code>wp-includes</code> <?php esc_html_e('The name to replace the directory.', 'wext-static-publisher'); ?></p>
            </div>

            <div class="wextstat-hide-field">
                <label for="wextstat-hide-uploads"><?php esc_html_e('Uploads Directory', 'wext-static-publisher'); ?></label>
                <div class="wextstat-input-group"><span data-wextstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="wextstat-hide-uploads" type="text" name="<?php echo esc_attr($option_name); ?>[uploads_directory]" value="<?php echo esc_attr((string) $settings['uploads_directory']); ?>" required></div>
                <p><?php esc_html_e('In the static output', 'wext-static-publisher'); ?> <code>wp-content/uploads</code> <?php esc_html_e('The subdirectory to use in place of the path.', 'wext-static-publisher'); ?></p>
            </div>

            <div class="wextstat-hide-field">
                <label for="wextstat-hide-plugins"><?php esc_html_e('Plugins Directory', 'wext-static-publisher'); ?></label>
                <div class="wextstat-input-group"><span data-wextstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="wextstat-hide-plugins" type="text" name="<?php echo esc_attr($option_name); ?>[plugins_directory]" value="<?php echo esc_attr((string) $settings['plugins_directory']); ?>" required></div>
                <p><?php esc_html_e('In the static output', 'wext-static-publisher'); ?> <code>wp-content/plugins</code> <?php esc_html_e('The subdirectory to use in place of the path.', 'wext-static-publisher'); ?></p>
            </div>

            <div class="wextstat-hide-field">
                <label for="wextstat-hide-themes"><?php esc_html_e('Themes Directory', 'wext-static-publisher'); ?></label>
                <div class="wextstat-input-group"><span data-wextstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="wextstat-hide-themes" type="text" name="<?php echo esc_attr($option_name); ?>[themes_directory]" value="<?php echo esc_attr((string) $settings['themes_directory']); ?>" required></div>
                <p><?php esc_html_e('In the static output', 'wext-static-publisher'); ?> <code>wp-content/themes</code> <?php esc_html_e('The subdirectory to use in place of the path.', 'wext-static-publisher'); ?></p>
            </div>

            <div class="wextstat-hide-field">
                <label for="wextstat-hide-style"><?php esc_html_e('Theme Style File', 'wext-static-publisher'); ?></label>
                <div class="wextstat-input-group is-compact"><input id="wextstat-hide-style" type="text" name="<?php echo esc_attr($option_name); ?>[theme_style_name]" value="<?php echo esc_attr((string) $settings['theme_style_name']); ?>" required><span>.css</span></div>
                <p><?php esc_html_e('in active theme', 'wext-static-publisher'); ?> <code>style.css</code> <?php esc_html_e('The name of the file in the static output.', 'wext-static-publisher'); ?></p>
            </div>

            <div class="wextstat-hide-field">
                <label for="wextstat-hide-author"><?php esc_html_e('Author URL', 'wext-static-publisher'); ?></label>
                <input id="wextstat-hide-author" type="text" name="<?php echo esc_attr($option_name); ?>[author_url]" value="<?php echo esc_attr((string) $settings['author_url']); ?>" required>
                <p><?php esc_html_e('In the static output', 'wext-static-publisher'); ?> <code>/author/</code> <?php esc_html_e('The path to be used instead of the path.', 'wext-static-publisher'); ?></p>
            </div>
            <?php endif; ?>

            <?php if ($current_hide_tab === 'traces') : ?>
            <section class="wextstat-hide-options" aria-labelledby="wextstat-hide-traces-title">
                <div class="wextstat-hide-options__header">
                    <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                    <div>
                        <h3 id="wextstat-hide-traces-title"><?php esc_html_e('Traces', 'wext-static-publisher'); ?></h3>
                        <p><?php esc_html_e('Removes selected WordPress identifiers from static HTML output.', 'wext-static-publisher'); ?></p>
                    </div>
                </div>
                <?php
                $hide_toggles = [
                    'hide_wordpress_version' => [__('Hide WordPress Version', 'wext-static-publisher'), __('Removes asset version parameters that specify the WordPress core version.', 'wext-static-publisher')],
                    'hide_generator_meta' => [__('Hide WordPress Generator Meta Tag', 'wext-static-publisher'), __('It removes the generator meta tag that describes the WordPress version.', 'wext-static-publisher')],
                    'hide_wordpress_dns_prefetch' => [__('Hide WordPress DNS Prefetch Connection', 'wext-static-publisher'), __('Removes DNS prefetch connections for WordPress services.', 'wext-static-publisher')],
                    'hide_rsd_header' => [__('Hide RSD Header Link', 'wext-static-publisher'), __('Really Simple Removes the Discovery link from static HTML.', 'wext-static-publisher')],
                ];
                foreach ($hide_toggles as $key => [$label, $description]) :
                    $field_id = 'wextstat-' . str_replace('_', '-', $key);
                    ?>
                    <div class="wextstat-hide-toggle-row">
                        <div>
                            <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label>
                            <p><?php echo esc_html($description); ?></p>
                        </div>
                        <label class="wextstat-switch" aria-label="<?php echo esc_attr($label); ?>">
                            <input id="<?php echo esc_attr($field_id); ?>" type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked((string) ($settings[$key] ?? '0'), '1'); ?>>
                            <span aria-hidden="true"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if ($current_hide_tab === 'static-outputs') : ?>
            <section class="wextstat-hide-options" aria-labelledby="wextstat-disable-features-title">
                <div class="wextstat-hide-options__header">
                    <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                    <div>
                        <h3 id="wextstat-disable-features-title"><?php esc_html_e('Static Outputs', 'wext-static-publisher'); ?></h3>
                        <p><?php esc_html_e('Cleans up unused WordPress links and scripts on the static site.', 'wext-static-publisher'); ?></p>
                    </div>
                </div>
                <?php
                $disable_toggles = [
                    'disable_xml_rpc' => [__('Disable XML-RPC Links', 'wext-static-publisher'), __('Removes XML-RPC and pingback discovery connections.', 'wext-static-publisher')],
                    'disable_embed_scripts' => [__('Disable Embed Scripts', 'wext-static-publisher'), __('Removes WordPress embed scripts from static HTML.', 'wext-static-publisher')],
                    'disable_db_debug' => [__('Disable Frontend DB Debug Information', 'wext-static-publisher'), __('It only prevents database error details from being shown in export requests.', 'wext-static-publisher')],
                    'disable_wlw_manifest' => [__('Disable WLW Manifest Link', 'wext-static-publisher'), __('Windows Live Writer removes the manifest link.', 'wext-static-publisher')],
                    'disable_emojis' => [__('Disable Emoji Scripts', 'wext-static-publisher'), __('Removes WordPress emoji scripts and styles from static HTML.', 'wext-static-publisher')],
                ];
                foreach ($disable_toggles as $key => [$label, $description]) :
                    $field_id = 'wextstat-' . str_replace('_', '-', $key);
                    ?>
                    <div class="wextstat-hide-toggle-row">
                        <div>
                            <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label>
                            <p><?php echo esc_html($description); ?></p>
                        </div>
                        <label class="wextstat-switch" aria-label="<?php echo esc_attr($label); ?>">
                            <input id="<?php echo esc_attr($field_id); ?>" type="checkbox" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked((string) ($settings[$key] ?? '0'), '1'); ?>>
                            <span aria-hidden="true"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

                    <?php submit_button(__('Save Hide Settings', 'wext-static-publisher')); ?>
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
        <div class="wextstat-diagnostics-header">
            <div>
                <h2><?php esc_html_e('Diagnostics', 'wext-static-publisher'); ?></h2>
                <p class="wextstat-diagnostics-summary"><strong><?php echo esc_html(sprintf(__('%1$d of %2$d checks passed.', 'wext-static-publisher'), $passed, $total)); ?></strong> <?php esc_html_e('Last checked:', 'wext-static-publisher'); ?> <?php echo $checked_timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $checked_timestamp)); ?></p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wext_static_refresh_diagnostics">
                <?php wp_nonce_field('wext_static_refresh_diagnostics'); ?>
                <?php submit_button(__('Check Again', 'wext-static-publisher'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php if (isset($_GET['checked']) && sanitize_key(wp_unslash((string) $_GET['checked'])) === '1') : ?>
            <div class="wextstat-inline-alert is-success" role="status">
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <span><?php esc_html_e('Diagnostics checks have been updated.', 'wext-static-publisher'); ?></span>
            </div>
        <?php endif; ?>
        <?php foreach ($groups as $group_label => $checks) : ?>
            <section class="wextstat-diagnostics-card" aria-labelledby="wextstat-diagnostics-<?php echo esc_attr(sanitize_title($group_label)); ?>">
                <h2 id="wextstat-diagnostics-<?php echo esc_attr(sanitize_title($group_label)); ?>"><?php echo esc_html($group_label); ?></h2>
                <table class="wextstat-diagnostics-table" role="presentation">
                    <tbody>
                    <?php foreach ($checks as $check) : ?>
                        <tr>
                            <td class="wextstat-diagnostic-icon-cell">
                                <span class="wextstat-diagnostic-icon <?php echo $check['passed'] ? 'is-passed' : 'is-failed'; ?>" aria-label="<?php echo esc_attr($check['passed'] ? __('Passed', 'wext-static-publisher') : __('Failed', 'wext-static-publisher')); ?>">
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

    private static function render_about_tab(string $requested_tab): void
    {
        $managed_license = Managed_Deployer::public_license();
        $managed_connection = Managed_Deployer::public_connection();
        $managed_notice = self::managed_notice();
        $tabs = [
            'about' => __('About', 'wext-static-publisher'),
            'license' => __('License', 'wext-static-publisher'),
            'support' => __('Support', 'wext-static-publisher'),
        ];
        $current_tab = isset($tabs[$requested_tab]) ? $requested_tab : 'about';
        ?>
        <div class="wextstat-about-layout">
            <nav class="wextstat-about-tabs" aria-label="<?php echo esc_attr__('About sections', 'wext-static-publisher'); ?>">
                <?php foreach ($tabs as $tab_id => $tab_label) : ?>
                    <a class="wextstat-about-tab <?php echo $current_tab === $tab_id ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::about_page_url($tab_id)); ?>" <?php echo $current_tab === $tab_id ? 'aria-current="page"' : ''; ?>><?php echo esc_html($tab_label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="wextstat-about-panel">
                <?php if ($current_tab === 'about') : ?>
                    <h2><?php esc_html_e('About', 'wext-static-publisher'); ?></h2>
                    <section class="wextstat-about-card" aria-labelledby="wextstat-about-title">
                        <div class="wextstat-about-card__intro">
                            <span class="wextstat-about-card__icon dashicons dashicons-media-document" aria-hidden="true"></span>
                            <div>
                                <h3 id="wextstat-about-title">Wext Static Publisher</h3>
                                <p><?php esc_html_e('It was developed to convert your WordPress site into static files and prepare them for publication processes.', 'wext-static-publisher'); ?></p>
                            </div>
                        </div>
                        <dl class="wextstat-about-details">
                            <div>
                                <dt><?php esc_html_e('Version Number', 'wext-static-publisher'); ?></dt>
                                <dd><code><?php echo esc_html(WEXTSTAT_VERSION); ?></code></dd>
                            </div>
                            <div>
                                <dt><?php esc_html_e('Plugin Website', 'wext-static-publisher'); ?></dt>
                                <dd>
                                    <a href="<?php echo esc_url('https://wext.io/'); ?>" target="_blank" rel="noopener noreferrer">
                                        wext.io
                                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                    </a>
                                </dd>
                            </div>
                        </dl>
                    </section>
                <?php elseif ($current_tab === 'license') : ?>
                    <h2><?php esc_html_e('License', 'wext-static-publisher'); ?></h2>
                    <?php if ($managed_notice !== [] && (string) ($managed_notice['message'] ?? '') !== '') : ?>
                        <div class="notice notice-<?php echo esc_attr((string) ($managed_notice['type'] ?? 'error')); ?> inline is-dismissible" role="status"><p><?php echo esc_html((string) $managed_notice['message']); ?></p></div>
                    <?php endif; ?>
                    <?php self::render_managed_license_card($managed_license); ?>
                <?php else : ?>
                    <h2><?php esc_html_e('Support', 'wext-static-publisher'); ?></h2>
                    <section class="wextstat-about-card" aria-labelledby="wextstat-support-title">
                        <div class="wextstat-about-card__intro">
                            <span class="wextstat-about-card__icon dashicons dashicons-sos" aria-hidden="true"></span>
                            <div>
                                <h3 id="wextstat-support-title"><?php esc_html_e('Wext Support', 'wext-static-publisher'); ?></h3>
                                <p><?php esc_html_e('Get help with licensing, static publishing, and deployment.', 'wext-static-publisher'); ?></p>
                            </div>
                        </div>
                        <dl class="wextstat-about-details">
                            <div>
                                <dt><?php esc_html_e('Support Email', 'wext-static-publisher'); ?></dt>
                                <dd><a href="<?php echo esc_url('mailto:info@wext.co'); ?>">info@wext.co</a></dd>
                            </div>
                            <div>
                                <dt><?php esc_html_e('Plugin Website', 'wext-static-publisher'); ?></dt>
                                <dd>
                                    <a href="<?php echo esc_url('https://wext.io/'); ?>" target="_blank" rel="noopener noreferrer">
                                        wext.io
                                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                    </a>
                                </dd>
                            </div>
                        </dl>
                    </section>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_managed_license_card(array $managed_license): void
    {
        ?>
        <section class="wextstat-deploy-card wextstat-about-license-card" aria-labelledby="wextstat-license-title">
            <div class="wextstat-deploy-card__content">
                <h3 id="wextstat-license-title"><?php esc_html_e('Wext License', 'wext-static-publisher'); ?></h3>
                <?php if (empty($managed_license['active'])) : ?>
                    <p><?php esc_html_e('Activate this WordPress installation before connecting a Cloudflare account.', 'wext-static-publisher'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wext_static_license_activate">
                        <?php wp_nonce_field('wext_static_license_activate'); ?>
                        <label for="wextstat-license-key"><?php esc_html_e('License key', 'wext-static-publisher'); ?></label>
                        <input id="wextstat-license-key" class="regular-text" type="password" name="license_key" required minlength="16" autocomplete="off">
                        <?php submit_button(__('Activate License', 'wext-static-publisher'), 'primary', 'submit', false, empty($managed_license['available']) ? ['disabled' => 'disabled'] : []); ?>
                    </form>
                <?php else : ?>
                    <table class="widefat striped wextstat-status-table">
                        <tbody>
                        <tr>
                            <th><?php esc_html_e('Status', 'wext-static-publisher'); ?></th>
                            <td class="wextstat-license-status">
                                <span><?php esc_html_e('Active', 'wext-static-publisher'); ?></span>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="wext_static_license_deactivate">
                                    <?php wp_nonce_field('wext_static_license_deactivate'); ?>
                                    <button type="submit" class="button-link wextstat-license-detach-link" data-wextstat-confirm="<?php echo esc_attr__('Detach this license from this site? Cloudflare publishing will stop, but the remaining license term will stay available.', 'wext-static-publisher'); ?>" data-wextstat-confirm-label="<?php echo esc_attr__('Detach License', 'wext-static-publisher'); ?>"><?php esc_html_e('Detach License', 'wext-static-publisher'); ?></button>
                                </form>
                            </td>
                        </tr>
                        <tr><th><?php esc_html_e('Plan', 'wext-static-publisher'); ?></th><td><code><?php echo esc_html((string) ($managed_license['plan_code'] ?: '—')); ?></code></td></tr>
                        <tr><th><?php esc_html_e('Site limit', 'wext-static-publisher'); ?></th><td><?php echo esc_html((string) ($managed_license['site_limit'] ?: '—')); ?></td></tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }
}
