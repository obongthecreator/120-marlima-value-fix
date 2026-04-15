<?php
/**
 * Uninstall script for Inventory Management System
 * This file is executed when the plugin is deleted from WordPress admin
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Only run if user has proper permissions
if (!current_user_can('manage_options')) {
    exit;
}

global $wpdb;

// Remove all plugin tables
$tables_to_drop = array(
    $wpdb->prefix . 'ims_imports',
    $wpdb->prefix . 'ims_stock', 
    $wpdb->prefix . 'ims_chopped',
    $wpdb->prefix . 'ims_integration_tracking',
    $wpdb->prefix . 'ims_products'
);

foreach ($tables_to_drop as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Remove all plugin options
$options_to_delete = array(
    'ims_low_stock_threshold',
    'ims_timezone',
    'ims_last_daily_reset',
    'ims_last_daily_reset_date',
    'ims_opening_values_restored',
    'ims_opening_migration_version',
    'ims_plugin_version',
    'ims_database_version'
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// Clear scheduled events
wp_clear_scheduled_hook('ims_daily_reset');
wp_clear_scheduled_hook('ims_low_stock_alert');

// Remove upload directories
$upload_dir = wp_upload_dir();
$export_dir = $upload_dir['basedir'] . '/ims-exports/';
$backup_dir = $upload_dir['basedir'] . '/ims-backups/';

// Remove export files
if (is_dir($export_dir)) {
    $files = glob($export_dir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($export_dir);
}

// Remove backup files
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($backup_dir);
}

// Clean up any remaining transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ims_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ims_%'");

// Log the uninstall
error_log('IMS Plugin: Uninstall completed successfully at ' . current_time('mysql'));
?>