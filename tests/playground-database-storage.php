<?php
/** Regression coverage for review fixes. Filesystem writes below are test fixtures only. */
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once __DIR__ . '/database-test-helpers.php';
use Wext\StaticPublisher\Export_Storage as Storage;
use Wext\StaticPublisher\Export_Zip;
use Wext\StaticPublisher\Plugin;
use Wext\StaticPublisher\REST_Controller;
use Wext\StaticPublisher\Cloudflare_Deployer;
function db_assert($condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
$before_display = ini_get('display_errors');
$before_reporting = error_reporting();
$before_db_errors = $wpdb->show_errors;
activate_plugin('wext-static-publisher/wext-static-publisher.php');
do_action('init');
db_assert(defined('WP_DEBUG') && WP_DEBUG, 'WP_DEBUG must be enabled');
db_assert($before_display === ini_get('display_errors') && $before_reporting === error_reporting() && $before_db_errors === $wpdb->show_errors, 'Plugin changed error reporting');
$uploads = wp_upload_dir(null, false);
foreach (['wext-static-publisher', 'wext-static'] as $folder) {
    db_assert(! is_dir($uploads['basedir'] . '/' . $folder), 'Plugin created export files on disk');
}
$base = Plugin::storage_directory() . '/builds/database-test';
$binary = str_repeat("\x00\xff\x80'\"\\\n", 120000);
Storage::write($base, 'assets/binary.png', $binary);
Storage::write($base, 'empty.txt', '');
Storage::write($base, 'index.html', '<h1>Database export</h1>');
Storage::write($base, 'türkçe/örnek.html', '<h1>Türkçe</h1>');
Storage::write($base, '_headers', "/*\n X-Test: yes\n");
Storage::write($base, '_redirects', "/old /new 301\n");
$manifest = ['job_id' => 'database-test', 'build_sha256' => str_repeat('a', 64), 'target' => 'https://static.example.com'];
Storage::write($base, 'wext-static-manifest.json', wp_json_encode($manifest));
db_assert(Storage::read($base . '/assets/binary.png') === $binary, 'Binary chunk round trip failed');
db_assert(Storage::metadata($base . '/assets/binary.png')['parts'] > 1, 'Fixture did not cross chunk boundaries');
$reader = Storage::reader($base . '/assets/binary.png');
$received = '';
while (($piece = $reader(32713)) !== null) {
    db_assert(strlen($piece) <= 32713, 'SFTP reader exceeded requested size');
    $received .= $piece;
}
db_assert($received === $binary, 'SFTP callback truncated binary data');
db_assert(Storage::reader($base . '/empty.txt')(100) === null, 'Empty SFTP source did not terminate');
$pieces = static function (): Generator { yield str_repeat('x', 300000); throw new RuntimeException('fixture interruption'); };
try { Storage::store($base . '/interrupted.txt', $pieces()); throw new LogicException('Failed write was accepted'); } catch (RuntimeException $error) { db_assert($error->getMessage() === 'fixture interruption', 'Unexpected write error'); }
db_assert(! Storage::exists($base . '/interrupted.txt'), 'Partial write was published');
db_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE object_key = %s', $wpdb->prefix . 'wext_static_objects', hash('sha256', $base . '/interrupted.txt'))) === 0, 'Partial chunks were retained');
Storage::write('wext-db/builds/Case', 'index.html', 'upper');
Storage::write('wext-db/builds/case', 'index.html', 'lower');
Storage::delete_directory('wext-db/builds/Case');
db_assert(Storage::read('wext-db/builds/case/index.html') === 'lower', 'Case-sensitive build deletion removed another build');
foreach (['https://example.com/x.js', '/tmp/x.js', 'wext-db/../x.js'] as $bad) {
    try { Storage::store($bad, ['bad']); throw new LogicException('Invalid storage identifier accepted'); } catch (RuntimeException $error) {}
}
$seo_class = new ReflectionClass(Wext\StaticPublisher\SEO_Toolkit::class);
$seo = $seo_class->newInstanceWithoutConstructor();
$lookup = $seo_class->getMethod('public_url_exists');
$lookup->setAccessible(true);
foreach (['https://static.example.com/../outside.html', 'https://static.example.com/%25bad.html', 'https://static.example.com/evil.php'] as $link) {
    db_assert($lookup->invoke($seo, $link, $base) === false, 'Unsafe SEO link was accepted');
}
$objects = [];
foreach (Storage::files($base) as $name => $meta) { $objects[$name] = $base . '/' . $name; }
$archive = 'wext-db/archives/database-test.zip';
Storage::store($archive, Export_Zip::pieces($objects));
$zip = new ZipArchive();
db_assert($zip->open(wext_test_zip_path($archive), ZipArchive::CHECKCONS) === true, 'ZIP failed consistency check');
db_assert($zip->numFiles === count($objects), 'ZIP entry count differs');
db_assert($zip->getFromName('assets/binary.png') === $binary && $zip->getFromName('empty.txt') === '', 'ZIP binary/empty content corrupted');
db_assert($zip->getFromName('türkçe/örnek.html') === '<h1>Türkçe</h1>', 'UTF-8 ZIP names corrupted');
$zip->close();
wp_set_current_user(0);
$request = new WP_REST_Request('GET', '/wext-static/v1/exports/database-test/artifact');
db_assert(rest_do_request($request)->get_status() === 401, 'Anonymous archive access allowed');
wp_set_current_user(1);
$response = rest_do_request($request);
db_assert($response instanceof Wext\StaticPublisher\File_Response, 'Admin archive response unavailable');
ob_start();
REST_Controller::serve_file(false, $response);
$download = ob_get_clean();
db_assert(hash('sha256', $download) === Storage::metadata($archive)['sha256'], 'REST download bytes differ from archive');
$request = new WP_REST_Request('GET', '/wext-static/v1/exports/missing-job/artifact');
db_assert(rest_do_request($request)->get_status() === 404, 'Missing archive did not return 404');
// Exercise the real Cloudflare upload method through mocked WordPress HTTP responses.
$requests = [];
$hashes = [];
$http = static function ($pre, $args, $url) use (&$requests, &$hashes, $binary): array {
    db_assert(str_starts_with($url, 'https://api.cloudflare.com/client/v4/'), 'Unexpected HTTP destination');
    $requests[] = $url;
    $result = [];
    if (str_ends_with($url, '/assets-upload-session')) {
        $manifest = json_decode($args['body'], true)['manifest'];
        db_assert(! isset($manifest['/_headers']) && ! isset($manifest['/_redirects']), 'Config leaked into assets');
        db_assert($manifest['/assets/binary.png']['size'] === strlen($binary), 'Cloudflare asset length incorrect');
        $hashes = array_column($manifest, 'hash');
        $result = ['jwt' => 'fixture-upload-token', 'buckets' => [$hashes]];
    } elseif (str_contains($url, '/workers/assets/upload?')) {
        db_assert(str_contains($args['body'], base64_encode($binary)), 'Cloudflare binary upload corrupted');
        $result = ['jwt' => 'fixture-completion-token'];
    } elseif ($args['method'] === 'PUT') {
        db_assert(str_contains($args['body'], 'fixture-completion-token') && str_contains($args['body'], '_headers') && str_contains($args['body'], 'worker.mjs'), 'Cloudflare deployment metadata missing');
    } else {
        throw new RuntimeException('Unexpected Cloudflare request');
    }
    return ['headers' => [], 'body' => wp_json_encode(['success' => true, 'result' => $result]), 'response' => ['code' => 200, 'message' => 'OK']];
};
add_filter('pre_http_request', $http, 10, 3);
$upload = new ReflectionMethod(Cloudflare_Deployer::class, 'upload');
$upload->setAccessible(true);
$upload->invoke(null, 'database-test', ['api_token' => Wext\StaticPublisher\Secret_Store::encrypt('fixture-token'), 'account_id' => str_repeat('a', 32), 'worker_name' => 'fixture']);
remove_filter('pre_http_request', $http, 10);
db_assert(count($requests) === 3, 'Cloudflare session/upload/deploy sequence incomplete');
foreach (['wext-static-publisher', 'wext-static'] as $folder) {
    db_assert(! is_dir($uploads['basedir'] . '/' . $folder), 'Export/download/deploy wrote artifacts to uploads');
}
define('WP_UNINSTALL_PLUGIN', 'wext-static-publisher/wext-static-publisher.php');
require WEXTSTAT_DIR . 'uninstall.php';
db_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'wext_static_objects'))) === null, 'Uninstall retained export table');
db_assert(get_option('wext_static_storage_schema', false) === false, 'Uninstall retained storage schema marker');
db_assert(get_option('siteurl') !== false, 'Uninstall affected unrelated WordPress data');
echo "Database storage, binary ZIP, failure cleanup, REST authorization, Cloudflare HTTP integration, and uninstall passed.\n";
