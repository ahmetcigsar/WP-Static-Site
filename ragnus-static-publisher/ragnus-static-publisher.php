<?php
/**
 * Plugin Name: Ragnus Static Publisher
 * Description: WordPress sitelerini statik dosyalara aktarır ve Cloudflare Pages yayın akışlarına hazırlar.
 * Version: 1.12.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Ragnus
 * License: GPL-2.0-or-later
 * Text Domain: ragnus-static-publisher
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RAGSTAT_VERSION', '1.12.0');
define('RAGSTAT_FILE', __FILE__);
define('RAGSTAT_DIR', plugin_dir_path(__FILE__));

require_once RAGSTAT_DIR . 'includes/class-path-mapper.php';
require_once RAGSTAT_DIR . 'includes/class-hide-replacements.php';
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
