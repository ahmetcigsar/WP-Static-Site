<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('ragnus_static_settings');
delete_option('ragnus_static_hide_settings');
delete_option('ragnus_static_status');
delete_option('ragnus_static_export_dirty');
delete_option('ragnus_static_plugin_version');
delete_option('ragnus_static_diagnostics');
delete_transient('ragnus_static_export_lock');
remove_role('ragnus_static_deployer');

$administrator = get_role('administrator');
if ($administrator !== null) {
    $administrator->remove_cap('ragnus_static_export');
}
