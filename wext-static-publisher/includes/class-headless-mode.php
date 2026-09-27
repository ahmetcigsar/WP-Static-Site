<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

final class Headless_Mode
{
    private const HEADER_MARKER = 'X-Wext-Static-Export';
    private const HEADER_TIMESTAMP = 'X-Wext-Static-Timestamp';
    private const HEADER_SIGNATURE = 'X-Wext-Static-Signature';
    private const SIGNATURE_TTL = 300;

    public static function boot(): void
    {
        add_filter('http_request_args', [self::class, 'sign_export_request'], 20, 2);
        add_action('template_redirect', [self::class, 'protect_frontend'], -100);
        add_filter('robots_txt', [self::class, 'protect_robots'], 100, 2);
        add_filter('wp_sitemaps_enabled', [self::class, 'sitemaps_enabled']);
        add_filter('xmlrpc_enabled', [self::class, 'xmlrpc_enabled']);
        add_filter('comments_open', [self::class, 'comments_open'], 100, 2);
        add_filter('pings_open', [self::class, 'comments_open'], 100, 2);
        add_action('wp_loaded', [self::class, 'protect_xmlrpc'], -100);
    }

    public static function enabled(): bool
    {
        return (string) (Plugin::settings()['headless_enabled'] ?? '0') === '1';
    }

    public static function sign_export_request(array $args, string $url): array
    {
        $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];
        if (! self::has_export_marker($headers) || ! self::is_same_origin($url)) {
            return $args;
        }

