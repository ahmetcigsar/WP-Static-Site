<?php
/**
 * Plugin Name: Wext Static Publisher
 * Description: Exports WordPress sites to static files and prepares them for Cloudflare Pages deployment workflows.
 * Version: 2.0.2
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Wext
 * License: GPL-2.0-or-later
 * Text Domain: wext-static-publisher
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('WEXTSTAT_VERSION', '2.0.2');
define('WEXTSTAT_FILE', __FILE__);
define('WEXTSTAT_DIR', plugin_dir_path(__FILE__));

$wextstat_autoloader = WEXTSTAT_DIR . 'vendor/autoload.php';
if (is_readable($wextstat_autoloader)) {
    require_once $wextstat_autoloader;
}

require_once WEXTSTAT_DIR . 'includes/class-path-mapper.php';
require_once WEXTSTAT_DIR . 'includes/class-hide-replacements.php';
require_once WEXTSTAT_DIR . 'includes/class-static-search.php';
require_once WEXTSTAT_DIR . 'includes/class-language-routing.php';
require_once WEXTSTAT_DIR . 'includes/class-rank-math-integration.php';
require_once WEXTSTAT_DIR . 'includes/class-aioseo-integration.php';
require_once WEXTSTAT_DIR . 'includes/class-seopress-integration.php';
require_once WEXTSTAT_DIR . 'includes/class-block-seo-integration.php';
require_once WEXTSTAT_DIR . 'includes/class-seo-toolkit.php';
require_once WEXTSTAT_DIR . 'includes/class-secret-store.php';
require_once WEXTSTAT_DIR . 'includes/class-headless-mode.php';
require_once WEXTSTAT_DIR . 'includes/class-managed-deployer.php';
require_once WEXTSTAT_DIR . 'includes/class-sftp-deployer.php';
require_once WEXTSTAT_DIR . 'includes/class-archive-manager.php';
require_once WEXTSTAT_DIR . 'includes/class-activity-log.php';
require_once WEXTSTAT_DIR . 'includes/class-diagnostics.php';
require_once WEXTSTAT_DIR . 'includes/class-exporter.php';
require_once WEXTSTAT_DIR . 'includes/class-rest-controller.php';
require_once WEXTSTAT_DIR . 'includes/class-admin.php';
require_once WEXTSTAT_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, [Wext\StaticPublisher\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Wext\StaticPublisher\Plugin::class, 'deactivate']);

Wext\StaticPublisher\Plugin::boot();
