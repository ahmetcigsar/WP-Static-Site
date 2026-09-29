<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once __DIR__ . '/database-test-helpers.php';
use Wext\StaticPublisher\Export_Storage;
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'wext-static-publisher/wext-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

do_action('init');

$base = Wext\StaticPublisher\Plugin::storage_directory();


$fixtures = [
    ['job_id' => 'retention-test-1', 'finished_at' => '2026-01-01T10:00:00Z', 'url_count' => 10, 'build_sha256' => str_repeat('1', 64), 'target' => 'https://static.example.com'],
    ['job_id' => 'retention-test-2', 'finished_at' => '2026-01-02T10:00:00Z', 'url_count' => 20, 'build_sha256' => str_repeat('2', 64), 'target' => 'https://static.example.com'],
    ['job_id' => 'retention-test-3', 'finished_at' => '2026-01-03T10:00:00Z', 'url_count' => 30, 'build_sha256' => str_repeat('3', 64), 'target' => 'https://static.example.com'],
];

foreach ($fixtures as $fixture) {
    $build = $base . '/builds/' . $fixture['job_id'];
    Export_Storage::write($build, 'index.html', $fixture['job_id']);
    Export_Storage::write($build, 'wext-static-manifest.json', (string) wp_json_encode($fixture));
    $objects = [];
    foreach (Export_Storage::files($build) as $name => $metadata) {
        $objects[$name] = $build . '/' . $name;
    }
    Export_Storage::store($base . '/archives/' . $fixture['job_id'] . '.zip', Wext\StaticPublisher\Export_Zip::pieces($objects));
}

$archives = Wext\StaticPublisher\Archive_Manager::archives();
if (array_column($archives, 'job_id') !== ['retention-test-3', 'retention-test-2', 'retention-test-1']) {
    throw new RuntimeException('ZIP dosyaları oluşturma zamanına göre sıralanmadı.');
}
if (($archives[0]['url_count'] ?? null) !== 30) {
    throw new RuntimeException('URL sayısı manifestten okunamadı.');
}
if ((Wext\StaticPublisher\Archive_Manager::find('retention-test-2')['url_count'] ?? null) !== 20) {
    throw new RuntimeException('Tekil ZIP dosyası iş kimliğiyle bulunamadı.');
}
if (($archives[0]['build_sha256'] ?? '') !== str_repeat('3', 64)
    || ($archives[0]['target'] ?? '') !== 'https://static.example.com') {
    throw new RuntimeException('Deploy doğrulama bilgileri manifestten okunamadı.');
}

$artifact_request = new WP_REST_Request('GET');
$artifact_request->set_param('job_id', 'retention-test-2');
$artifact_response = Wext\StaticPublisher\REST_Controller::job_artifact($artifact_request);
if (! $artifact_response instanceof Wext\StaticPublisher\File_Response
    || $artifact_response->filename !== 'retention-test-2.zip') {
    throw new RuntimeException('İş kimliğine sabitlenmiş ZIP artefaktı bulunamadı.');
}

$bad_callback = new WP_REST_Request('POST');
$bad_callback->set_body_params([
    'job_id' => 'retention-test-2',
    'status' => 'deploying',
    'build_sha256' => str_repeat('9', 64),
]);
if (Wext\StaticPublisher\REST_Controller::deployment_callback($bad_callback)->get_status() !== 409) {
    throw new RuntimeException('Yanlış deploy checksum değeri reddedilmedi.');
}

foreach (['deploying', 'completed'] as $callback_index => $deployment_state) {
    $callback = new WP_REST_Request('POST');
    $callback->set_route('/wext-static/v1/deployments/callback');
    $callback->set_body_params([
        'delivery_id' => 'phase3-delivery-' . ($callback_index + 1),
        'job_id' => 'retention-test-2',
        'status' => $deployment_state,
        'build_sha256' => str_repeat('2', 64),
        'deployment_url' => $deployment_state === 'completed' ? 'https://deployment.example.workers.dev' : '',
    ]);
    if (Wext\StaticPublisher\REST_Controller::deployment_callback($callback)->get_status() !== 200) {
        throw new RuntimeException('Cloudflare deploy callback durumu kaydedilemedi: ' . $deployment_state);
    }
}
$deployment_status = Wext\StaticPublisher\Plugin::deployment_status();
if (($deployment_status['state'] ?? '') !== 'completed'
    || ($deployment_status['job_id'] ?? '') !== 'retention-test-2'
    || ($deployment_status['deployment_url'] ?? '') !== 'https://deployment.example.workers.dev') {
    throw new RuntimeException('Cloudflare deploy sonucu ayrı durum kaydına doğru yazılmadı.');
}

$bundle = Wext\StaticPublisher\Archive_Manager::create_bundle(['retention-test-1', 'retention-test-3']);
$bundle_zip = new ZipArchive();
if ($bundle_zip->open(wext_test_zip_path($bundle)) !== true
    || $bundle_zip->locateName('retention-test-1.zip') === false
    || $bundle_zip->locateName('retention-test-3.zip') === false
    || $bundle_zip->locateName('retention-test-2.zip') !== false) {
    throw new RuntimeException('Toplu indirme paketi yalnızca seçilen ZIP dosyalarını içermiyor.');
}
$bundle_zip->close();
Export_Storage::delete($bundle);

$result = Wext\StaticPublisher\Archive_Manager::delete(['retention-test-1']);
if ($result !== ['deleted' => 1, 'failed' => 0]) {
    throw new RuntimeException('Tekil ZIP silme işlemi beklenen sonucu vermedi.');
}
if (Export_Storage::exists($base . '/archives/retention-test-1.zip') || Export_Storage::files($base . '/builds/retention-test-1') !== []) {
    throw new RuntimeException('Tekil silme ZIP veya ilişkili build klasörünü kaldırmadı.');
}

$settings = Wext\StaticPublisher\Plugin::settings();
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $settings, false);
$settings['archive_retention'] = 1;
update_option(Wext\StaticPublisher\Plugin::SETTINGS_KEY, $settings, false);
if (Export_Storage::exists($base . '/archives/retention-test-2.zip') || Export_Storage::files($base . '/builds/retention-test-2') !== []) {
    throw new RuntimeException('Ayar değişikliği eski ZIP veya ilişkili build klasörünü silmedi.');
}
if (! Export_Storage::exists($base . '/archives/retention-test-3.zip')) {
    throw new RuntimeException('En son ZIP dosyası yanlışlıkla silindi.');
}

$result = Wext\StaticPublisher\Archive_Manager::delete(['retention-test-3']);
if ($result !== ['deleted' => 1, 'failed' => 0] || Wext\StaticPublisher\Archive_Manager::latest() !== null) {
    throw new RuntimeException('En son ZIP dosyası tekil işlemle silinemedi.');
}

echo "Archive retention integration test passed.\n";
