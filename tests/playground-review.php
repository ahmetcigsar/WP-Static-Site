<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin('wext-static-publisher/wext-static-publisher.php');
use Wext\StaticPublisher\Export_Assets;
use Wext\StaticPublisher\Export_Storage;
use Wext\StaticPublisher\Path_Mapper;
function review_assert($condition, $message) { if (! $condition) { throw new RuntimeException($message); } }
$base = Wext\StaticPublisher\Plugin::storage_directory() . '/review-test';
wp_mkdir_p($base);
foreach (['../escape.js', '/absolute.js', 'a\\b.js', '%2e%2e/a.js', 'a/../../b.js', 'x.php', 'x.php.js', 'x.PHTML', 'x.cgi', '.htaccess', 'web.config', 'a//b.js', 'x.js/../a.js'] as $path) {
    review_assert(! Path_Mapper::is_safe_static_path($path), 'Accepted unsafe path: ' . $path);
    try { Export_Storage::write($base, $path, 'unsafe'); throw new LogicException('Writer accepted: ' . $path); } catch (RuntimeException $e) {}
}
foreach (['index.html', 'assets/app.js', 'assets/app.mjs', 'style.css', 'sitemap.xml', '.well-known/test.txt', '_headers', '_redirects'] as $path) {
    Export_Storage::write($base, $path, 'fixture');
    review_assert(file_get_contents($base . '/' . $path) === 'fixture', 'Failed valid path: ' . $path);
}
$outside = dirname($base) . '/outside-review';
wp_mkdir_p($outside);
file_put_contents($outside . '/sentinel.txt', 'unchanged');
review_assert(symlink($outside, $base . '/linked'), 'Cannot create symlink fixture');
try { Export_Storage::write($base, 'linked/sentinel.txt', 'changed'); throw new LogicException('Symlink allowed'); } catch (RuntimeException $e) {}
review_assert(file_get_contents($outside . '/sentinel.txt') === 'unchanged', 'Wrote outside storage');
wp_enqueue_script('live-review', '/live.js');
wp_enqueue_style('live-review', '/live.css');
$scripts = wp_scripts(); $styles = wp_styles();
$script_queue = $scripts->queue; $style_queue = $styles->queue;
$html = Export_Assets::scripts('export-review', '/export.js', true);
review_assert(str_contains($html, '/export.js') && str_contains($html, 'type="module"') && str_contains($html, 'defer') && ! str_contains($html, '/live.js'), 'Export script rendering failed');
$css = Export_Assets::styles('export-review', '/export.css');
review_assert(str_contains($css, '/export.css') && ! str_contains($css, '/live.css'), 'Export style rendering failed');
review_assert(wp_scripts() === $scripts && wp_styles() === $styles && $scripts->queue === $script_queue && $styles->queue === $style_queue, 'Live queues changed');
echo "Review storage and asset tests passed.\n";
