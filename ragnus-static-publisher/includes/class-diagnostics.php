<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

final class Diagnostics
{
    public static function checks($site_response = null): array
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

        $site_message = $site_reachable
            ? sprintf('Site URL erişilebilir (HTTP %d): %s', $response_code, home_url('/'))
            : (is_wp_error($site_response)
                ? 'Site URL erişilemiyor: ' . $site_response->get_error_message()
                : sprintf('Site URL beklenmeyen bir HTTP %d yanıtı verdi: %s', $response_code, home_url('/')));

        return [
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
                    'label' => 'php-xml',
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
        ];
    }
}
