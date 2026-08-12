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
                'pollError' => __('Progress information is unavailable. Check your internet connection or WordPress REST API access.', 'ragnus-static-publisher'),
            ],
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
            $sanitized['archive_retention'] = max(1, min(100, absint($value['archive_retention'] ?? 5)));
        }

        if (in_array($section, ['automation', 'all'], true)) {
            $sanitized['auto_export'] = isset($value['auto_export']) ? '1' : '0';
            foreach (array_keys(Plugin::auto_export_trigger_defaults()) as $trigger) {
                $sanitized[$trigger] = isset($value[$trigger]) ? '1' : '0';
            }
        }

        if (in_array($section, ['deploy', 'all'], true)) {
            $submitted_token = sanitize_text_field((string) ($value['deployment_webhook_token'] ?? ''));
            $sanitized['deployment_webhook_url'] = esc_url_raw((string) ($value['deployment_webhook_url'] ?? ''));
            $sanitized['deployment_webhook_token'] = $submitted_token !== '' ? $submitted_token : (string) $current['deployment_webhook_token'];
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

    public static function sanitize_hide_settings(array $value): array
    {
        $defaults = Plugin::hide_defaults();
        $sanitized = [];
        $path_keys = [
            'wp_content_directory',
            'wp_includes_directory',
            'uploads_directory',
            'plugins_directory',
            'themes_directory',
            'theme_style_name',
            'author_url',
        ];
        foreach ($path_keys as $key) {
            $default = $defaults[$key];
            $candidate = strtolower(trim((string) ($value[$key] ?? '')));
            if ($key === 'theme_style_name') {
                $candidate = preg_replace('/\.css$/i', '', $candidate) ?? $candidate;
            }
            $candidate = sanitize_key(str_replace(' ', '-', $candidate));
            $sanitized[$key] = $candidate !== '' ? $candidate : $default;
        }
        foreach (array_diff(array_keys($defaults), $path_keys) as $key) {
            $sanitized[$key] = isset($value[$key]) ? '1' : '0';
        }
        return $sanitized;
    }

    public static function sanitize_search_settings(array $value): array
    {
        $defaults = Plugin::search_defaults();
        $fields = ['index_title', 'index_excerpt', 'index_content', 'index_taxonomies'];
        $sanitized = [
            'enabled' => isset($value['enabled']) ? '1' : '0',
            'page_path' => sanitize_title((string) ($value['page_path'] ?? $defaults['page_path'])) ?: $defaults['page_path'],
            'result_limit' => max(5, min(100, absint($value['result_limit'] ?? $defaults['result_limit']))),
            'min_chars' => max(1, min(10, absint($value['min_chars'] ?? $defaults['min_chars']))),
            'content_limit' => max(500, min(20000, absint($value['content_limit'] ?? $defaults['content_limit']))),
            'threshold' => (string) max(0.1, min(0.8, (float) ($value['threshold'] ?? $defaults['threshold']))),
            'token_match' => in_array(($value['token_match'] ?? ''), ['all', 'any'], true) ? $value['token_match'] : 'all',
            'title_selector' => sanitize_text_field((string) ($value['title_selector'] ?? $defaults['title_selector'])),
            'content_selector' => sanitize_text_field((string) ($value['content_selector'] ?? $defaults['content_selector'])),
            'excerpt_selector' => sanitize_text_field((string) ($value['excerpt_selector'] ?? $defaults['excerpt_selector'])),
            'exclude_urls' => sanitize_textarea_field((string) ($value['exclude_urls'] ?? '')),
        ];
        foreach ($fields as $field) {
            $sanitized[$field] = isset($value[$field]) ? '1' : '0';
        }
        if (! in_array('1', array_intersect_key($sanitized, array_flip($fields)), true)) {
            $sanitized['index_title'] = '1';
        }
        foreach (['title_weight', 'excerpt_weight', 'content_weight', 'taxonomy_weight'] as $weight) {
            $sanitized[$weight] = (string) max(0.1, min(10, (float) ($value[$weight] ?? $defaults[$weight])));
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
        Plugin::schedule_export('admin');
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
        wp_safe_redirect(add_query_arg([
            'archive_notice' => $notice,
            'deleted' => absint($result['deleted'] ?? 0),
            'failed' => absint($result['failed'] ?? 0),
        ], self::admin_page_url('files')));
        exit;
    }

    public static function cleanup_exports(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You are not authorized for this operation.', 'ragnus-static-publisher'), 403);
        }
        check_admin_referer('ragnus_static_cleanup_exports');

        if (get_transient(Plugin::LOCK_KEY)) {
            wp_safe_redirect(add_query_arg('cleanup', 'running', self::admin_page_url('files')));
            exit;
        }

        $result = Archive_Manager::delete_old_archives();
        $query = [
            'cleanup' => $result['failed'] > 0 ? 'partial' : 'success',
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ];
        wp_safe_redirect(add_query_arg($query, self::admin_page_url('files')));
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
        if ($requested_tab === 'settings' && $requested_settings_tab === 'deploy') {
            $requested_tab = 'deploy';
            $requested_deploy_tab = 'github';
        }
        $tabs = [
            'main' => __('Main', 'ragnus-static-publisher'),
            'deploy' => __('Deploy', 'ragnus-static-publisher'),
            'files' => __('Files', 'ragnus-static-publisher'),
            'settings' => __('Settings', 'ragnus-static-publisher'),
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
            <?php elseif ($current_tab === 'files') : ?>
                <?php self::render_files_tab($archives); ?>
            <?php elseif ($current_tab === 'activity') : ?>
                <?php self::render_activity_tab(); ?>
            <?php elseif ($current_tab === 'settings') : ?>
                <?php self::render_settings_tab(Plugin::settings(), $requested_settings_tab); ?>
            <?php elseif ($current_tab === 'search') : ?>
                <?php self::render_search_tab(Plugin::search_settings()); ?>
            <?php elseif ($current_tab === 'hide') : ?>
                <?php self::render_hide_tab(Plugin::hide_settings()); ?>
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

        <form class="ragstat-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ragnus_static_export">
            <?php wp_nonce_field('ragnus_static_export'); ?>
            <?php submit_button(__('Create Static Site', 'ragnus-static-publisher'), 'primary', 'submit', false, $is_active ? ['disabled' => 'disabled'] : []); ?>
            <a id="ragstat-download" class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ragnus_static_download'), 'ragnus_static_download')); ?>" <?php echo $archives === [] ? 'hidden' : ''; ?>><?php esc_html_e('Download', 'ragnus-static-publisher'); ?></a>
        </form>
        </div>
        <?php
    }

    private static function render_files_tab(array $archives): void
    {
        $cleanup_status = isset($_GET['cleanup']) ? sanitize_key(wp_unslash((string) $_GET['cleanup'])) : '';
        $archive_notice = isset($_GET['archive_notice']) ? sanitize_key(wp_unslash((string) $_GET['archive_notice'])) : '';
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
                <table class="widefat striped ragstat-files-table">
                    <thead><tr><td class="manage-column check-column"><input id="cb-select-all-1" type="checkbox"><label for="cb-select-all-1"><span class="screen-reader-text"><?php esc_html_e('Select All', 'ragnus-static-publisher'); ?></span></label></td><th class="ragstat-number-column"><?php esc_html_e('Order', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Job ID', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('URL Count', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Creation Date', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Creation Time', 'ragnus-static-publisher'); ?></th><th><?php esc_html_e('Actions', 'ragnus-static-publisher'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($archives as $archive_index => $archive) : ?>
                        <?php
                        $archive_id = (string) $archive['id'];
                        $download_url = wp_nonce_url(
                            add_query_arg(['action' => 'ragnus_static_download_archive', 'archive_id' => $archive_id], admin_url('admin-post.php')),
                            'ragnus_static_download_archive_' . $archive_id
                        );
                        ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" name="archive_ids[]" value="<?php echo esc_attr($archive_id); ?>"><span class="screen-reader-text"><?php echo esc_html(sprintf(__('Select %s', 'ragnus-static-publisher'), (string) $archive['job_id'])); ?></span></th>
                            <td class="ragstat-number-column"><?php echo esc_html((string) ($archive_index + 1)); ?></td>
                            <td><code><?php echo esc_html((string) $archive['job_id']); ?></code></td>
                            <td><?php echo $archive['url_count'] === null ? '—' : esc_html((string) $archive['url_count']); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $archive['created_at'])); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $archive['created_at'])); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($download_url); ?>"><?php esc_html_e('Download', 'ragnus-static-publisher'); ?></a> <button class="button button-small button-link-delete" type="submit" name="delete_archive" value="<?php echo esc_attr($archive_id); ?>" data-ragstat-confirm="<?php echo esc_attr__('This ZIP file will be permanently deleted. Do you want to continue?', 'ragnus-static-publisher'); ?>" data-ragstat-clear-bulk-action><?php esc_html_e('Delete', 'ragnus-static-publisher'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
        ];
        $current_deploy_tab = isset($deploy_tabs[$requested_deploy_tab]) ? $requested_deploy_tab : 'zip';
        ?>
        <div class="ragstat-deploy-header">
            <div>
                <h2><?php esc_html_e('Deploy', 'ragnus-static-publisher'); ?></h2>
                <p><?php esc_html_e('Choose the method you\'ll use to publish your static site.', 'ragnus-static-publisher'); ?></p>
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
                            <a class="button button-primary" href="<?php echo esc_url(self::admin_page_url('files')); ?>"><?php esc_html_e('Open ZIP Files', 'ragnus-static-publisher'); ?></a>
                        </div>
                    </section>
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
                    <section class="ragstat-deploy-card" aria-labelledby="ragstat-deploy-cloudflare-title">
                        <span class="ragstat-deploy-card__icon ragstat-deploy-card__cloudflare-icon" aria-hidden="true">
                            <?php self::render_cloudflare_icon('ragstat-cloudflare-icon'); ?>
                        </span>
                        <div class="ragstat-deploy-card__content">
                            <h3 id="ragstat-deploy-cloudflare-title"><?php esc_html_e('Cloudflare', 'ragnus-static-publisher'); ?></h3>
                            <p><?php esc_html_e('Publish static files to Cloudflare Workers Static Assets with a GitHub Actions workflow.', 'ragnus-static-publisher'); ?></p>
                        </div>
                        <div class="ragstat-deploy-card__footer">
                            <span class="ragstat-deploy-status is-info"><?php esc_html_e('Cloudflare account required', 'ragnus-static-publisher'); ?></span>
                            <a class="button button-primary" href="<?php echo esc_url('https://dash.cloudflare.com/'); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open Cloudflare', 'ragnus-static-publisher'); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                        </div>
                    </section>
                <?php else : ?>
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
            'automation' => __('Automation', 'ragnus-static-publisher'),
            'languages' => __('Languages', 'ragnus-static-publisher'),
        ];
        $current_settings_tab = isset($settings_tabs[$requested_settings_tab]) ? $requested_settings_tab : 'general';
        ?>
        <div class="ragstat-settings-header">
            <h2><?php esc_html_e('Settings', 'ragnus-static-publisher'); ?></h2>
            <p><?php esc_html_e('Manage static generation, automation and publishing settings.', 'ragnus-static-publisher'); ?></p>
        </div>
        <div class="ragstat-settings-layout">
            <nav class="ragstat-settings-tabs" aria-label="<?php echo esc_attr__('Settings subsections', 'ragnus-static-publisher'); ?>">
                <?php foreach ($settings_tabs as $settings_tab => $label) : ?>
                    <a class="ragstat-settings-tab <?php echo $current_settings_tab === $settings_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::settings_page_url($settings_tab)); ?>" <?php echo $current_settings_tab === $settings_tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="ragstat-settings-panel">
                <?php if ($current_settings_tab === 'general') : ?>
                    <?php self::render_general_settings($settings); ?>
                <?php elseif ($current_settings_tab === 'automation') : ?>
                    <?php self::render_automation_settings($settings); ?>
                <?php else : ?>
                    <?php self::render_language_settings(Plugin::language_settings()); ?>
                <?php endif; ?>
            </div>
        </div>
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
                <tr><th><label for="ragstat-archive-retention"><?php esc_html_e('Number of ZIPs to Store', 'ragnus-static-publisher'); ?></label></th><td><input id="ragstat-archive-retention" type="number" min="1" max="100" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[archive_retention]" value="<?php echo esc_attr((string) $settings['archive_retention']); ?>"><p class="description"><?php esc_html_e('Determines how many last successful export archives will be stored. Default: 5.', 'ragnus-static-publisher'); ?></p></td></tr>
            </table>
            <?php submit_button(__('Save General Settings', 'ragnus-static-publisher')); ?>
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
            <?php submit_button(__('Save Automation Settings', 'ragnus-static-publisher')); ?>
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
                        <input id="ragstat-default-language" type="text" maxlength="20" spellcheck="false" name="<?php echo esc_attr(Plugin::LANGUAGE_SETTINGS_KEY); ?>[default_language]" value="<?php echo esc_attr((string) ($settings['default_language'] ?? 'tr')); ?>">
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

    private static function render_search_tab(array $settings): void
    {
        $option_name = Plugin::SEARCH_SETTINGS_KEY;
        ?>
        <div class="ragstat-search-header">
            <div>
                <h2><?php esc_html_e('Search', 'ragnus-static-publisher'); ?></h2>
                <p><?php esc_html_e('Configure static site search that doesn\'t require a server, powered by Fuse.js.', 'ragnus-static-publisher'); ?></p>
            </div>
            <span class="ragstat-search-badge">Fuse.js 7.3.0</span>
        </div>
        <form class="ragstat-search-settings" method="post" action="options.php">
            <?php settings_fields('ragnus_static_search'); ?>

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

            <section class="ragstat-search-card">
                <div class="ragstat-search-card__heading">
                    <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                    <div><h3><?php esc_html_e('Fuse.js Fields and Weights', 'ragnus-static-publisher'); ?></h3><p><?php esc_html_e('Select the fields to search and determine their impact on the result ranking.', 'ragnus-static-publisher'); ?></p></div>
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

            <?php submit_button(__('Save Search Settings', 'ragnus-static-publisher')); ?>
        </form>
        <?php
    }

    private static function render_hide_tab(array $settings): void
    {
        $option_name = Plugin::HIDE_SETTINGS_KEY;
        ?>
        <h2><?php esc_html_e('Hide', 'ragnus-static-publisher'); ?></h2>
        <p class="ragstat-hide-intro"><?php esc_html_e('Specify which WordPress-specific directory and path names will be used in static output. Source WordPress files are not modified.', 'ragnus-static-publisher'); ?></p>
        <form class="ragstat-hide-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static_hide'); ?>

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

            <section class="ragstat-hide-options" aria-labelledby="ragstat-hide-traces-title">
                <div class="ragstat-hide-options__header">
                    <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                    <div>
                        <h3 id="ragstat-hide-traces-title"><?php esc_html_e('Hide WordPress Traces', 'ragnus-static-publisher'); ?></h3>
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

            <section class="ragstat-hide-options" aria-labelledby="ragstat-disable-features-title">
                <div class="ragstat-hide-options__header">
                    <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                    <div>
                        <h3 id="ragstat-disable-features-title"><?php esc_html_e('Disable on Static Output', 'ragnus-static-publisher'); ?></h3>
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

            <?php submit_button(__('Save Hide Settings', 'ragnus-static-publisher')); ?>
        </form>
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
