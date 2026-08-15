<?php
/**
 * Plugin Name: Ragnus Static Publisher
 * Description: Exports WordPress sites to static files and prepares them for Cloudflare Pages deployment workflows.
 * Version: 1.24.8
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Ragnus
 * License: GPL-2.0-or-later
 * Text Domain: ragnus-static-publisher
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RAGSTAT_VERSION', '1.24.8');
define('RAGSTAT_FILE', __FILE__);
define('RAGSTAT_DIR', plugin_dir_path(__FILE__));

$ragstat_autoloader = RAGSTAT_DIR . 'vendor/autoload.php';
if (is_readable($ragstat_autoloader)) {
    require_once $ragstat_autoloader;
}

require_once RAGSTAT_DIR . 'includes/class-path-mapper.php';
require_once RAGSTAT_DIR . 'includes/class-hide-replacements.php';
require_once RAGSTAT_DIR . 'includes/class-static-search.php';
require_once RAGSTAT_DIR . 'includes/class-language-routing.php';
require_once RAGSTAT_DIR . 'includes/class-rank-math-integration.php';
require_once RAGSTAT_DIR . 'includes/class-aioseo-integration.php';
require_once RAGSTAT_DIR . 'includes/class-seopress-integration.php';
require_once RAGSTAT_DIR . 'includes/class-block-seo-integration.php';
require_once RAGSTAT_DIR . 'includes/class-secret-store.php';
require_once RAGSTAT_DIR . 'includes/class-sftp-deployer.php';
require_once RAGSTAT_DIR . 'includes/class-archive-manager.php';
require_once RAGSTAT_DIR . 'includes/class-activity-log.php';
require_once RAGSTAT_DIR . 'includes/class-diagnostics.php';
require_once RAGSTAT_DIR . 'includes/class-exporter.php';
require_once RAGSTAT_DIR . 'includes/class-rest-controller.php';
require_once RAGSTAT_DIR . 'includes/class-admin.php';
require_once RAGSTAT_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, [Ragnus\StaticPublisher\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Ragnus\StaticPublisher\Plugin::class, 'deactivate']);

Ragnus\StaticPublisher\Plugin::boot();