        $timestamp = (string) time();
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $headers[self::HEADER_TIMESTAMP] = $timestamp;
        $headers[self::HEADER_SIGNATURE] = self::signature($method, self::request_target($url), $timestamp);
        $args['headers'] = $headers;
        return $args;
    }

    public static function valid_export_request(): bool
    {
        if ((string) ($_SERVER['HTTP_X_WEXT_STATIC_EXPORT'] ?? '') !== '1') {
            return false;
        }

        $timestamp = (string) ($_SERVER['HTTP_X_WEXT_STATIC_TIMESTAMP'] ?? '');
        $signature = strtolower((string) ($_SERVER['HTTP_X_WEXT_STATIC_SIGNATURE'] ?? ''));
        if (! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::SIGNATURE_TTL
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return false;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $target = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return hash_equals(self::signature($method, $target, $timestamp), $signature);
    }

    public static function protect_frontend(): void
    {
        if (! self::should_protect_frontend()) {
            return;
        }

        $settings = Plugin::settings();
        $behavior = (string) ($settings['headless_frontend_behavior'] ?? '404');
        if ($behavior === 'redirect') {
            $destination = self::frontend_destination($settings);
            if ($destination !== '') {
                if ((string) ($settings['headless_noindex'] ?? '1') === '1') {
                    header('X-Robots-Tag: noindex, nofollow', true);
                }
                wp_redirect($destination, 307, 'WP Static Publisher');
                exit;
            }
            $behavior = '404';
        }

        $status = $behavior === '410' ? 410 : 404;
        status_header($status);
        nocache_headers();
        header('Content-Type: text/plain; charset=' . get_option('blog_charset', 'UTF-8'));
        if ((string) ($settings['headless_noindex'] ?? '1') === '1') {
            header('X-Robots-Tag: noindex, nofollow', true);
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo $status === 410
                ? esc_html__('This WordPress frontend is no longer available.', 'wext-static-publisher')
                : esc_html__('This WordPress frontend is not available.', 'wext-static-publisher');
        }
        exit;
    }

    public static function should_protect_frontend(): bool
    {
        if (! self::enabled()
            || is_admin()
            || wp_doing_ajax()
            || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
            || self::valid_export_request()) {
            return false;
        }

        $settings = Plugin::settings();
        if ((string) ($settings['headless_allow_authenticated_preview'] ?? '1') === '1'
            && is_user_logged_in()
            && current_user_can('edit_posts')) {
            return false;
        }

        $path = '/' . ltrim((string) wp_parse_url(self::current_request_url(), PHP_URL_PATH), '/');
        if ((string) ($settings['headless_allow_graphql'] ?? '0') === '1'
            && ($path === '/graphql' || str_starts_with($path, '/graphql/'))) {
            return false;
        }

        return true;
    }

    public static function protect_robots(string $output, bool $public): string
    {
        if (! self::enabled()
            || (string) (Plugin::settings()['headless_noindex'] ?? '1') !== '1'
            || self::valid_export_request()) {
            return $output;
        }
        return "User-agent: *\nDisallow: /\n";
    }

    public static function sitemaps_enabled(bool $enabled): bool
    {
        if (! self::enabled()
            || (string) (Plugin::settings()['headless_noindex'] ?? '1') !== '1'
            || self::valid_export_request()) {
            return $enabled;
        }
        return false;
    }

    public static function xmlrpc_enabled(bool $enabled): bool
    {
        if (! self::enabled()
            || (string) (Plugin::settings()['headless_disable_xmlrpc'] ?? '1') !== '1') {
            return $enabled;
        }
        return false;
    }

    public static function protect_xmlrpc(): void
    {
        if (! self::enabled()
            || (string) (Plugin::settings()['headless_disable_xmlrpc'] ?? '1') !== '1'
            || ! defined('XMLRPC_REQUEST')
            || ! XMLRPC_REQUEST) {
            return;
        }

        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain; charset=' . get_option('blog_charset', 'UTF-8'));
        echo esc_html__('XML-RPC is disabled in Headless CMS mode.', 'wext-static-publisher');
        exit;
    }

    public static function comments_open(bool $open, int $post_id): bool
    {
        if (! self::enabled()
            || (string) (Plugin::settings()['headless_disable_comments'] ?? '1') !== '1') {
            return $open;
        }
        return false;
    }

    private static function frontend_destination(array $settings): string
    {
        $frontend = untrailingslashit((string) ($settings['target_url'] ?? ''));
        if ($frontend === '' || ! self::is_http_url($frontend) || self::same_origin($frontend, home_url('/'))) {
            return '';
        }

        if ((string) ($settings['headless_preserve_path'] ?? '1') !== '1') {
            return $frontend . '/';
        }

        $path = (string) wp_parse_url(self::current_request_url(), PHP_URL_PATH);
        return $frontend . '/' . ltrim($path, '/');
    }

    private static function current_request_url(): string
    {
        return home_url((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    }

    private static function has_export_marker(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, self::HEADER_MARKER) === 0 && (string) $value === '1') {
                return true;
            }
        }
        return false;
    }

    private static function is_same_origin(string $url): bool
    {
        return self::same_origin($url, home_url('/'));
    }

    private static function same_origin(string $first, string $second): bool
    {
        $first_parts = wp_parse_url($first);
        $second_parts = wp_parse_url($second);
        if (! is_array($first_parts) || ! is_array($second_parts)) {
            return false;
        }

        $first_scheme = strtolower((string) ($first_parts['scheme'] ?? ''));
        $second_scheme = strtolower((string) ($second_parts['scheme'] ?? ''));
        $first_host = strtolower((string) ($first_parts['host'] ?? ''));
        $second_host = strtolower((string) ($second_parts['host'] ?? ''));
        $first_port = (int) ($first_parts['port'] ?? ($first_scheme === 'https' ? 443 : 80));
        $second_port = (int) ($second_parts['port'] ?? ($second_scheme === 'https' ? 443 : 80));
        return $first_scheme !== ''
            && $first_scheme === $second_scheme
            && $first_host !== ''
            && $first_host === $second_host
            && $first_port === $second_port;
    }

    private static function is_http_url(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && (string) ($parts['host'] ?? '') !== '';
    }

    private static function request_target(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        return ($path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '');
    }

    private static function signature(string $method, string $target, string $timestamp): string
    {
        $key = hash('sha256', wp_salt('auth') . '|wext-static-headless-export', true);
        return hash_hmac('sha256', $method . "\n" . $target . "\n" . $timestamp, $key);
    }
}
