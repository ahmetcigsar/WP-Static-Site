<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('ragnus_static_settings');
delete_option('ragnus_static_status');
delete_option('ragnus_static_export_dirty');
delete_transient('ragnus_static_export_lock');
