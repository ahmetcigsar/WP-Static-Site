<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;


final class Language_Routing
{
    private array $settings;

    public function __construct(?array $settings = null)
    {
        $this->settings = wp_parse_args($settings ?? Plugin::language_settings(), self::defaults());
    }

    public static function defaults(): array
    {
        $site_language = self::site_language();
        $supported_languages = array_values(array_unique(['en', $site_language, 'tr']));

        return [
            'enabled' => '0',
            'supported_languages' => implode("\n", $supported_languages),
            'default_language' => 'en',
            'cookie_days' => 365,
        ];
    }

    public static function site_language(): string
    {
        $locale = (string) get_option('WPLANG', '');
        if ($locale === '') {
            $locale = 'en_US';
        }

        $language = self::normalise_language($locale);
        if ($language === '') {
            return 'en';
        }

        return explode('-', $language, 2)[0];
    }

    public static function sanitize(array $value): array
    {
        $languages = self::parse_languages((string) ($value['supported_languages'] ?? ''));
        $default_language = self::normalise_language((string) ($value['default_language'] ?? ''));
        if ($default_language === '' || ! in_array($default_language, $languages, true)) {
            $default_language = in_array('en', $languages, true) ? 'en' : ($languages[0] ?? 'en');
        }

        return [
            'enabled' => isset($value['enabled']) && count($languages) >= 2 ? '1' : '0',
            'supported_languages' => implode("\n", $languages),
            'default_language' => $default_language,
            'cookie_days' => max(1, min(3650, absint($value['cookie_days'] ?? 365))),
        ];
    }

    public static function parse_languages(string $value): array
    {
        $languages = preg_split('/[\s,;]+/', strtolower($value)) ?: [];
        $languages = array_map([self::class, 'normalise_language'], $languages);
        return array_values(array_unique(array_filter($languages)));
    }

    public static function normalise_language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language) === 1 ? $language : '';
    }

    public function enabled(): bool
    {
        return (string) $this->settings['enabled'] === '1' && count($this->languages()) >= 2;
    }

    public function languages(): array
    {
        return self::parse_languages((string) $this->settings['supported_languages']);
    }

    public function seed_urls(string $origin): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_map(
            static fn (string $language): string => untrailingslashit($origin) . '/' . rawurlencode($language) . '/',
            $this->languages()
        );
    }

    public function config_json(): string
    {
        return (string) wp_json_encode([
            'schema_version' => 1,
            'enabled' => $this->enabled(),
            'supported_languages' => $this->languages(),
            'default_language' => (string) $this->settings['default_language'],
            'cookie_name' => 'wext_language',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public function preference_script(): string
    {
        $languages = (string) wp_json_encode($this->languages());
        $cookie_days = max(1, min(3650, (int) $this->settings['cookie_days']));
        return "(() => {\n"
            . "  const languages = {$languages};\n"
            . "  const language = location.pathname.split('/').filter(Boolean)[0]?.toLowerCase();\n"
            . "  if (!language || !languages.includes(language)) return;\n"
            . "  const secure = location.protocol === 'https:' ? '; Secure' : '';\n"
            . "  document.cookie = 'wext_language=' + encodeURIComponent(language) + '; Path=/; Max-Age=" . ($cookie_days * DAY_IN_SECONDS) . "; SameSite=Lax' + secure;\n"
            . "})();\n";
    }

    public function inject_preference_script(string $html): string
    {
        if (! $this->enabled() || str_contains($html, '/wext-language-preference.js')) {
            return $html;
        }

        $script = Export_Assets::scripts('wextstat-language-preference', '/wext-language-preference.js');
        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $script . '</body>', $html, 1) ?? $html;
        }
        return $html . $script;
    }

    public function inject_x_default(string $html, string $target): string
    {
        if (! $this->enabled() || preg_match('/hreflang\s*=\s*["\']x-default["\']/i', $html) === 1) {
            return $html;
        }

        $link = '<link rel="alternate" hreflang="x-default" href="' . esc_url(untrailingslashit($target) . '/') . '">';
        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $link . '</head>', $html, 1) ?? $html;
        }
        return $link . $html;
    }
}
