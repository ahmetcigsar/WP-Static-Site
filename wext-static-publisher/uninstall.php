<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wext_static_settings');
delete_option('wext_static_hide_settings');
delete_option('wext_static_search_settings');
delete_option('wext_static_language_settings');
delete_option('wext_static_seo_plugin_settings');
delete_option('wext_static_seo_settings');
delete_option('wext_static_indexnow_snapshot');
delete_option('wext_static_indexnow_status');
delete_option('wext_static_status');
delete_option('wext_static_export_dirty');
delete_option('wext_static_plugin_version');
delete_option('wext_static_brand_migration');
delete_option('wext_static_managed_connection');
delete_option('wext_static_license');
delete_option('wext_static_installation_id');
delete_option('wext_static_callback_deliveries');
delete_option('wext_static_cloudflare_connection');
delete_option('wext_static_deployment_status');
delete_option('wext_static_diagnostics');
delete_option('wext_static_sftp_status');
delete_transient('wext_static_export_lock');
delete_transient('wext_static_sftp_lock');
remove_role('wext_static_deployer');

$wextstat_administrator = get_role('administrator');
if ($wextstat_administrator !== null) {
    $wextstat_administrator->remove_cap('wext_static_export');
}

// Export objects are private per-site database data, including on multisite.
function wextstat_delete_database_storage(): void
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove only the plugin-owned export table on uninstall.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wext_static_objects'));
    delete_option('wext_static_storage_schema');
    delete_option('wext_static_database_migration');
    delete_option('wext_static_storage_migration_error');
}
if (is_multisite()) {
    $wextstat_offset = 0;
    do {
        $wextstat_sites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $wextstat_offset]);
        foreach ($wextstat_sites as $wextstat_site_id) {
            switch_to_blog((int) $wextstat_site_id);
            wextstat_delete_database_storage();
            restore_current_blog();
        }
        $wextstat_offset += 100;
    } while (count($wextstat_sites) === 100);
} else {
    wextstat_delete_database_storage();
}
