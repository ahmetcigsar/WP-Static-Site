<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

final class Diagnostics
{
    public const OPTION_KEY = 'ragnus_static_diagnostics';

    public static function report(): array
    {
        $report = get_option(self::OPTION_KEY, []);
        if (is_array($report) && is_array($report['groups'] ?? null) && is_string($report['checked_at'] ?? null)) {
            return $report;
        }

        return self::refresh();
    }

    public static function refresh(): array
    {
        $report = [
            'checked_at' => gmdate('c'),
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
            ? sprintf('Site URL erişilebilir (HTTP %d): %s', $response_code, home_url('/'))
            : (is_wp_error($site_response)
                ? 'Site URL erişilemiyor: ' . $site_response->get_error_message()
                : sprintf('Site URL beklenmeyen bir HTTP %d yanıtı verdi: %s', $response_code, home_url('/')));

        $groups = [
            'Server' => [
                [
                    'id' => 'php-version',
                    'label' => 'PHP Sürümü',
                    'passed' => $php_supported,
                    'message' => $php_supported
                        ? sprintf('PHP 8.1 veya üzeri kullanılabilir. Mevcut sürüm: %s.', PHP_VERSION)
                        : sprintf('PHP 8.1 veya üzeri gerekli. Mevcut sürüm: %s.', PHP_VERSION),
                ],
                [
                    'id' => 'basic-auth',
                    'label' => 'Basic Auth',
                    'passed' => ! $basic_auth_enabled,
                    'message' => $basic_auth_enabled
                        ? 'Basic Auth etkin görünüyor; statik tarama kimlik doğrulamasında durabilir.'
                        : 'Basic Auth etkin değil.',
                ],
                [
                    'id' => 'php-xml',
                    'label' => 'PHP-XML',
                    'passed' => $xml_available,
                    'message' => $xml_available ? 'php-xml kullanılabilir.' : 'php-xml kullanılamıyor; DOMDocument eklentisini etkinleştirin.',
                ],
                [
                    'id' => 'curl',
                    'label' => 'cURL',
                    'passed' => $curl_available,
                    'message' => $curl_available ? 'cURL kullanılabilir.' : 'cURL kullanılamıyor; PHP cURL eklentisini etkinleştirin.',
                ],
                [
                    'id' => 'docker-site-url',
                    'label' => 'Docker / Site URL',
                    'passed' => $site_reachable,
                    'message' => $site_message,
                ],
            ],
            'WordPress' => [
                [
                    'id' => 'permalinks',
                    'label' => 'Kalıcı Bağlantılar',
                    'passed' => $permalinks_enabled,
                    'message' => $permalinks_enabled
                        ? 'WordPress kalıcı bağlantı yapısı ayarlanmış.'
                        : 'Kalıcı bağlantılar düz yapıda; Ayarlar > Kalıcı Bağlantılar bölümünden bir yapı seçin.',
                ],
                [
                    'id' => 'indexable',
                    'label' => 'Indexlenebilirlik',
                    'passed' => $indexable,
                    'message' => $indexable
                        ? 'Arama motorlarının siteyi indekslemesini engelleyen ayar kapalı.'
                        : 'Arama motorlarının siteyi indekslemesini engelleyen ayar açık.',
                ],
                [
                    'id' => 'caching',
                    'label' => 'Önbellek',
                    'passed' => $cache_disabled,
                    'message' => $cache_disabled
                        ? 'WordPress sayfa önbelleği devre dışı.'
                        : 'WP_CACHE etkin; export sırasında eski içerik sunulmadığını doğrulayın.',
                ],
                [
                    'id' => 'wp-cron',
                    'label' => 'WP-CRON',
                    'passed' => $cron_available,
                    'message' => $cron_available
                        ? 'WordPress cron kullanılabilir.'
                        : 'WordPress cron devre dışı; zamanlanmış export işleri çalışmayabilir.',
                ],
            ],
            'Eklentiler' => [
                [
                    'id' => 'incompatible-plugins',
                    'label' => 'Uyumsuz Eklentiler',
                    'passed' => $incompatible_plugins === [],
                    'message' => $incompatible_plugins === []
                        ? 'Etkin uyumsuz eklenti bulunamadı.'
                        : 'Statik taramayı engelleyebilecek etkin eklentiler: ' . implode(', ', $incompatible_plugins) . '.',
                ],
            ],
            'Dosya Sistemi' => [
                [
                    'id' => 'temp-directory-readable',
                    'label' => 'Geçici Dizin Okunabilir',
                    'passed' => $temporary_readable,
                    'message' => $temporary_readable
                        ? sprintf('Web sunucusu geçici dizini okuyabiliyor: %s', $temporary_directory)
                        : sprintf('Web sunucusu geçici dizini okuyamıyor: %s', $temporary_directory),
                ],
                [
                    'id' => 'temp-directory-writable',
                    'label' => 'Geçici Dizin Yazılabilir',
                    'passed' => $temporary_writable,
                    'message' => $temporary_writable
                        ? sprintf('Web sunucusu geçici dizine yazabiliyor: %s', $temporary_directory)
                        : sprintf('Web sunucusu geçici dizine yazamıyor: %s', $temporary_directory),
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
                    ? sprintf('MySQL kullanıcısı %s yetkisine sahip.', $privilege)
                    : sprintf('MySQL kullanıcısında %s yetkisi bulunamadı.', $privilege),
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
