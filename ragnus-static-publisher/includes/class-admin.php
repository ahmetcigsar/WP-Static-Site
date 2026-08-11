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
    }

    public static function sanitize(array $value): array
    {
        $current = Plugin::settings();
        $submitted_token = sanitize_text_field((string) ($value['deployment_webhook_token'] ?? ''));

        return [
            'target_url' => esc_url_raw(untrailingslashit((string) ($value['target_url'] ?? home_url()))),
            'maximum_urls' => max(10, min(20000, absint($value['maximum_urls'] ?? 2000))),
            'excluded_paths' => sanitize_textarea_field((string) ($value['excluded_paths'] ?? '')),
            'auto_export' => isset($value['auto_export']) ? '1' : '0',
            'archive_retention' => max(1, min(100, absint($value['archive_retention'] ?? 5))),
            'deployment_webhook_url' => esc_url_raw((string) ($value['deployment_webhook_url'] ?? '')),
            'deployment_webhook_token' => $submitted_token !== '' ? $submitted_token : (string) $current['deployment_webhook_token'],
        ];
    }

    public static function start_export(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Bu işlem için yetkiniz yok.', 403);
        }
        check_admin_referer('ragnus_static_export');
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
            'files' => 'Files',
            'activity' => 'Activity Log',
            'settings' => 'Settings',
            'diagnostics' => 'Diagnostics',
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
            <?php elseif ($current_tab === 'files') : ?>
                <?php self::render_files_tab($archives); ?>
            <?php elseif ($current_tab === 'activity') : ?>
                <?php self::render_activity_tab(); ?>
            <?php elseif ($current_tab === 'settings') : ?>
                <?php self::render_settings_tab(Plugin::settings()); ?>
            <?php else : ?>
                <?php self::render_diagnostics_tab(); ?>
            <?php endif; ?>
            </main>
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
        ?>
        <h2>Yayın Durumu</h2>
        <table class="widefat striped ragstat-status-table">
            <tbody>
            <tr><th>Durum</th><td><?php echo esc_html($state_labels[$state] ?? 'Henüz Çalışmadı'); ?></td></tr>
            <tr>
                <th>İlerleme</th>
                <td>
                    <?php if ($state === 'completed') : ?>
                        <span class="ragstat-progress-status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong>Tamamlandı</strong></span>
                    <?php elseif ($state === 'failed') : ?>
                        <span class="ragstat-progress-status"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><strong>Başarısız</strong></span>
                    <?php else : ?>
                        <div class="ragstat-progress <?php echo in_array($state, ['queued', 'running'], true) ? 'is-active' : ''; ?>" role="progressbar" aria-label="Static oluşturma ilerlemesi" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $progress); ?>">
                            <div class="ragstat-progress__bar" style="width:<?php echo esc_attr((string) $progress); ?>%"></div>
                        </div>
                        <span><?php echo esc_html((string) $progress); ?>%</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr><th>İş Kimliği</th><td><code><?php echo esc_html((string) ($status['job_id'] ?? '—')); ?></code></td></tr>
            <tr><th>URL Sayısı</th><td><?php echo esc_html((string) ($status['url_count'] ?? 0)); ?></td></tr>
            <tr><th>Son Statik Oluşturma</th><td><?php echo $last_completed_timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $last_completed_timestamp)); ?></td></tr>
            <?php if (! empty($status['error'])) : ?><tr><th>Hata</th><td><?php echo esc_html((string) $status['error']); ?></td></tr><?php endif; ?>
            </tbody>
        </table>

        <form class="ragstat-actions" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ragnus_static_export">
            <?php wp_nonce_field('ragnus_static_export'); ?>
            <?php submit_button('Şimdi Statik Export Oluştur', 'primary', 'submit', false); ?>
            <?php if ($archives !== []) : ?>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ragnus_static_download'), 'ragnus_static_download')); ?>">Son ZIP'i İndir</a>
            <?php endif; ?>
        </form>
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
                        <?php submit_button('Uygula', 'action', 'bulk_submit', false, ['onclick' => "if (this.form.archive_bulk_action.value === 'delete') return confirm('Seçilen ZIP dosyaları kalıcı olarak silinecek. Devam edilsin mi?');"]); ?>
                    </div>
                    <br class="clear">
                </div>
                <table class="widefat striped ragstat-files-table">
                    <thead><tr><td class="manage-column check-column"><input id="cb-select-all-1" type="checkbox"><label for="cb-select-all-1"><span class="screen-reader-text">Tümünü Seç</span></label></td><th>İş Kimliği</th><th>URL Sayısı</th><th>Oluşturma Tarihi</th><th>Oluşturma Saati</th><th>İşlem</th></tr></thead>
                    <tbody>
                    <?php foreach ($archives as $archive) : ?>
                        <?php
                        $archive_id = (string) $archive['id'];
                        $download_url = wp_nonce_url(
                            add_query_arg(['action' => 'ragnus_static_download_archive', 'archive_id' => $archive_id], admin_url('admin-post.php')),
                            'ragnus_static_download_archive_' . $archive_id
                        );
                        ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" name="archive_ids[]" value="<?php echo esc_attr($archive_id); ?>"><span class="screen-reader-text"><?php echo esc_html((string) $archive['job_id']); ?> seç</span></th>
                            <td><code><?php echo esc_html((string) $archive['job_id']); ?></code></td>
                            <td><?php echo $archive['url_count'] === null ? '—' : esc_html((string) $archive['url_count']); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $archive['created_at'])); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $archive['created_at'])); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($download_url); ?>">İndir</a> <button class="button button-small button-link-delete" type="submit" name="delete_archive" value="<?php echo esc_attr($archive_id); ?>" onclick="if (!confirm('Bu ZIP dosyası kalıcı olarak silinecek. Devam edilsin mi?')) return false; this.form.archive_bulk_action.value = '';">Sil</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>

        <form class="ragstat-cleanup-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ragnus_static_cleanup_exports">
            <?php wp_nonce_field('ragnus_static_cleanup_exports'); ?>
            <?php submit_button('Eski Dosyaları Sil', 'delete', 'submit', false, ['onclick' => "return confirm('En son ZIP dışındaki tüm eski ZIP dosyaları silinecek. Devam edilsin mi?');"]); ?>
            <p class="description">En son ZIP dosyası korunur; önceki ZIP dosyaları ve bunlara ait geçici build klasörleri kalıcı olarak silinir.</p>
        </form>
        <?php
    }

    private static function render_activity_tab(): void
    {
        $requested_page = isset($_GET['log_page']) ? absint($_GET['log_page']) : 1;
        $activity = Activity_Log::page($requested_page);
        $entries = $activity['entries'];
        $level_labels = ['info' => 'Bilgi', 'warning' => 'Uyarı', 'error' => 'Hata'];
        ?>
        <h2>Activity Log</h2>
        <?php if ($activity['job_id'] !== '') : ?>
            <p>En son static işlemine ait kayıtlar: <code><?php echo esc_html((string) $activity['job_id']); ?></code></p>
        <?php endif; ?>
        <?php if ($entries === []) : ?>
            <p>Henüz kaydedilmiş bir export etkinliği yok.</p>
        <?php else : ?>
            <table class="widefat striped ragstat-activity-table">
                <thead><tr><th>Tarih</th><th>Saat</th><th>Seviye</th><th>Kaynak Adres</th><th>Statik Adres</th></tr></thead>
                <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <?php
                    $timestamp = strtotime((string) ($entry['time'] ?? ''));
                    $level = sanitize_key((string) ($entry['level'] ?? 'info'));
                    ?>
                    <tr>
                        <td><?php echo $timestamp === false ? '—' : esc_html(wp_date((string) get_option('date_format'), $timestamp)); ?></td>
                        <td><?php echo $timestamp === false ? '—' : esc_html(wp_date((string) get_option('time_format'), $timestamp)); ?></td>
                        <td><?php echo esc_html($level_labels[$level] ?? ucfirst($level)); ?></td>
                        <td><?php if (($entry['source_url'] ?? '') === '') : ?>—<?php else : ?><code><?php echo esc_html((string) $entry['source_url']); ?></code><?php endif; ?></td>
                        <td><?php if (($entry['static_path'] ?? '') === '') : ?>—<?php else : ?><code><?php echo esc_html((string) $entry['static_path']); ?></code><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($activity['total_pages'] > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html(sprintf('%d kayıt', (int) $activity['total'])); ?></span>
                        <span class="pagination-links">
                            <?php
                            echo wp_kses_post((string) paginate_links([
                                'base' => add_query_arg('log_page', '%#%', self::admin_page_url('activity')),
                                'format' => '',
                                'current' => (int) $activity['page'],
                                'total' => (int) $activity['total_pages'],
                                'prev_text' => '‹',
                                'next_text' => '›',
                            ]));
                            ?>
                        </span>
                    </div>
                    <br class="clear">
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    private static function render_settings_tab(array $settings): void
    {
        ?>
        <h2>Ayarlar</h2>
        <form class="ragstat-settings-form" method="post" action="options.php">
            <?php settings_fields('ragnus_static'); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="ragstat-target">Canlı Site Adresi</label></th><td><input class="regular-text" id="ragstat-target" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[target_url]" value="<?php echo esc_attr((string) $settings['target_url']); ?>"><p class="description">Örnek: https://example.com</p></td></tr>
                <tr><th><label for="ragstat-limit">En Fazla URL</label></th><td><input id="ragstat-limit" type="number" min="10" max="20000" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[maximum_urls]" value="<?php echo esc_attr((string) $settings['maximum_urls']); ?>"></td></tr>
                <tr><th><label for="ragstat-excluded">Hariç Tutulan Yollar</label></th><td><textarea class="large-text code" rows="7" id="ragstat-excluded" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[excluded_paths]"><?php echo esc_textarea((string) $settings['excluded_paths']); ?></textarea><p class="description">Satır başına bir yol öneki.</p></td></tr>
                <tr><th>Otomatik Export</th><td><label><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[auto_export]" value="1" <?php checked($settings['auto_export'], '1'); ?>> Yayımlanmış içerik değiştiğinde export kuyruğuna ekle</label></td></tr>
                <tr><th><label for="ragstat-archive-retention">Saklanacak ZIP Sayısı</label></th><td><input id="ragstat-archive-retention" type="number" min="1" max="100" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[archive_retention]" value="<?php echo esc_attr((string) $settings['archive_retention']); ?>"><p class="description">En son kaç başarılı export arşivinin saklanacağını belirler. Varsayılan: 5.</p></td></tr>
                <tr><th><label for="ragstat-webhook">Deployment Webhook</label></th><td><input class="large-text code" id="ragstat-webhook" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_url]" value="<?php echo esc_attr((string) $settings['deployment_webhook_url']); ?>"><p class="description">GitHub için: https://api.github.com/repos/SAHIP/REPO/dispatches</p></td></tr>
                <tr><th><label for="ragstat-webhook-token">Webhook Bearer Token</label></th><td><input class="regular-text" id="ragstat-webhook-token" type="password" autocomplete="new-password" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_token]" value="" placeholder="<?php echo $settings['deployment_webhook_token'] !== '' ? esc_attr('Kayıtlı tokenı korumak için boş bırakın') : ''; ?>"><p class="description">GitHub kullanılıyorsa yalnızca bu repository için Contents: write yetkili fine-grained token kullanın.</p></td></tr>
            </table>
            <?php submit_button('Ayarları Kaydet'); ?>
        </form>
        <?php
    }

    private static function render_diagnostics_tab(): void
    {
        $groups = Diagnostics::checks();
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
        ?>
        <h2>Diagnostics</h2>
        <p class="ragstat-diagnostics-summary"><strong><?php echo esc_html(sprintf('%1$d / %2$d kontrol başarılı.', $passed, $total)); ?></strong> Bu sonuçlar sayfa her açıldığında mevcut sunucu ve WordPress ayarlarından yeniden hesaplanır.</p>
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
}
