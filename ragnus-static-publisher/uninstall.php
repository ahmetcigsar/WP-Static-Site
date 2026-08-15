<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('ragnus_static_settings');
delete_option('ragnus_static_hide_settings');
delete_option('ragnus_static_search_settings');
delete_option('ragnus_static_language_settings');
delete_option('ragnus_static_seo_plugin_settings');
delete_option('ragnus_static_seo_settings');
delete_option('ragnus_static_indexnow_snapshot');
delete_option('ragnus_static_indexnow_status');
delete_option('ragnus_static_status');
delete_option('ragnus_static_export_dirty');
delete_option('ragnus_static_plugin_version');
delete_option('ragnus_static_managed_connection');
delete_option('ragnus_static_diagnostics');
delete_option('ragnus_static_sftp_status');
delete_transient('ragnus_static_export_lock');
delete_transient('ragnus_static_sftp_lock');
remove_role('ragnus_static_deployer');

$administrator = get_role('administrator');
if ($administrator !== null) {
    $administrator->remove_cap('ragnus_static_export');
}
