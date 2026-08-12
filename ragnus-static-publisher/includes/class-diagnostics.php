<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

final class Diagnostics
{
    public const OPTION_KEY = 'ragnus_static_diagnostics';

    public static function report(): array
    {
        $report = get_option(self::OPTION_KEY, []);
        if (is_array($report)
            && is_array($report['groups'] ?? null)
            && is_string($report['checked_at'] ?? null)
            && ($report['locale'] ?? '') === determine_locale()) {
            return $report;
        }

        return self::refresh();
    }

    public static function refresh(): array
    {
        $report = [
            'checked_at' => gmdate('c'),
            'locale' => determine_locale(),
            'groups' => self::checks(),
        ];
        update_option(self::OPTION_KEY, $report, false);
        return $report;
    }

    public static function checks($site_response = null, ?array $database_grants = null, ?array $active_plugins = null): array
    {
        if ($site_response === null) {
            $site_response = wp_remote_get(home_url('/'), [
                'timeout' => 10,
                'redirection' => 3,
                'user-agent' => 'RagnusStaticPublisher/' . RAGSTAT_VERSION,
            ]);
        }

        $response_code = is_wp_error($site_response) ? 0 : (int) wp_remote_retrieve_response_code($site_response);
        $authenticate_header = is_wp_error($site_response)
            ? ''
            : (string) wp_remote_retrieve_header($site_response, 'www-authenticate');
        $request_authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $basic_auth_enabled = isset($_SERVER['PHP_AUTH_USER'])
            || stripos($request_authorization, 'Basic ') === 0
            || ($response_code === 401 && stripos($authenticate_header, 'Basic') !== false);
        $site_reachable = $response_code >= 200 && $response_code < 400;
        $php_supported = version_compare(PHP_VERSION, '8.1', '>=');
        $xml_available = extension_loaded('libxml') && class_exists('DOMDocument');
        $curl_available = extension_loaded('curl') && function_exists('curl_init');
        $permalinks_enabled = (string) get_option('permalink_structure', '') !== '';
        $indexable = (string) get_option('blog_public', '1') === '1';
        $cache_disabled = ! (defined('WP_CACHE') && WP_CACHE);
        $cron_available = ! (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
        $temporary_directory = trailingslashit(Plugin::storage_directory()) . 'temp-files';
        if (! is_dir($temporary_directory)) {
            wp_mkdir_p($temporary_directory);
        }
        $temporary_directory = trailingslashit($temporary_directory);
        $temporary_readable = is_dir($temporary_directory) && is_readable($temporary_directory);
        $temporary_writable = is_dir($temporary_directory) && is_writable($temporary_directory);
        $active_plugins = $active_plugins ?? self::active_plugins();
        $incompatible_plugins = self::active_incompatible_plugins($active_plugins);
        $database_grants = $database_grants ?? self::database_grants();
        $database_privileges = self::database_privileges($database_grants);

        $site_message = $site_reachable
            ? sprintf(__('Site URL erişilebilir (HTTP %d): %s', 'ragnus-static-publisher'), $response_code, home_url('/'))
            : (is_wp_error($site_response)
                ? __('Site URL erişilemiyor: ', 'ragnus-static-publisher') . $site_response->get_error_message()
                : sprintf(__('Site URL beklenmeyen bir HTTP %d yanıtı verdi: %s', 'ragnus-static-publisher'), $response_code, home_url('/')));

        $groups = [
            __('Server', 'ragnus-static-publisher') => [
                [
                    'id' => 'php-version',
                    'label' => __('PHP Sürümü', 'ragnus-static-publisher'),
                    'passed' => $php_supported,
                    'message' => $php_supported
                        ? sprintf(__('PHP 8.1 veya üzeri kullanılabilir. Mevcut sürüm: %s.', 'ragnus-static-publisher'), PHP_VERSION)
                        : sprintf(__('PHP 8.1 veya üzeri gerekli. Mevcut sürüm: %s.', 'ragnus-static-publisher'), PHP_VERSION),
                ],
                [
                    'id' => 'basic-auth',
                    'label' => __('Basic Auth', 'ragnus-static-publisher'),
                    'passed' => ! $basic_auth_enabled,
                    'message' => $basic_auth_enabled
                        ? __('Basic Auth etkin görünüyor; statik tarama kimlik doğrulamasında durabilir.', 'ragnus-static-publisher')
                        : __('Basic Auth etkin değil.', 'ragnus-static-publisher'),
                ],
                [
                    'id' => 'php-xml',
                    'label' => __('PHP-XML', 'ragnus-static-publisher'),
                    'passed' => $xml_available,
                    'message' => $xml_available ? __('php-xml kullanılabilir.', 'ragnus-static-publisher') : __('php-xml kullanılamıyor; DOMDocument eklentisini etkinleştirin.', 'ragnus-static-publisher'),
                ],
                [
                    'id' => 'curl',
                    'label' => __('cURL', 'ragnus-static-publisher'),
                    'passed' => $curl_available,
                    'message' => $curl_available ? __('cURL kullanılabilir.', 'ragnus-static-publisher') : __('cURL kullanılamıyor; PHP cURL eklentisini etkinleştirin.', 'ragnus-static-publisher'),
                ],
                [
                    'id' => 'docker-site-url',
                    'label' => __('Docker / Site URL', 'ragnus-static-publisher'),
                    'passed' => $site_reachable,
                    'message' => $site_message,
                ],
            ],
            __('WordPress', 'ragnus-static-publisher') => [
                [
                    'id' => 'permalinks',
                    'label' => __('Kalıcı Bağlantılar', 'ragnus-static-publisher'),
                    'passed' => $permalinks_enabled,
                    'message' => $permalinks_enabled
                        ? __('WordPress kalıcı bağlantı yapısı ayarlanmış.', 'ragnus-static-publisher')
                        : __('Kalıcı bağlantılar düz yapıda; Ayarlar > Kalıcı Bağlantılar bölümünden bir yapı seçin.', 'ragnus-static-publisher'),
                ],
                [
                    'id' => 'indexable',
                    'label' => __('Indexlenebilirlik', 'ragnus-static-publisher'),
                    'passed' => $indexable,
                    'message' => $indexable
                        ? __('Arama motorlarının siteyi indekslemesini engelleyen ayar kapalı.', 'ragnus-static-publisher')
                        : __('Arama motorlarının siteyi indekslemesini engelleyen ayar açık.', 'ragnus-static-publisher'),
                ],
                [
                    'id' => 'caching',
                    'label' => __('Önbellek', 'ragnus-static-publisher'),
                    'passed' => $cache_disabled,
                    'message' => $cache_disabled
                        ? __('WordPress sayfa önbelleği devre dışı.', 'ragnus-static-publisher')
                        : __('WP_CACHE etkin; export sırasında eski içerik sunulmadığını doğrulayın.', 'ragnus-static-publisher'),
                ],
                [
                    'id' => 'wp-cron',
                    'label' => __('WP-CRON', 'ragnus-static-publisher'),
                    'passed' => $cron_available,
                    'message' => $cron_available
                        ? __('WordPress cron kullanılabilir.', 'ragnus-static-publisher')
                        : __('WordPress cron devre dışı; zamanlanmış export işleri çalışmayabilir.', 'ragnus-static-publisher'),
                ],
            ],
            __('Eklentiler', 'ragnus-static-publisher') => [
                [
                    'id' => 'incompatible-plugins',
                    'label' => __('Uyumsuz Eklentiler', 'ragnus-static-publisher'),
                    'passed' => $incompatible_plugins === [],
                    'message' => $incompatible_plugins === []
                        ? __('Etkin uyumsuz eklenti bulunamadı.', 'ragnus-static-publisher')
                        : __('Statik taramayı engelleyebilecek etkin eklentiler: ', 'ragnus-static-publisher') . implode(', ', $incompatible_plugins) . '.',
                ],
            ],
            __('Dosya Sistemi', 'ragnus-static-publisher') => [
                [
                    'id' => 'temp-directory-readable',
                    'label' => __('Geçici Dizin Okunabilir', 'ragnus-static-publisher'),
                    'passed' => $temporary_readable,
                    'message' => $temporary_readable
                        ? sprintf(__('Web sunucusu geçici dizini okuyabiliyor: %s', 'ragnus-static-publisher'), $temporary_directory)
                        : sprintf(__('Web sunucusu geçici dizini okuyamıyor: %s', 'ragnus-static-publisher'), $temporary_directory),
                ],
                [
                    'id' => 'temp-directory-writable',
                    'label' => __('Geçici Dizin Yazılabilir', 'ragnus-static-publisher'),
                    'passed' => $temporary_writable,
                    'message' => $temporary_writable
                        ? sprintf(__('Web sunucusu geçici dizine yazabiliyor: %s', 'ragnus-static-publisher'), $temporary_directory)
                        : sprintf(__('Web sunucusu geçici dizine yazamıyor: %s', 'ragnus-static-publisher'), $temporary_directory),
                ],
            ],
        ];

        $groups['MySQL'] = [];
        foreach (['DELETE', 'INSERT', 'SELECT', 'CREATE', 'ALTER', 'DROP'] as $privilege) {
            $has_privilege = in_array($privilege, $database_privileges, true);
            $groups['MySQL'][] = [
                'id' => 'mysql-' . strtolower($privilege),
                'label' => $privilege,
                'passed' => $has_privilege,
                'message' => $has_privilege
                    ? sprintf(__('MySQL kullanıcısı %s yetkisine sahip.', 'ragnus-static-publisher'), $privilege)
                    : sprintf(__('MySQL kullanıcısında %s yetkisi bulunamadı.', 'ragnus-static-publisher'), $privilege),
            ];
        }

        return $groups;
    }

    private static function active_plugins(): array
    {
        $active_plugins = get_option('active_plugins', []);
        $active_plugins = is_array($active_plugins) ? $active_plugins : [];
        if (is_multisite()) {
            $network_plugins = get_site_option('active_sitewide_plugins', []);
            if (is_array($network_plugins)) {
                $active_plugins = array_merge($active_plugins, array_keys($network_plugins));
            }
        }
        return array_values(array_unique(array_map('strval', $active_plugins)));
    }

    private static function active_incompatible_plugins(array $active_plugins): array
    {
        $known_plugins = apply_filters('ragnus_static_incompatible_plugins', [
            'password-protected/password-protected.php' => 'Password Protected',
            'wp-maintenance-mode/wp-maintenance-mode.php' => 'LightStart / WP Maintenance Mode',
            'coming-soon/coming-soon.php' => 'SeedProd Coming Soon',
            'under-construction-page/under-construction.php' => 'Under Construction',
        ]);
        if (! is_array($known_plugins)) {
            return [];
        }

        $active_incompatible = [];
        foreach ($known_plugins as $plugin_file => $plugin_name) {
            if (in_array((string) $plugin_file, $active_plugins, true)) {
                $active_incompatible[] = sanitize_text_field((string) $plugin_name);
            }
        }
        return $active_incompatible;
    }

    private static function database_grants(): array
    {
        global $wpdb;
        $grants = $wpdb->get_col('SHOW GRANTS FOR CURRENT_USER()');
        return is_array($grants) ? array_map('strval', $grants) : [];
    }

    private static function database_privileges(array $grants): array
    {
        $privileges = [];
        foreach ($grants as $grant) {
            if (! preg_match('/GRANT\s+(.+?)\s+ON\s+/i', (string) $grant, $matches)) {
                continue;
            }
            foreach (explode(',', strtoupper((string) $matches[1])) as $privilege) {
                $privilege = trim($privilege);
                if ($privilege === 'ALL PRIVILEGES') {
                    return ['DELETE', 'INSERT', 'SELECT', 'CREATE', 'ALTER', 'DROP'];
                }
                $privileges[] = $privilege;
            }
        }
        return array_values(array_unique($privileges));
    }
}
