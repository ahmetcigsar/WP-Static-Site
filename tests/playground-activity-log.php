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

update_option(Ragnus\StaticPublisher\Plugin::STATUS_KEY, [
    'job_id' => 'legacy-job',
    'state' => 'completed',
    'log' => [['message' => 'Veritabanında kalmamalı']],
], false);
Ragnus\StaticPublisher\Plugin::remove_legacy_activity_log();
$database_status = get_option(Ragnus\StaticPublisher\Plugin::STATUS_KEY, []);
if (isset($database_status['log'])) {
    throw new RuntimeException('Eski activity log verisi veritabanından temizlenmedi.');
}

Ragnus\StaticPublisher\Activity_Log::reset('activity-job-1');
for ($index = 1; $index <= 120; ++$index) {
    Ragnus\StaticPublisher\Activity_Log::append(
        'activity-job-1',
        'info',
        'Kayıt ' . $index,
        'https://cms.example.com/source/' . $index . '/',
        'static/' . $index
    );
}

$first_page = Ragnus\StaticPublisher\Activity_Log::page(1);
$second_page = Ragnus\StaticPublisher\Activity_Log::page(2);
$third_page = Ragnus\StaticPublisher\Activity_Log::page(3);
if (count($first_page['entries']) !== 50 || count($second_page['entries']) !== 50 || count($third_page['entries']) !== 20) {
    throw new RuntimeException('Activity Log sayfaları 50/50/20 kayıt olarak bölünmedi.');
}
if ($first_page['total'] !== 120 || $first_page['total_pages'] !== 3 || $first_page['job_id'] !== 'activity-job-1') {
    throw new RuntimeException('Activity Log sayfalama metadatası doğru değil.');
}
if (($first_page['entries'][0]['message'] ?? '') !== 'Kayıt 120' || ($third_page['entries'][19]['message'] ?? '') !== 'Kayıt 1') {
    throw new RuntimeException('Activity Log kayıtları en yeniden eskiye sıralanmadı.');
}
if (($first_page['entries'][0]['source_url'] ?? '') !== 'https://cms.example.com/source/120/'
    || ($first_page['entries'][0]['static_path'] ?? '') !== 'static/120') {
    throw new RuntimeException('Kaynak ve statik adres alanları Activity Log dosyasından okunamadı.');
}

Ragnus\StaticPublisher\Activity_Log::reset('activity-job-2');
Ragnus\StaticPublisher\Activity_Log::append(
    'activity-job-2',
    'warning',
    'Yeni işlem kaydı',
    'https://cms.example.com/new-source/'
);
$new_job_page = Ragnus\StaticPublisher\Activity_Log::page(1);
if ($new_job_page['total'] !== 1
    || $new_job_page['job_id'] !== 'activity-job-2'
    || ($new_job_page['entries'][0]['message'] ?? '') !== 'Yeni işlem kaydı'
    || ($new_job_page['entries'][0]['source_url'] ?? '') !== 'https://cms.example.com/new-source/'
    || ($new_job_page['entries'][0]['static_path'] ?? '') !== '') {
    throw new RuntimeException('Yeni static işlemi eski Activity Log kayıtlarını temizlemedi.');
}

echo "Activity Log integration test passed.\n";
