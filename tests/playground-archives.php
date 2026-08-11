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

$settings = Ragnus\StaticPublisher\Plugin::settings();
update_option(Ragnus\StaticPublisher\Plugin::SETTINGS_KEY, $settings, false);
$settings['archive_retention'] = 2;
update_option(Ragnus\StaticPublisher\Plugin::SETTINGS_KEY, $settings, false);
if (file_exists($base . '/archives/retention-test-1.zip') || is_dir($base . '/builds/retention-test-1')) {
    throw new RuntimeException('Ayar değişikliği eski ZIP veya ilişkili build klasörünü silmedi.');
}
if (! file_exists($base . '/archives/retention-test-2.zip') || ! file_exists($base . '/archives/retention-test-3.zip')) {
    throw new RuntimeException('Ayar değişikliği saklanması gereken ZIP dosyalarından birini sildi.');
}

$result = Ragnus\StaticPublisher\Archive_Manager::delete_old_archives();
if ($result !== ['deleted' => 1, 'failed' => 0]) {
    throw new RuntimeException('Eski Dosyaları Sil işlemi beklenen sayıda ZIP silmedi.');
}
if (! file_exists($base . '/archives/retention-test-3.zip')) {
    throw new RuntimeException('En son ZIP dosyası yanlışlıkla silindi.');
}
if (file_exists($base . '/archives/retention-test-2.zip') || is_dir($base . '/builds/retention-test-2')) {
    throw new RuntimeException('Önceki ZIP veya ilişkili build klasörü silinmedi.');
}

echo "Archive retention integration test passed.\n";
