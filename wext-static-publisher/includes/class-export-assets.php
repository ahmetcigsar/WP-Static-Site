<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

/** Render asset queues for standalone exported documents without changing the live page's queues. */
final class Export_Assets
{
    public static function scripts(string $handle, string $path, bool $module = false): string
    {
        $previous = $GLOBALS['wp_scripts'] ?? null;
        $GLOBALS['wp_scripts'] = new \WP_Scripts();
        $GLOBALS['wp_scripts']->base_url = '';
        $module_tag = static function (string $tag, string $current_handle) use ($handle, $module): string {
            if ($current_handle === $handle && $module) {
                $processor = new \WP_HTML_Tag_Processor($tag);
                if ($processor->next_tag('SCRIPT')) {
                    $processor->set_attribute('type', 'module');
                    return $processor->get_updated_html();
                }
            }
            return $tag;
        };
        add_filter('script_loader_tag', $module_tag, 10, 2);
        ob_start();
        try {
            wp_enqueue_script($handle, $path, [], WEXTSTAT_VERSION, ['strategy' => 'defer']);
            wp_scripts()->do_items([$handle]);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            remove_filter('script_loader_tag', $module_tag, 10);
            $GLOBALS['wp_scripts'] = $previous;
        }
    }

    public static function styles(string $handle, string $path): string
    {
        $previous = $GLOBALS['wp_styles'] ?? null;
        $GLOBALS['wp_styles'] = new \WP_Styles();
        $GLOBALS['wp_styles']->base_url = '';
        ob_start();
        try {
            wp_enqueue_style($handle, $path, [], WEXTSTAT_VERSION);
            wp_styles()->do_items([$handle]);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $GLOBALS['wp_styles'] = $previous;
        }
    }
}
