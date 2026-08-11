<?php

declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'ragnus-static-publisher/ragnus-static-publisher.php';
$activation = activate_plugin($plugin);
if (is_wp_error($activation)) {
    throw new RuntimeException($activation->get_error_message());
}

do_action('init');

$base = Ragnus\StaticPublisher\Plugin::storage_directory();
wp_mkdir_p($base . '/archives');
wp_mkdir_p($base . '/builds');

$fixtures = [
    ['job_id' => 'retention-test-1', 'finished_at' => '2026-01-01T10:00:00Z', 'url_count' => 10],
    ['job_id' => 'retention-test-2', 'finished_at' => '2026-01-02T10:00:00Z', 'url_count' => 20],
    ['job_id' => 'retention-test-3', 'finished_at' => '2026-01-03T10:00:00Z', 'url_count' => 30],
];

foreach ($fixtures as $fixture) {
    $zip = new ZipArchive();
    $path = $base . '/archives/' . $fixture['job_id'] . '.zip';
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Test ZIP dosyası oluşturulamadı.');
    }
    $zip->addFromString('ragnus-static-manifest.json', (string) wp_json_encode($fixture));
    $zip->close();

    $build = $base . '/builds/' . $fixture['job_id'];
    wp_mkdir_p($build);
    file_put_contents($build . '/index.html', $fixture['job_id']);
}

$archives = Ragnus\StaticPublisher\Archive_Manager::archives();
if (array_column($archives, 'job_id') !== ['retention-test-3', 'retention-test-2', 'retention-test-1']) {
    throw new RuntimeException('ZIP dosyaları oluşturma zamanına göre sıralanmadı.');
}
if (($archives[0]['url_count'] ?? null) !== 30) {
    throw new RuntimeException('URL sayısı manifestten okunamadı.');
}
if ((Ragnus\StaticPublisher\Archive_Manager::find('retention-test-2')['url_count'] ?? null) !== 20) {
    throw new RuntimeException('Tekil ZIP dosyası iş kimliğiyle bulunamadı.');
}

$bundle = Ragnus\StaticPublisher\Archive_Manager::create_bundle(['retention-test-1', 'retention-test-3']);
$bundle_zip = new ZipArchive();
if ($bundle_zip->open($bundle) !== true
    || $bundle_zip->locateName('retention-test-1.zip') === false
    || $bundle_zip->locateName('retention-test-3.zip') === false
    || $bundle_zip->locateName('retention-test-2.zip') !== false) {
    throw new RuntimeException('Toplu indirme paketi yalnızca seçilen ZIP dosyalarını içermiyor.');
}
$bundle_zip->close();
unlink($bundle);

$result = Ragnus\StaticPublisher\Archive_Manager::delete(['retention-test-1']);
if ($result !== ['deleted' => 1, 'failed' => 0]) {
    throw new RuntimeException('Tekil ZIP silme işlemi beklenen sonucu vermedi.');
}
if (file_exists($base . '/archives/retention-test-1.zip') || is_dir($base . '/builds/retention-test-1')) {
    throw new RuntimeException('Tekil silme ZIP veya ilişkili build klasörünü kaldırmadı.');
}

$settings = Ragnus\StaticPublisher\Plugin::settings();
update_option(Ragnus\StaticPublisher\Plugin::SETTINGS_KEY, $settings, false);
$settings['archive_retention'] = 1;
update_option(Ragnus\StaticPublisher\Plugin::SETTINGS_KEY, $settings, false);
if (file_exists($base . '/archives/retention-test-2.zip') || is_dir($base . '/builds/retention-test-2')) {
    throw new RuntimeException('Ayar değişikliği eski ZIP veya ilişkili build klasörünü silmedi.');
}
if (! file_exists($base . '/archives/retention-test-3.zip')) {
    throw new RuntimeException('En son ZIP dosyası yanlışlıkla silindi.');
}

$result = Ragnus\StaticPublisher\Archive_Manager::delete(['retention-test-3']);
if ($result !== ['deleted' => 1, 'failed' => 0] || Ragnus\StaticPublisher\Archive_Manager::latest() !== null) {
    throw new RuntimeException('En son ZIP dosyası tekil işlemle silinemedi.');
}

echo "Archive retention integration test passed.\n";
