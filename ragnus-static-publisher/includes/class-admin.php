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
        ]);
    }

    public static function sanitize(array $value): array
    {
        $current = Plugin::settings();
        $submitted_token = sanitize_text_field((string) ($value['deployment_webhook_token'] ?? ''));
        $sanitized = [
            'target_url' => esc_url_raw(untrailingslashit((string) ($value['target_url'] ?? home_url()))),
            'maximum_urls' => max(10, min(20000, absint($value['maximum_urls'] ?? 2000))),
            'excluded_paths' => sanitize_textarea_field((string) ($value['excluded_paths'] ?? '')),
            'auto_export' => isset($value['auto_export']) ? '1' : '0',
            'archive_retention' => max(1, min(100, absint($value['archive_retention'] ?? 5))),
            'deployment_webhook_url' => esc_url_raw((string) ($value['deployment_webhook_url'] ?? '')),
            'deployment_webhook_token' => $submitted_token !== '' ? $submitted_token : (string) $current['deployment_webhook_token'],
        ];
        foreach (array_keys(Plugin::auto_export_trigger_defaults()) as $trigger) {
            $sanitized[$trigger] = isset($value[$trigger]) ? '1' : '0';
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
            wp_die('Bu işlem için yetkiniz yok.', 403);
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
            wp_die('Bu işlem için yetkiniz yok.', 403);
        }
        check_admin_referer('ragnus_static_download');

        $archive = Archive_Manager::latest();
        if ($archive === null || ! is_readable((string) $archive['path'])) {
            wp_die('İndirilebilir export bulunamadı.', 404);
        }

        self::send_file((string) $archive['path'], (string) $archive['id'] . '.zip');
    }

    public static function run_pending_export(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bu işlem için yetkiniz yok.'], 403);
        }
        check_ajax_referer('ragnus_static_run_pending', 'nonce');

        $status = Plugin::status();
        $job_id = sanitize_file_name((string) ($status['job_id'] ?? ''));
        if (($status['state'] ?? '') !== 'queued' || $job_id === '') {
            wp_send_json_success(['status' => Plugin::public_status()]);
        }
        if (get_transient(Plugin::LOCK_KEY)) {
            wp_send_json_error(['message' => 'Export işlemi zaten çalışıyor.'], 409);
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
            wp_die('Bu işlem için yetkiniz yok.', 403);
        }
        check_admin_referer('ragnus_static_refresh_diagnostics');
        Diagnostics::refresh();
        wp_safe_redirect(add_query_arg('checked', '1', self::admin_page_url('diagnostics')));
        exit;
    }

    public static function download_archive(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Bu işlem için yetkiniz yok.', 403);
        }

        $archive_id = isset($_GET['archive_id']) ? sanitize_file_name(wp_unslash((string) $_GET['archive_id'])) : '';
        check_admin_referer('ragnus_static_download_archive_' . $archive_id);
        $archive = Archive_Manager::find($archive_id);
        if ($archive === null || ! is_readable((string) $archive['path'])) {
            wp_die('İndirilebilir ZIP dosyası bulunamadı.', 404);
        }

        self::send_file((string) $archive['path'], $archive_id . '.zip');
    }

    public static function archive_bulk_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Bu işlem için yetkiniz yok.', 403);
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
            wp_die('Bu işlem için yetkiniz yok.', 403);
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
        $tabs = [
            'main' => 'Main',
            'deploy' => 'Deploy',
            'files' => 'Files',
            'activity' => 'Activity Log',
            'settings' => 'Settings',
            'search' => 'Arama',
            'hide' => 'Hide',
            'diagnostics' => 'Diagnostics',
            'about' => 'About',
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
                    <p>WordPress sitenizin statik kopyasını üretin. Cloudflare kimlik bilgileri bu eklentide tutulmaz.</p>
                </div>
            </header>
            <nav class="nav-tab-wrapper wp-clearfix" aria-label="Static Publisher bölümleri">
                <?php foreach ($tabs as $tab_id => $tab_label) : ?>
                    <a class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::admin_page_url($tab_id)); ?>" <?php echo $current_tab === $tab_id ? 'aria-current="page"' : ''; ?>><?php echo esc_html($tab_label); ?></a>
                <?php endforeach; ?>
            </nav>

            <main class="ragstat-tab-content">
            <?php if ($current_tab === 'main') : ?>
                <?php self::render_main_tab($status, $archives); ?>
            <?php elseif ($current_tab === 'deploy') : ?>
                <?php self::render_deploy_tab($archives, Plugin::settings()); ?>
            <?php elseif ($current_tab === 'files') : ?>
                <?php self::render_files_tab($archives); ?>
            <?php elseif ($current_tab === 'activity') : ?>
                <?php self::render_activity_tab(); ?>
            <?php elseif ($current_tab === 'settings') : ?>
                <?php self::render_settings_tab(Plugin::settings()); ?>
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
                    <h2 id="ragstat-confirm-title">İşlemi Onaylayın</h2>
                    <p id="ragstat-confirm-message"></p>
                </div>
                <div class="ragstat-confirm-modal__actions">
                    <button type="button" class="button" data-ragstat-confirm-cancel>Vazgeç</button>
                    <button type="button" class="button button-primary ragstat-confirm-modal__confirm" data-ragstat-confirm-accept>Sil</button>
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

    private static function render_main_tab(array $status, array $archives): void
    {
        $state = (string) ($status['state'] ?? '');
        $progress = max(0, min(100, absint($status['progress'] ?? 0)));
        $state_labels = [
            'queued' => 'Kuyrukta',
            'running' => 'Çalışıyor',
            'completed' => 'Tamamlandı',
            'failed' => 'Başarısız',
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
        <h2>Yayın Durumu</h2>
        <div id="ragstat-runtime-notice" class="notice notice-warning inline ragstat-runtime-notice" role="status" <?php echo $runtime_notice === '' ? 'hidden' : ''; ?>><p><?php echo esc_html($runtime_notice); ?></p></div>
        <table class="widefat striped ragstat-status-table">
            <tbody>
            <tr><th>Durum</th><td id="ragstat-status-state"><?php echo esc_html($state_labels[$state] ?? 'Henüz Çalışmadı'); ?></td></tr>
            <tr>
                <th>İlerleme</th>
                <td id="ragstat-progress-cell">
                    <?php if ($state === 'completed') : ?>
                        <span class="ragstat-progress-status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong>Tamamlandı</strong></span>
                    <?php elseif ($state === 'failed') : ?>
                        <span class="ragstat-progress-status"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><strong>Başarısız</strong></span>
                    <?php else : ?>
                        <div class="ragstat-progress <?php echo in_array($state, ['queued', 'running'], true) ? 'is-active' : ''; ?>" role="progressbar" aria-label="Static oluşturma ilerlemesi" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $progress); ?>">
                            <div class="ragstat-progress__bar" style="width:<?php echo esc_attr((string) $progress); ?>%"></div>
                        </div>
                        <span class="ragstat-progress-percent"><?php echo esc_html((string) $progress); ?>%</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr><th>Aşama</th><td id="ragstat-status-message"><?php echo esc_html((string) ($status['status_message'] ?? '—')); ?></td></tr>
            <tr><th>İş Kimliği</th><td><code id="ragstat-job-id"><?php echo esc_html((string) ($status['job_id'] ?? '—')); ?></code></td></tr>
            <tr><th>URL Sayısı</th><td id="ragstat-url-count"><?php echo esc_html((string) ($status['url_count'] ?? 0)); ?></td></tr>
            <tr id="ragstat-current-url-row" <?php echo empty($status['current_url']) ? 'hidden' : ''; ?>><th>İşlenen Adres</th><td><code id="ragstat-current-url"><?php echo esc_html((string) ($status['current_url'] ?? '')); ?></code></td></tr>
            <tr><th>Son Statik Oluşturma</th><td id="ragstat-last-completed"><?php echo $last_completed_timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $last_completed_timestamp)); ?></td></tr>
            <tr id="ragstat-error-row" <?php echo empty($status['error']) ? 'hidden' : ''; ?>><th>Hata</th><td id="ragstat-error"><?php echo esc_html((string) ($status['error'] ?? '')); ?></td></tr>
            </tbody>
        </table>

        <form class="ragstat-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ragnus_static_export">
            <?php wp_nonce_field('ragnus_static_export'); ?>
            <?php submit_button('Statik Site Oluştur', 'primary', 'submit', false, $is_active ? ['disabled' => 'disabled'] : []); ?>
            <a id="ragstat-download" class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ragnus_static_download'), 'ragnus_static_download')); ?>" <?php echo $archives === [] ? 'hidden' : ''; ?>>İndir</a>
        </form>
        </div>
        <?php
    }

    private static function render_files_tab(array $archives): void
    {
        $cleanup_status = isset($_GET['cleanup']) ? sanitize_key(wp_unslash((string) $_GET['cleanup'])) : '';
        $archive_notice = isset($_GET['archive_notice']) ? sanitize_key(wp_unslash((string) $_GET['archive_notice'])) : '';
        ?>
        <h2>ZIP Dosyaları</h2>
        <?php if ($cleanup_status === 'success') : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf('%d eski ZIP dosyası silindi.', absint($_GET['deleted'] ?? 0))); ?></p></div>
        <?php elseif ($cleanup_status === 'partial') : ?>
            <div class="notice notice-warning is-dismissible"><p><?php echo esc_html(sprintf('%1$d eski ZIP dosyası silindi, %2$d dosya silinemedi.', absint($_GET['deleted'] ?? 0), absint($_GET['failed'] ?? 0))); ?></p></div>
        <?php elseif ($cleanup_status === 'running') : ?>
            <div class="notice notice-warning is-dismissible"><p>Export devam ederken eski dosyalar silinemez.</p></div>
        <?php endif; ?>

        <?php if ($archive_notice === 'deleted') : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf('%d ZIP dosyası silindi.', absint($_GET['deleted'] ?? 0))); ?></p></div>
        <?php elseif ($archive_notice === 'partial') : ?>
            <div class="notice notice-warning is-dismissible"><p><?php echo esc_html(sprintf('%1$d ZIP dosyası silindi, %2$d dosya silinemedi.', absint($_GET['deleted'] ?? 0), absint($_GET['failed'] ?? 0))); ?></p></div>
        <?php elseif ($archive_notice === 'no-selection') : ?>
            <div class="notice notice-warning is-dismissible"><p>İşlem yapmak için en az bir ZIP dosyası seçin.</p></div>
        <?php elseif ($archive_notice === 'invalid-action') : ?>
            <div class="notice notice-warning is-dismissible"><p>Geçerli bir toplu işlem seçin.</p></div>
        <?php elseif ($archive_notice === 'running') : ?>
            <div class="notice notice-warning is-dismissible"><p>Export devam ederken ZIP dosyaları silinemez.</p></div>
        <?php endif; ?>

        <?php if ($archives === []) : ?>
            <p>Henüz oluşturulmuş bir ZIP dosyası yok.</p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ragnus_static_archive_bulk">
                <?php wp_nonce_field('ragnus_static_archive_bulk'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label class="screen-reader-text" for="ragstat-bulk-action">Toplu İşlem Seçin</label>
                        <select id="ragstat-bulk-action" name="archive_bulk_action">
                            <option value="">Toplu İşlem</option>
                            <option value="download">Seçilenleri İndir</option>
                            <option value="delete">Seçilenleri Sil</option>
                        </select>
                        <?php submit_button('Uygula', 'action', 'bulk_submit', false, [
                            'data-ragstat-confirm' => 'Seçilen ZIP dosyaları kalıcı olarak silinecek. Devam edilsin mi?',
                            'data-ragstat-confirm-action' => 'delete',
                        ]); ?>
                    </div>
                    <br class="clear">
                </div>
                <table class="widefat striped ragstat-files-table">
                    <thead><tr><td class="manage-column check-column"><input id="cb-select-all-1" type="checkbox"><label for="cb-select-all-1"><span class="screen-reader-text">Tümünü Seç</span></label></td><th class="ragstat-number-column">Sıra</th><th>İş Kimliği</th><th>URL Sayısı</th><th>Oluşturma Tarihi</th><th>Oluşturma Saati</th><th>İşlem</th></tr></thead>
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
                            <th scope="row" class="check-column"><input type="checkbox" name="archive_ids[]" value="<?php echo esc_attr($archive_id); ?>"><span class="screen-reader-text"><?php echo esc_html((string) $archive['job_id']); ?> seç</span></th>
                            <td class="ragstat-number-column"><?php echo esc_html((string) ($archive_index + 1)); ?></td>
                            <td><code><?php echo esc_html((string) $archive['job_id']); ?></code></td>
                            <td><?php echo $archive['url_count'] === null ? '—' : esc_html((string) $archive['url_count']); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $archive['created_at'])); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $archive['created_at'])); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($download_url); ?>">İndir</a> <button class="button button-small button-link-delete" type="submit" name="delete_archive" value="<?php echo esc_attr($archive_id); ?>" data-ragstat-confirm="Bu ZIP dosyası kalıcı olarak silinecek. Devam edilsin mi?" data-ragstat-clear-bulk-action>Sil</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>

        <form class="ragstat-cleanup-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ragnus_static_cleanup_exports">
            <?php wp_nonce_field('ragnus_static_cleanup_exports'); ?>
            <?php submit_button('Eski Dosyaları Sil', 'delete', 'submit', false, [
                'data-ragstat-confirm' => 'En son ZIP dışındaki tüm eski ZIP dosyaları silinecek. Devam edilsin mi?',
            ]); ?>
            <p class="description">En son ZIP dosyası korunur; önceki ZIP dosyaları ve bunlara ait geçici build klasörleri kalıcı olarak silinir.</p>
        </form>
        <?php
    }

    private static function render_deploy_tab(array $archives, array $settings): void
    {
        $has_archive = $archives !== [];
        $github_configured = (string) ($settings['deployment_webhook_url'] ?? '') !== '';
        ?>
        <div class="ragstat-deploy-header">
            <div>
                <h2>Deploy</h2>
                <p>Statik sitenizi yayınlamak için kullanacağınız yöntemi seçin.</p>
            </div>
        </div>
        <div class="ragstat-deploy-grid">
            <section class="ragstat-deploy-card" aria-labelledby="ragstat-deploy-zip-title">
                <span class="ragstat-deploy-card__icon dashicons dashicons-media-archive" aria-hidden="true"></span>
                <div class="ragstat-deploy-card__content">
                    <h3 id="ragstat-deploy-zip-title">ZIP File</h3>
                    <p>Oluşturulan statik site paketini indirip istediğiniz sunucuya manuel olarak yükleyin.</p>
                </div>
                <div class="ragstat-deploy-card__footer">
                    <span class="ragstat-deploy-status <?php echo $has_archive ? 'is-ready' : 'is-pending'; ?>">
                        <?php echo $has_archive ? 'ZIP hazır' : 'Önce statik site oluşturun'; ?>
                    </span>
                    <a class="button button-primary" href="<?php echo esc_url(self::admin_page_url('files')); ?>">ZIP Dosyalarını Aç</a>
                </div>
            </section>

            <section class="ragstat-deploy-card" aria-labelledby="ragstat-deploy-github-title">
                <span class="ragstat-deploy-card__icon dashicons dashicons-randomize" aria-hidden="true"></span>
                <div class="ragstat-deploy-card__content">
                    <h3 id="ragstat-deploy-github-title">GitHub</h3>
                    <p>Export tamamlandığında GitHub Actions akışını webhook ile otomatik olarak tetikleyin.</p>
                </div>
                <div class="ragstat-deploy-card__footer">
                    <span class="ragstat-deploy-status <?php echo $github_configured ? 'is-ready' : 'is-pending'; ?>">
                        <?php echo $github_configured ? 'Webhook ayarlı' : 'Yapılandırma gerekli'; ?>
                    </span>
                    <a class="button button-primary" href="<?php echo esc_url(self::admin_page_url('settings') . '#ragstat-webhook'); ?>">GitHub Ayarları</a>
                </div>
            </section>

            <section class="ragstat-deploy-card" aria-labelledby="ragstat-deploy-cloudflare-title">
                <span class="ragstat-deploy-card__icon dashicons dashicons-cloud" aria-hidden="true"></span>
                <div class="ragstat-deploy-card__content">
                    <h3 id="ragstat-deploy-cloudflare-title">Cloudflare</h3>
                    <p>GitHub Actions akışıyla statik dosyaları Cloudflare Workers Static Assets üzerinde yayınlayın.</p>
                </div>
                <div class="ragstat-deploy-card__footer">
                    <span class="ragstat-deploy-status is-info">Cloudflare hesabı gerekir</span>
                    <a class="button button-primary" href="<?php echo esc_url('https://dash.cloudflare.com/'); ?>" target="_blank" rel="noopener noreferrer">Cloudflare'ı Aç<span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                </div>
            </section>
        </div>
        <?php
    }

    private static function render_activity_tab(): void
    {
        $requested_page = isset($_GET['log_page']) ? absint($_GET['log_page']) : 1;
        $search = isset($_GET['log_search']) ? sanitize_text_field(wp_unslash((string) $_GET['log_search'])) : '';
        $activity = Activity_Log::page($requested_page, $search);
        $entries = $activity['entries'];
        ?>
        <h2>Activity Log</h2>
        <?php if ($activity['job_id'] !== '') : ?>
            <p class="ragstat-activity-meta">
                <span>En son static işlemine ait kayıtlar: <code><?php echo esc_html((string) $activity['job_id']); ?></code></span>
                <span>Kayıt Sayısı: <strong><?php echo esc_html((string) $activity['job_total']); ?></strong></span>
            </p>
        <?php endif; ?>
        <form class="ragstat-activity-search" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="ragnus-static-publisher">
            <input type="hidden" name="tab" value="activity">
            <label class="screen-reader-text" for="ragstat-log-search">Log Kayıtlarında Ara</label>
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <input id="ragstat-log-search" type="search" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="Kaynak veya statik adreste ara...">
            <button class="button button-primary" type="submit">Ara</button>
            <?php if ($search !== '') : ?>
                <a class="button" href="<?php echo esc_url(self::admin_page_url('activity')); ?>">Aramayı Temizle</a>
            <?php endif; ?>
        </form>
        <?php if ($entries === []) : ?>
            <div class="ragstat-empty-state">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <p><?php echo $search === '' ? 'Henüz kaydedilmiş bir export etkinliği yok.' : 'Aramanızla eşleşen bir log kaydı bulunamadı.'; ?></p>
            </div>
        <?php else : ?>
            <table class="widefat striped ragstat-activity-table">
                <thead><tr><th>Tarih</th><th class="ragstat-time-column">Saat</th><th>Kaynak Adres</th><th>Statik Adres</th></tr></thead>
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
                <nav class="ragstat-pagination" aria-label="Activity Log sayfaları">
                    <span class="ragstat-pagination__summary"><?php echo esc_html(sprintf('%d Kayıt', (int) $activity['total'])); ?></span>
                    <?php
                    echo wp_kses_post((string) paginate_links([
                        'base' => add_query_arg('log_page', '%#%', $pagination_url),
                        'format' => '',
                        'current' => (int) $activity['page'],
                        'total' => (int) $activity['total_pages'],
                        'mid_size' => 2,
                        'end_size' => 1,
                        'prev_text' => '‹ Önceki',
                        'next_text' => 'Sonraki ›',
                        'type' => 'list',
                    ]));
                    ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    private static function render_settings_tab(array $settings): void
    {
        $auto_export_groups = [
            'İçerik' => [
                'auto_export_post_created' => ['Yeni yazı yayınlandığında', 'Yeni bir blog yazısı ilk kez yayınlandığında.'],
                'auto_export_post_updated' => ['Mevcut yazı güncellendiğinde', 'Yayındaki bir blog yazısı değiştirildiğinde veya yayından kaldırıldığında.'],
                'auto_export_page_created' => ['Yeni sayfa yayınlandığında', 'Yeni bir sayfa ilk kez yayınlandığında.'],
                'auto_export_page_updated' => ['Mevcut sayfa güncellendiğinde', 'Yayındaki bir sayfa değiştirildiğinde veya yayından kaldırıldığında.'],
                'auto_export_custom_content' => ['Özel içerik türleri değiştiğinde', 'Ürün, portföy ve benzeri yayındaki özel içerikler değiştiğinde.'],
            ],
            'Yapı ve Tasarım' => [
                'auto_export_taxonomy' => ['Kategori veya etiket değiştiğinde', 'Kategori, etiket ya da özel sınıflandırmalar değiştirildiğinde.'],
                'auto_export_media' => ['Medya değiştiğinde', 'Görsel veya başka bir medya dosyası eklendiğinde, güncellendiğinde ya da silindiğinde.'],
                'auto_export_menu' => ['Menü değiştiğinde', 'Gezinme menülerinin yapısı veya bağlantıları değiştirildiğinde.'],
                'auto_export_widgets' => ['Bileşenler değiştiğinde', 'Widget ve bileşen yerleşimleri güncellendiğinde.'],
                'auto_export_theme' => ['Tema değiştiğinde', 'Tema değiştirildiğinde, güncellendiğinde veya özelleştirici ayarları kaydedildiğinde.'],
                'auto_export_site_settings' => ['Site ayarları değiştiğinde', 'Site adı, açıklama, ana sayfa, okuma veya kalıcı bağlantı ayarları değiştirildiğinde.'],
            ],
        ];
        ?>
        <h2>Ayarlar</h2>
        <form class="ragstat-settings-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static'); ?>
            <section class="ragstat-auto-export-card" aria-labelledby="ragstat-auto-export-title">
                <div class="ragstat-auto-export-card__heading">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <div>
                        <h3 id="ragstat-auto-export-title">Otomatik Statik Site Oluşturma ve Deploy</h3>
                        <p>Seçilen değişikliklerden sonra statik site oluşturulur; deployment webhook ayarlıysa deploy akışı otomatik tetiklenir.</p>
                    </div>
                    <label class="ragstat-switch" aria-label="Otomatik statik site oluşturmayı etkinleştir">
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
                <p class="ragstat-auto-export-card__note"><span class="dashicons dashicons-clock" aria-hidden="true"></span>Arka arkaya yapılan değişiklikler 60 saniye boyunca birleştirilerek tek export ve deploy olarak çalıştırılır.</p>
            </section>
            <table class="form-table" role="presentation">
                <tr><th><label for="ragstat-target">Canlı Site Adresi</label></th><td><input class="regular-text" id="ragstat-target" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[target_url]" value="<?php echo esc_attr((string) $settings['target_url']); ?>"><p class="description">Örnek: https://example.com</p></td></tr>
                <tr><th><label for="ragstat-limit">En Fazla URL</label></th><td><input id="ragstat-limit" type="number" min="10" max="20000" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[maximum_urls]" value="<?php echo esc_attr((string) $settings['maximum_urls']); ?>"></td></tr>
                <tr><th><label for="ragstat-excluded">Hariç Tutulan Yollar</label></th><td><textarea class="large-text code" rows="7" id="ragstat-excluded" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[excluded_paths]"><?php echo esc_textarea((string) $settings['excluded_paths']); ?></textarea><p class="description">Satır başına bir yol öneki.</p></td></tr>
                <tr><th><label for="ragstat-archive-retention">Saklanacak ZIP Sayısı</label></th><td><input id="ragstat-archive-retention" type="number" min="1" max="100" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[archive_retention]" value="<?php echo esc_attr((string) $settings['archive_retention']); ?>"><p class="description">En son kaç başarılı export arşivinin saklanacağını belirler. Varsayılan: 5.</p></td></tr>
                <tr><th><label for="ragstat-webhook">Deployment Webhook</label></th><td><input class="large-text code" id="ragstat-webhook" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_url]" value="<?php echo esc_attr((string) $settings['deployment_webhook_url']); ?>"><p class="description">GitHub için: https://api.github.com/repos/SAHIP/REPO/dispatches</p></td></tr>
                <tr><th><label for="ragstat-webhook-token">Webhook Bearer Token</label></th><td><input class="regular-text" id="ragstat-webhook-token" type="password" autocomplete="new-password" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_token]" value="" placeholder="<?php echo $settings['deployment_webhook_token'] !== '' ? esc_attr('Kayıtlı tokenı korumak için boş bırakın') : ''; ?>"><p class="description">GitHub kullanılıyorsa yalnızca bu repository için Contents: write yetkili fine-grained token kullanın.</p></td></tr>
            </table>
            <?php submit_button('Ayarları Kaydet'); ?>
        </form>
        <?php
    }

    private static function render_search_tab(array $settings): void
    {
        $option_name = Plugin::SEARCH_SETTINGS_KEY;
        ?>
        <div class="ragstat-search-header">
            <div>
                <h2>Arama</h2>
                <p>Fuse.js ile çalışan, sunucu gerektirmeyen statik site aramasını yapılandırın.</p>
            </div>
            <span class="ragstat-search-badge">Fuse.js 7.3.0</span>
        </div>
        <form class="ragstat-search-settings" method="post" action="options.php">
            <?php settings_fields('ragnus_static_search'); ?>

            <section class="ragstat-search-card">
                <div class="ragstat-search-card__heading">
                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                    <div><h3>Statik Arama</h3><p>Arama sayfası, indeks ve gerekli Fuse.js dosyaları export ZIP’ine eklenir.</p></div>
                    <label class="ragstat-switch" aria-label="Statik Aramayı Etkinleştir">
                        <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[enabled]" value="1" <?php checked((string) $settings['enabled'], '1'); ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>
                <div class="ragstat-search-grid">
                    <div><label for="ragstat-search-path">Arama Sayfası Yolu</label><div class="ragstat-input-group is-compact"><span>/</span><input id="ragstat-search-path" type="text" name="<?php echo esc_attr($option_name); ?>[page_path]" value="<?php echo esc_attr((string) $settings['page_path']); ?>" required><span>/</span></div><p>Örnek: <code>/arama/</code></p></div>
                    <div><label for="ragstat-search-limit">Gösterilecek Sonuç Sayısı</label><input id="ragstat-search-limit" type="number" min="5" max="100" name="<?php echo esc_attr($option_name); ?>[result_limit]" value="<?php echo esc_attr((string) $settings['result_limit']); ?>"></div>
                    <div><label for="ragstat-search-min-chars">Minimum Arama Karakteri</label><input id="ragstat-search-min-chars" type="number" min="1" max="10" name="<?php echo esc_attr($option_name); ?>[min_chars]" value="<?php echo esc_attr((string) $settings['min_chars']); ?>"></div>
                    <div><label for="ragstat-search-content-limit">İçerik Karakter Sınırı</label><input id="ragstat-search-content-limit" type="number" min="500" max="20000" step="500" name="<?php echo esc_attr($option_name); ?>[content_limit]" value="<?php echo esc_attr((string) $settings['content_limit']); ?>"><p>Her sayfadan indekse alınacak en fazla metin uzunluğu.</p></div>
                    <div><label for="ragstat-search-threshold">Bulanıklık Eşiği</label><input id="ragstat-search-threshold" type="number" min="0.1" max="0.8" step="0.05" name="<?php echo esc_attr($option_name); ?>[threshold]" value="<?php echo esc_attr((string) $settings['threshold']); ?>"><p>Düşük değer daha kesin, yüksek değer daha toleranslı sonuç verir.</p></div>
                    <div><label for="ragstat-search-token-match">Kelime Eşleştirme</label><select id="ragstat-search-token-match" name="<?php echo esc_attr($option_name); ?>[token_match]"><option value="all" <?php selected($settings['token_match'], 'all'); ?>>Tüm kelimeler eşleşsin</option><option value="any" <?php selected($settings['token_match'], 'any'); ?>>Herhangi bir kelime eşleşsin</option></select></div>
                </div>
            </section>

            <section class="ragstat-search-card">
                <div class="ragstat-search-card__heading">
                    <span class="dashicons dashicons-filter" aria-hidden="true"></span>
                    <div><h3>İndeksleme Seçicileri</h3><p>Sayfa ve yazı HTML’inden hangi alanların alınacağını CSS selector ile belirleyin.</p></div>
                </div>
                <div class="ragstat-search-selectors">
                    <div><label for="ragstat-title-selector">Başlık İçin CSS Selector</label><input id="ragstat-title-selector" type="text" name="<?php echo esc_attr($option_name); ?>[title_selector]" value="<?php echo esc_attr((string) $settings['title_selector']); ?>" required><p>Örnek: <code>title</code>, <code>h1.entry-title</code> veya <code>meta[property="og:title"]</code></p></div>
                    <div><label for="ragstat-content-selector">İçerik İçin CSS Selector</label><input id="ragstat-content-selector" type="text" name="<?php echo esc_attr($option_name); ?>[content_selector]" value="<?php echo esc_attr((string) $settings['content_selector']); ?>" required><p>Örnek: <code>body</code>, <code>.entry-content</code> veya <code>#main</code></p></div>
                    <div><label for="ragstat-excerpt-selector">Özet İçin CSS Selector</label><input id="ragstat-excerpt-selector" type="text" name="<?php echo esc_attr($option_name); ?>[excerpt_selector]" value="<?php echo esc_attr((string) $settings['excerpt_selector']); ?>" required><p>Alan bulunamazsa içerikten otomatik kısa özet üretilir.</p></div>
                    <div><label for="ragstat-search-excludes">İndeks Dışında Tutulacak URL’ler</label><textarea id="ragstat-search-excludes" rows="6" name="<?php echo esc_attr($option_name); ?>[exclude_urls]"><?php echo esc_textarea((string) $settings['exclude_urls']); ?></textarea><p>Satır başına tam URL, URL parçası veya kelime yazabilirsiniz.</p></div>
                </div>
            </section>

            <section class="ragstat-search-card">
                <div class="ragstat-search-card__heading">
                    <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                    <div><h3>Fuse.js Alanları ve Ağırlıkları</h3><p>Aranacak alanları seçin ve sonuç sıralamasındaki etkilerini belirleyin.</p></div>
                </div>
                <div class="ragstat-search-fields">
                    <?php
                    $index_fields = [
                        'title' => ['Başlık', 'Başlık eşleşmelerini en üstte gösterir.'],
                        'excerpt' => ['Özet', 'Kısa açıklama ve özet metninde arar.'],
                        'content' => ['İçerik', 'Sayfa ve yazının ana metninde arar.'],
                        'taxonomies' => ['Kategori ve Etiketler', 'WordPress kategori ve etiket adlarını indekse ekler.'],
                    ];
                    foreach ($index_fields as $field => [$label, $description]) :
                        $weight_key = $field === 'taxonomies' ? 'taxonomy_weight' : $field . '_weight';
                        ?>
                        <div class="ragstat-search-field-row">
                            <label class="ragstat-search-field-check"><input type="checkbox" name="<?php echo esc_attr($option_name); ?>[index_<?php echo esc_attr($field); ?>]" value="1" <?php checked((string) $settings['index_' . $field], '1'); ?>><span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($description); ?></small></span></label>
                            <label class="ragstat-search-weight">Ağırlık <input type="number" min="0.1" max="10" step="0.1" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($weight_key); ?>]" value="<?php echo esc_attr((string) $settings[$weight_key]); ?>"></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php submit_button('Arama Ayarlarını Kaydet'); ?>
        </form>
        <?php
    }

    private static function render_hide_tab(array $settings): void
    {
        $option_name = Plugin::HIDE_SETTINGS_KEY;
        ?>
        <h2>Hide</h2>
        <p class="ragstat-hide-intro">WordPress'e özgü dizin ve yol adlarının statik çıktıda hangi adlarla kullanılacağını belirleyin. Kaynak WordPress dosyaları değiştirilmez.</p>
        <form class="ragstat-hide-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static_hide'); ?>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-wp-content">WP-Content Dizini</label>
                <input id="ragstat-hide-wp-content" type="text" name="<?php echo esc_attr($option_name); ?>[wp_content_directory]" value="<?php echo esc_attr((string) $settings['wp_content_directory']); ?>" required>
                <p>Statik çıktıda <code>wp-content</code> dizininin yerine kullanılacak ad.</p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-wp-includes">WP-Includes Dizini</label>
                <input id="ragstat-hide-wp-includes" type="text" name="<?php echo esc_attr($option_name); ?>[wp_includes_directory]" value="<?php echo esc_attr((string) $settings['wp_includes_directory']); ?>" required>
                <p>Statik çıktıda <code>wp-includes</code> dizininin yerine kullanılacak ad.</p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-uploads">Uploads Dizini</label>
                <div class="ragstat-input-group"><span data-ragstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="ragstat-hide-uploads" type="text" name="<?php echo esc_attr($option_name); ?>[uploads_directory]" value="<?php echo esc_attr((string) $settings['uploads_directory']); ?>" required></div>
                <p>Statik çıktıda <code>wp-content/uploads</code> yolunun yerine kullanılacak alt dizin.</p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-plugins">Plugins Dizini</label>
                <div class="ragstat-input-group"><span data-ragstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="ragstat-hide-plugins" type="text" name="<?php echo esc_attr($option_name); ?>[plugins_directory]" value="<?php echo esc_attr((string) $settings['plugins_directory']); ?>" required></div>
                <p>Statik çıktıda <code>wp-content/plugins</code> yolunun yerine kullanılacak alt dizin.</p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-themes">Themes Dizini</label>
                <div class="ragstat-input-group"><span data-ragstat-content-prefix><?php echo esc_html((string) $settings['wp_content_directory']); ?>/</span><input id="ragstat-hide-themes" type="text" name="<?php echo esc_attr($option_name); ?>[themes_directory]" value="<?php echo esc_attr((string) $settings['themes_directory']); ?>" required></div>
                <p>Statik çıktıda <code>wp-content/themes</code> yolunun yerine kullanılacak alt dizin.</p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-style">Tema Stil Dosyası</label>
                <div class="ragstat-input-group is-compact"><input id="ragstat-hide-style" type="text" name="<?php echo esc_attr($option_name); ?>[theme_style_name]" value="<?php echo esc_attr((string) $settings['theme_style_name']); ?>" required><span>.css</span></div>
                <p>Aktif tema içindeki <code>style.css</code> dosyasının statik çıktıdaki adı.</p>
            </div>

            <div class="ragstat-hide-field">
                <label for="ragstat-hide-author">Yazar URL'si</label>
                <input id="ragstat-hide-author" type="text" name="<?php echo esc_attr($option_name); ?>[author_url]" value="<?php echo esc_attr((string) $settings['author_url']); ?>" required>
                <p>Statik çıktıda <code>/author/</code> yolunun yerine kullanılacak yol.</p>
            </div>

            <section class="ragstat-hide-options" aria-labelledby="ragstat-hide-traces-title">
                <div class="ragstat-hide-options__header">
                    <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                    <div>
                        <h3 id="ragstat-hide-traces-title">WordPress İzlerini Gizle</h3>
                        <p>Seçilen WordPress tanımlayıcılarını statik HTML çıktısından kaldırır.</p>
                    </div>
                </div>
                <?php
                $hide_toggles = [
                    'hide_wordpress_version' => ['WordPress Sürümünü Gizle', 'WordPress çekirdek sürümünü belirten asset sürüm parametrelerini kaldırır.'],
                    'hide_generator_meta' => ['WordPress Generator Meta Etiketini Gizle', 'WordPress sürümünü açıklayan generator meta etiketini kaldırır.'],
                    'hide_wordpress_dns_prefetch' => ['WordPress DNS Prefetch Bağlantısını Gizle', 'WordPress servislerine ait DNS prefetch bağlantılarını kaldırır.'],
                    'hide_rsd_header' => ['RSD Header Bağlantısını Gizle', 'Really Simple Discovery bağlantısını statik HTML’den kaldırır.'],
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
                        <h3 id="ragstat-disable-features-title">Statik Çıktıda Devre Dışı Bırak</h3>
                        <p>Statik sitede kullanılmayan WordPress bağlantı ve betiklerini temizler.</p>
                    </div>
                </div>
                <?php
                $disable_toggles = [
                    'disable_xml_rpc' => ['XML-RPC Bağlantılarını Devre Dışı Bırak', 'XML-RPC ve pingback keşif bağlantılarını kaldırır.'],
                    'disable_embed_scripts' => ['Embed Scriptlerini Devre Dışı Bırak', 'WordPress embed betiklerini statik HTML’den kaldırır.'],
                    'disable_db_debug' => ['Frontend DB Debug Bilgisini Devre Dışı Bırak', 'Yalnızca export isteklerinde veritabanı hata ayrıntılarının gösterilmesini engeller.'],
                    'disable_wlw_manifest' => ['WLW Manifest Bağlantısını Devre Dışı Bırak', 'Windows Live Writer manifest bağlantısını kaldırır.'],
                    'disable_emojis' => ['Emoji Scriptlerini Devre Dışı Bırak', 'WordPress emoji scriptlerini ve stillerini statik HTML’den kaldırır.'],
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

            <?php submit_button('Hide Ayarlarını Kaydet'); ?>
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
                <h2>Diagnostics</h2>
                <p class="ragstat-diagnostics-summary"><strong><?php echo esc_html(sprintf('%1$d / %2$d kontrol başarılı.', $passed, $total)); ?></strong> Son kontrol: <?php echo $checked_timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $checked_timestamp)); ?></p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ragnus_static_refresh_diagnostics">
                <?php wp_nonce_field('ragnus_static_refresh_diagnostics'); ?>
                <?php submit_button('Tekrar Kontrol Et', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php if (isset($_GET['checked']) && sanitize_key(wp_unslash((string) $_GET['checked'])) === '1') : ?>
            <div class="ragstat-inline-alert is-success" role="status">
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <span>Diagnostics kontrolleri güncellendi.</span>
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
                                <span class="ragstat-diagnostic-icon <?php echo $check['passed'] ? 'is-passed' : 'is-failed'; ?>" aria-label="<?php echo $check['passed'] ? 'Başarılı' : 'Başarısız'; ?>">
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
        <h2>About</h2>
        <section class="ragstat-about-card" aria-labelledby="ragstat-about-title">
            <div class="ragstat-about-card__intro">
                <span class="ragstat-about-card__icon dashicons dashicons-media-document" aria-hidden="true"></span>
                <div>
                    <h3 id="ragstat-about-title">Ragnus Static Publisher</h3>
                    <p>WordPress sitenizi statik dosyalara dönüştürmek ve yayın süreçlerine hazırlamak için geliştirilmiştir.</p>
                </div>
            </div>
            <dl class="ragstat-about-details">
                <div>
                    <dt>Versiyon Numarası</dt>
                    <dd><code><?php echo esc_html(RAGSTAT_VERSION); ?></code></dd>
                </div>
                <div>
                    <dt>Destek Maili</dt>
                    <dd><a href="<?php echo esc_url('mailto:info@ragnus.co'); ?>">info@ragnus.co</a></dd>
                </div>
                <div>
                    <dt>Eklenti Web Sitesi</dt>
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
