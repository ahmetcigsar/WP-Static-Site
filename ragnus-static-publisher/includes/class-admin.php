<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

final class Admin
{
    public static function menu(): void
    {
        add_management_page(
            'Ragnus Static Publisher',
            'Static Publisher',
            'manage_options',
            'ragnus-static-publisher',
            [self::class, 'render']
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
        wp_safe_redirect(admin_url('tools.php?page=ragnus-static-publisher&started=1'));
        exit;
    }

    public static function download_export(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Bu işlem için yetkiniz yok.', 403);
        }
        check_admin_referer('ragnus_static_download');

        $status = Plugin::status();
        $archive = $status['archive'] ?? '';
        if (($status['state'] ?? '') !== 'completed' || ! is_string($archive) || ! is_readable($archive)) {
            wp_die('İndirilebilir export bulunamadı.', 404);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="ragnus-static-export.zip"');
        header('Content-Length: ' . (string) filesize($archive));
        readfile($archive);
        exit;
    }

    public static function cleanup_exports(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Bu işlem için yetkiniz yok.', 403);
        }
        check_admin_referer('ragnus_static_cleanup_exports');

        if (get_transient(Plugin::LOCK_KEY)) {
            wp_safe_redirect(admin_url('tools.php?page=ragnus-static-publisher&cleanup=running'));
            exit;
        }

        $result = Archive_Manager::delete_old_archives();
        $query = [
            'page' => 'ragnus-static-publisher',
            'cleanup' => $result['failed'] > 0 ? 'partial' : 'success',
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ];
        wp_safe_redirect(add_query_arg($query, admin_url('tools.php')));
        exit;
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $settings = Plugin::settings();
        $status = Plugin::public_status();
        $archives = Archive_Manager::archives();
        $cleanup_status = isset($_GET['cleanup']) ? sanitize_key(wp_unslash((string) $_GET['cleanup'])) : '';
        ?>
        <div class="wrap">
            <h1>Ragnus Static Publisher</h1>
            <p>WordPress sitenizin statik kopyasını üretin. Cloudflare kimlik bilgileri bu eklentide tutulmaz.</p>

            <?php if ($cleanup_status !== '') : ?>
                <?php if ($cleanup_status === 'success') : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf('%d eski ZIP dosyası silindi.', absint($_GET['deleted'] ?? 0))); ?></p></div>
                <?php elseif ($cleanup_status === 'partial') : ?>
                    <div class="notice notice-warning is-dismissible"><p><?php echo esc_html(sprintf('%1$d eski ZIP dosyası silindi, %2$d dosya silinemedi.', absint($_GET['deleted'] ?? 0), absint($_GET['failed'] ?? 0))); ?></p></div>
                <?php elseif ($cleanup_status === 'running') : ?>
                    <div class="notice notice-warning is-dismissible"><p>Export devam ederken eski dosyalar silinemez.</p></div>
                <?php endif; ?>
            <?php endif; ?>

            <h2>Yayın durumu</h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                <tr><th>Durum</th><td><?php echo esc_html((string) ($status['state'] ?? 'Henüz çalışmadı')); ?></td></tr>
                <tr><th>İlerleme</th><td><?php echo esc_html((string) ($status['progress'] ?? 0)); ?>%</td></tr>
                <tr><th>İş kimliği</th><td><code><?php echo esc_html((string) ($status['job_id'] ?? '—')); ?></code></td></tr>
                <tr><th>URL sayısı</th><td><?php echo esc_html((string) ($status['url_count'] ?? 0)); ?></td></tr>
                <?php if (! empty($status['error'])) : ?><tr><th>Hata</th><td><?php echo esc_html((string) $status['error']); ?></td></tr><?php endif; ?>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:16px 0 32px">
                <input type="hidden" name="action" value="ragnus_static_export">
                <?php wp_nonce_field('ragnus_static_export'); ?>
                <?php submit_button('Şimdi statik export oluştur', 'primary', 'submit', false); ?>
                <?php if (($status['state'] ?? '') === 'completed') : ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ragnus_static_download'), 'ragnus_static_download')); ?>">Son ZIP'i indir</a>
                <?php endif; ?>
            </form>

