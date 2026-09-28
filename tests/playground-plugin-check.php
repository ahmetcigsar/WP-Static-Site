<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once WP_PLUGIN_DIR . '/plugin-check/plugin.php';
$runner = new WordPress\Plugin_Check\Checker\AJAX_Runner();
$runner->set_plugin('wext-static-publisher/wext-static-publisher.php');
$runner->set_experimental_flag(false);
$runner->set_use_ai(false);
$result = $runner->run();
$report = ['errors' => $result->get_error_count(), 'warnings' => $result->get_warning_count(), 'details' => ['errors' => $result->get_errors(), 'warnings' => $result->get_warnings()]];
file_put_contents('/workspace/wordpress-org/plugin-check-3.2.1.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(['errors' => $report['errors'], 'warnings' => $report['warnings']]) . "\n";
exit($report['errors'] ? 1 : 0);