            <h2>Ayarlar</h2>
            <form method="post" action="options.php">
                <?php settings_fields('ragnus_static'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="ragstat-target">Canlı site adresi</label></th>
                        <td><input class="regular-text" id="ragstat-target" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[target_url]" value="<?php echo esc_attr((string) $settings['target_url']); ?>"><p class="description">Örnek: https://example.com</p></td>
                    </tr>
                    <tr>
                        <th><label for="ragstat-limit">En fazla URL</label></th>
                        <td><input id="ragstat-limit" type="number" min="10" max="20000" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[maximum_urls]" value="<?php echo esc_attr((string) $settings['maximum_urls']); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="ragstat-excluded">Hariç tutulan yollar</label></th>
                        <td><textarea class="large-text code" rows="7" id="ragstat-excluded" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[excluded_paths]"><?php echo esc_textarea((string) $settings['excluded_paths']); ?></textarea><p class="description">Satır başına bir yol öneki.</p></td>
                    </tr>
                    <tr>
                        <th>Otomatik export</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[auto_export]" value="1" <?php checked($settings['auto_export'], '1'); ?>> Yayımlanmış içerik değiştiğinde export kuyruğuna ekle</label></td>
                    </tr>
                    <tr>
                        <th><label for="ragstat-archive-retention">Saklanacak ZIP sayısı</label></th>
                        <td><input id="ragstat-archive-retention" type="number" min="1" max="100" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[archive_retention]" value="<?php echo esc_attr((string) $settings['archive_retention']); ?>"><p class="description">En son kaç başarılı export arşivinin saklanacağını belirler. Varsayılan: 5.</p></td>
                    </tr>
                    <tr>
                        <th><label for="ragstat-webhook">Deployment webhook</label></th>
                        <td><input class="large-text code" id="ragstat-webhook" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_url]" value="<?php echo esc_attr((string) $settings['deployment_webhook_url']); ?>"><p class="description">GitHub için: https://api.github.com/repos/SAHIP/REPO/dispatches</p></td>
                    </tr>
                    <tr>
                        <th><label for="ragstat-webhook-token">Webhook bearer token</label></th>
                        <td><input class="regular-text" id="ragstat-webhook-token" type="password" autocomplete="new-password" name="<?php echo esc_attr(Plugin::SETTINGS_KEY); ?>[deployment_webhook_token]" value="" placeholder="<?php echo $settings['deployment_webhook_token'] !== '' ? esc_attr('Kayıtlı tokenı korumak için boş bırakın') : ''; ?>"><p class="description">GitHub kullanılıyorsa yalnızca bu repository için Contents: write yetkili fine-grained token kullanın.</p></td>
                    </tr>
                </table>
                <?php submit_button('Ayarları kaydet'); ?>
            </form>

            <hr>
            <h2>ZIP dosyaları</h2>
            <?php if ($archives === []) : ?>
                <p>Henüz oluşturulmuş bir ZIP dosyası yok.</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr><th>İş kimliği</th><th>URL sayısı</th><th>Oluşturma tarihi</th><th>Oluşturma saati</th></tr></thead>
                    <tbody>
                    <?php foreach ($archives as $archive) : ?>
                        <tr>
                            <td><code><?php echo esc_html((string) $archive['job_id']); ?></code></td>
                            <td><?php echo $archive['url_count'] === null ? '—' : esc_html((string) $archive['url_count']); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('date_format'), (int) $archive['created_at'])); ?></td>
                            <td><?php echo esc_html(wp_date((string) get_option('time_format'), (int) $archive['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
                <input type="hidden" name="action" value="ragnus_static_cleanup_exports">
                <?php wp_nonce_field('ragnus_static_cleanup_exports'); ?>
                <?php submit_button('Eski Dosyaları Sil', 'delete', 'submit', false, ['onclick' => "return confirm('En son ZIP dışındaki tüm eski ZIP dosyaları silinecek. Devam edilsin mi?');"]); ?>
                <p class="description">En son ZIP dosyası korunur; önceki ZIP dosyaları ve bunlara ait geçici build klasörleri kalıcı olarak silinir.</p>
            </form>
        </div>
        <?php
    }
}
