<?php
/**
 * Plugin Name: Inventory Management System
 * Plugin URI: https://github.com/Officialese/inventory-management-plugin
 * Description: Comprehensive inventory management system for imports, stock, and chopped items with real-time integration and analytics.
 * Version: 1.0.1
 * Author: Officialese
 * Author URI: https://github.com/Officialese
 * Text Domain: inventory-management
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access not allowed.');
}

// Define plugin constants
define('IMS_VERSION', '1.0.1');
define('IMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IMS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('IMS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check PHP version
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Inventory Management System:</strong> This plugin requires PHP 7.4 or higher. You are running PHP ' . esc_html(PHP_VERSION) . '</p></div>';
    });
    return;
}

/* =========================
   Utility helpers
   ========================= */
function ims_get_lagos_time() {
    try {
        $timezone = new DateTimeZone('Africa/Lagos');
        $datetime = new DateTime('now', $timezone);
        return $datetime->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return current_time('mysql');
    }
}

/* Remarks normalization: prevent saving "0" / "0.000000" etc. */
function ims_is_zero_like_remarks($val) {
    if (is_null($val)) return true;
    if (is_numeric($val)) return (float)$val == 0.0;
    if (!is_string($val)) return false;
    $t = trim($val);
    if ($t === '') return true;
    if (preg_match('/[a-zA-Z]/', $t)) return false;
    if (!preg_match('/^[0\.\,\s]+$/', $t)) return false;
    $numeric = str_replace([' ', ','], '', $t);
    if ($numeric === '' || !preg_match('/^\d*\.?\d*$/', $numeric)) $numeric = '0';
    return (float)$numeric == 0.0;
}
function ims_normalize_remarks($val) {
    $val = is_string($val) ? trim($val) : $val;
    return ims_is_zero_like_remarks($val) ? '' : (is_string($val) ? $val : '');
}

/* Admin URL helper */
function ims_admin_url($slug, $args = array()) {
    $url = admin_url('admin.php?page=' . $slug);
    return add_query_arg($args, $url);
}

/* =========================
   Admin menu
   ========================= */
add_action('admin_menu', 'ims_create_admin_menu');

function ims_create_admin_menu() {
    add_menu_page(
        'Inventory Management',
        'Inventory',
        'manage_options',
        'inventory-management',
        'ims_admin_dashboard_page',
        'dashicons-clipboard',
        30
    );

    add_submenu_page(
        'inventory-management',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'inventory-management',
        'ims_admin_dashboard_page'
    );

    add_submenu_page(
        'inventory-management',
        'Products',
        'Products',
        'manage_options',
        'ims-products',
        'ims_products_page'
    );

    add_submenu_page(
        'inventory-management',
        'Import Records',
        'Import Records',
        'manage_options',
        'ims-import-records',
        'ims_import_records_page'
    );

    add_submenu_page(
        'inventory-management',
        'Stock Records',
        'Stock Records',
        'manage_options',
        'ims-stock-records',
        'ims_stock_records_page'
    );

    // Correct slug + callback
    add_submenu_page(
        'inventory-management',
        'Chopped Records',
        'Chopped Records',
        'manage_options',
        'ims-chopped-records',
        'ims_chopped_records_page'
    );

    add_submenu_page(
        'inventory-management',
        'Settings',
        'Settings',
        'manage_options',
        'ims-settings',
        'ims_settings_page'
    );
}

/* =========================
   Admin notices (success/error)
   ========================= */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;

    if (!empty($_GET['ims_msg'])) {
        $msg = sanitize_text_field($_GET['ims_msg']);
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        $text = '';
        $class = 'updated';
        switch ($msg) {
            case 'deleted':
                $text = sprintf('Deleted %d record(s) successfully.', $count);
                $class = 'updated';
                break;
            case 'none_selected':
                $text = 'No records selected to delete.';
                $class = 'notice-warning';
                break;
            case 'invalid':
                $text = 'Invalid delete request.';
                $class = 'notice-error';
                break;
            case 'dberror':
                $err = isset($_GET['err']) ? sanitize_text_field($_GET['err']) : 'Database error occurred.';
                $text = 'Error: ' . $err;
                $class = 'notice-error';
                break;
        }
        if ($text !== '') {
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
        }
    }
});

/* Products page notices */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'ims-products') return;

    if (!empty($_GET['ims_prod_msg'])) {
        $msg = sanitize_text_field($_GET['ims_prod_msg']);
        $class = 'updated';
        $text = '';
        switch ($msg) {
            case 'added':     $text = 'Product added successfully.'; break;
            case 'updated':   $text = 'Product updated successfully.'; break;
            case 'deleted':   $text = 'Product deleted successfully.'; break;
            case 'duplicate': $text = 'A product with the same name already exists in this category.'; $class='notice-warning'; break;
            case 'invalid':   $text = 'Invalid product request.'; $class='notice-error'; break;
            case 'dberror':   $err = isset($_GET['err']) ? sanitize_text_field($_GET['err']) : 'Database error occurred.'; $text = 'Error: ' . $err; $class='notice-error'; break;
        }
        if ($text) {
            echo '<div class="notice '.esc_attr($class).' is-dismissible"><p>'.esc_html($text).'</p></div>';
        }
    }
});

/* =========================
   Cascade delete helpers
   ========================= */
function ims_is_fruit($product) {
    global $wpdb;
    $products_table = $wpdb->prefix . 'ims_products';
    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT type FROM $products_table WHERE name = %s AND is_active = 1 LIMIT 1",
        $product
    ));
    return $result === 'chopped';
}

function ims_cascade_delete_for_row($table_key, $row) {
    global $wpdb;
    $stock  = $wpdb->prefix . 'ims_stock';
    $imports= $wpdb->prefix . 'ims_imports';
    $chopped= $wpdb->prefix . 'ims_chopped';
    $date = date('Y-m-d', strtotime($row->date_created ?? $row->date ?? 'now'));

    if ($table_key === 'imports') {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $stock WHERE product = %s AND DATE(date_created) = %s",
            $row->product, $date
        ));
    } elseif ($table_key === 'chopped') {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $stock WHERE product = %s AND DATE(date_created) = %s",
            $row->fruit, $date
        ));
    } elseif ($table_key === 'stock') {
        $product = $row->product;
        if (ims_is_fruit($product)) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $chopped WHERE fruit = %s AND DATE(date_created) = %s",
                $product, $date
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $imports WHERE product = %s AND DATE(date_created) = %s",
                $product, $date
            ));
        }
    }
}

function ims_cascade_delete_for_filters($table_key, $date_from, $date_to, $name_val) {
    global $wpdb;
    $stock  = $wpdb->prefix . 'ims_stock';
    $imports= $wpdb->prefix . 'ims_imports';
    $chopped= $wpdb->prefix . 'ims_chopped';

    $have_from = !empty($date_from);
    $have_to   = !empty($date_to);
    $range_sql = '';
    $range_params = array();
    if ($have_from) { $range_sql .= " AND DATE(date_created) >= %s"; $range_params[] = $date_from; }
    if ($have_to)   { $range_sql .= " AND DATE(date_created) <= %s"; $range_params[] = $date_to; }

    if ($table_key === 'imports') {
        if (!empty($name_val)) {
            $sql = "DELETE FROM $stock WHERE product = %s" . $range_sql;
            $wpdb->query($wpdb->prepare($sql, array_merge(array($name_val), $range_params)));
        } else {
            $sql = "DELETE FROM $stock WHERE 1=1" . $range_sql;
            if (!empty($range_params)) $wpdb->query($wpdb->prepare($sql, $range_params)); else $wpdb->query($sql);
        }
    } elseif ($table_key === 'chopped') {
        if (!empty($name_val)) {
            $sql = "DELETE FROM $stock WHERE product = %s" . $range_sql;
            $wpdb->query($wpdb->prepare($sql, array_merge(array($name_val), $range_params)));
        } else {
            $sql = "DELETE FROM $stock WHERE 1=1" . $range_sql;
            if (!empty($range_params)) $wpdb->query($wpdb->prepare($sql, $range_params)); else $wpdb->query($sql);
        }
    } elseif ($table_key === 'stock') {
        if (!empty($name_val)) {
            if (ims_is_fruit($name_val)) {
                $sql = "DELETE FROM $chopped WHERE fruit = %s" . $range_sql;
                $wpdb->query($wpdb->prepare($sql, array_merge(array($name_val), $range_params)));
            } else {
                $sql = "DELETE FROM $imports WHERE product = %s" . $range_sql;
                $wpdb->query($wpdb->prepare($sql, array_merge(array($name_val), $range_params)));
            }
        } else {
            $sql1 = "DELETE FROM $chopped WHERE 1=1" . $range_sql;
            $sql2 = "DELETE FROM $imports WHERE 1=1" . $range_sql;
            if (!empty($range_params)) {
                $wpdb->query($wpdb->prepare($sql1, $range_params));
                $wpdb->query($wpdb->prepare($sql2, $range_params));
            } else {
                $wpdb->query($sql1);
                $wpdb->query($sql2);
            }
        }
    }
}

/* =========================
   Admin delete handler (single, bulk, filtered) with cascade option
   ========================= */
add_action('admin_init', 'ims_handle_admin_deletions');
function ims_handle_admin_deletions() {
    if (!is_admin() || !current_user_can('manage_options')) return;

    global $wpdb;

    $action = isset($_REQUEST['ims_action']) ? sanitize_key($_REQUEST['ims_action']) : '';
    if ($action === '') return;

    $table_key = isset($_REQUEST['table_key']) ? sanitize_key($_REQUEST['table_key']) : '';
    $map = array(
        'imports' => array('table' => $wpdb->prefix . 'ims_imports', 'id' => 'id', 'date' => 'date_created', 'name' => 'product', 'page' => 'ims-import-records'),
        'stock'   => array('table' => $wpdb->prefix . 'ims_stock',   'id' => 'id', 'date' => 'date_created', 'name' => 'product', 'page' => 'ims-stock-records'),
        'chopped' => array('table' => $wpdb->prefix . 'ims_chopped', 'id' => 'id', 'date' => 'date_created', 'name' => 'fruit',   'page' => 'ims-chopped-records'),
    );
    if (!isset($map[$table_key])) {
        wp_redirect(ims_admin_url('ims-' . ($table_key ? $table_key . '-records' : 'inventory-management'), array('ims_msg' => 'invalid')));
        exit;
    }
    $info = $map[$table_key];
    $redirect_page = $info['page'];

    $cascade = (
        (isset($_REQUEST['ims_also_delete_linked']) && $_REQUEST['ims_also_delete_linked'] === '1') ||
        (isset($_GET['cascade']) && $_GET['cascade'] === '1')
    );

    if ($action === 'delete_row' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        if ($id <= 0 || !wp_verify_nonce($nonce, "ims_delete_row_{$table_key}_{$id}")) {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'invalid')));
            exit;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$info['table']} WHERE {$info['id']} = %d", $id));

        $deleted = $wpdb->delete($info['table'], array($info['id'] => $id), array('%d'));
        if ($deleted !== false && $cascade && $row) {
            ims_cascade_delete_for_row($table_key, $row);
        }

        if ($deleted === false) {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'dberror', 'err' => rawurlencode($wpdb->last_error))));
        } else {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'deleted', 'count' => $deleted)));
        }
        exit;
    }

    if ($action === 'bulk_delete' && isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $nonce = isset($_POST['ims_bulk_delete_nonce']) ? $_POST['ims_bulk_delete_nonce'] : '';
        if (!wp_verify_nonce($nonce, "ims_bulk_delete_{$table_key}")) {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'invalid')));
            exit;
        }
        $ids = array_map('intval', $_POST['selected_ids']);
        $ids = array_values(array_filter($ids, function($v){ return $v > 0; }));
        if (empty($ids)) {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'none_selected')));
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$info['table']} WHERE {$info['id']} IN ($placeholders)",
            $ids
        ));

        $count = 0;
        foreach ($ids as $id) {
            $res = $wpdb->delete($info['table'], array($info['id'] => $id), array('%d'));
            if ($res !== false) $count += $res;
        }

        if ($count > 0 && $cascade && $rows) {
            foreach ($rows as $row) {
                ims_cascade_delete_for_row($table_key, $row);
            }
        }

        if ($wpdb->last_error) {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'dberror', 'err' => rawurlencode($wpdb->last_error))));
        } else {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'deleted', 'count' => $count)));
        }
        exit;
    }

    if ($action === 'delete_filtered') {
        $nonce = isset($_POST['ims_delete_filtered_nonce']) ? $_POST['ims_delete_filtered_nonce'] : '';
        if (!wp_verify_nonce($nonce, "ims_delete_filtered_{$table_key}")) {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'invalid')));
            exit;
        }
        $date_from = isset($_POST['ims_date_from']) ? sanitize_text_field($_POST['ims_date_from']) : '';
        $date_to   = isset($_POST['ims_date_to']) ? sanitize_text_field($_POST['ims_date_to']) : '';
        $name_val  = isset($_POST['ims_name']) ? sanitize_text_field($_POST['ims_name']) : '';

        $where = 'WHERE 1=1';
        $params = array();

        if ($date_from !== '') { $where .= " AND DATE(date_created) >= %s"; $params[] = $date_from; }
        if ($date_to !== '')   { $where .= " AND DATE(date_created) <= %s"; $params[] = $date_to; }
        if ($name_val !== '')  { $where .= " AND {$info['name']} = %s";    $params[] = $name_val; }

        if ($where === 'WHERE 1=1') {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'invalid')));
            exit;
        }

        $sql = "DELETE FROM {$info['table']} {$where}";
        if (!empty($params)) {
            $prepared = $wpdb->prepare($sql, $params);
            $wpdb->query($prepared);
        } else {
            $wpdb->query($sql);
        }

        if ($cascade) {
            ims_cascade_delete_for_filters($table_key, $date_from, $date_to, $name_val);
        }

        if ($wpdb->last_error) {
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'dberror', 'err' => rawurlencode($wpdb->last_error))));
        } else {
            $affected = isset($wpdb->rows_affected) ? intval($wpdb->rows_affected) : 0;
            wp_redirect(ims_admin_url($redirect_page, array('ims_msg' => 'deleted', 'count' => $affected)));
        }
        exit;
    }
}

/* =========================
   Auto-initialize DB tables + default products for existing installs
   ========================= */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;

    // Run once per plugin version to ensure tables + products exist
    $db_init_version = get_option('ims_db_init_version', '');
    if ($db_init_version === IMS_VERSION) return;

    ims_create_database_tables();
    ims_populate_default_products();
    update_option('ims_db_init_version', IMS_VERSION);
});

/* =========================
   Products CRUD (admin_init)
   ========================= */
add_action('admin_init', 'ims_handle_products_admin_actions');
function ims_handle_products_admin_actions() {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'ims-products') return;

    global $wpdb;
    $table = $wpdb->prefix . 'ims_products';

    if (!empty($_POST['ims_products_nonce']) && wp_verify_nonce($_POST['ims_products_nonce'], 'ims_products_form')) {
        $action = isset($_POST['ims_prod_action']) ? sanitize_key($_POST['ims_prod_action']) : '';
        $name = isset($_POST['name']) ? trim(sanitize_text_field($_POST['name'])) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'all';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $sort_order = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;

        if ($name === '' || !in_array($type, array('all','chopped'), true)) {
            wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'invalid')));
            exit;
        }

        if ($action === 'add') {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE name = %s AND type = %s LIMIT 1", $name, $type));
            if ($exists) {
                wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'duplicate')));
                exit;
            }
            $res = $wpdb->insert($table, array(
                'name' => $name,
                'type' => $type,
                'is_active' => $is_active,
                'sort_order' => $sort_order
            ), array('%s','%s','%d','%d'));

            if ($res === false) {
                wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'dberror', 'err' => rawurlencode($wpdb->last_error))));
            } else {
                wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'added', 'tab' => $type)));
            }
            exit;
        }

        if ($action === 'update') {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id <= 0) {
                wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'invalid')));
                exit;
            }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE name = %s AND type = %s AND id <> %d LIMIT 1", $name, $type, $id));
            if ($exists) {
                wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'duplicate', 'tab' => $type)));
                exit;
            }
            $res = $wpdb->update($table, array(
                'name' => $name,
                'type' => $type,
                'is_active' => $is_active,
                'sort_order' => $sort_order
            ), array('id' => $id), array('%s','%s','%d','%d'), array('%d'));

            if ($res === false) {
                wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'dberror', 'err' => rawurlencode($wpdb->last_error), 'tab' => $type)));
            } else {
                wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'updated', 'tab' => $type)));
            }
            exit;
        }
    }

    if (isset($_GET['ims_prod_action']) && $_GET['ims_prod_action'] === 'delete' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        if ($id <= 0 || !wp_verify_nonce($nonce, 'ims_delete_product_'.$id)) {
            wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'invalid')));
            exit;
        }
        $res = $wpdb->delete($table, array('id' => $id), array('%d'));
        if ($res === false) {
            wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'dberror', 'err' => rawurlencode($wpdb->last_error))));
        } else {
            wp_redirect(ims_admin_url('ims-products', array('ims_prod_msg' => 'deleted')));
        }
        exit;
    }
}

/* =========================
   Helper: last known closings (persistence across days)
   ========================= */
function ims_get_last_stock_closing_before($product, $dateYmd) {
    global $wpdb;
    $t = $wpdb->prefix . 'ims_stock';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT closing_packs FROM $t WHERE product = %s AND date_created < %s ORDER BY date_created DESC, id DESC LIMIT 1",
        $product, $dateYmd . ' 00:00:00'
    ));
    return $row ? max(0.0, floatval($row->closing_packs)) : 0.0;
}
function ims_get_last_chopped_closing_before($fruit, $dateYmd) {
    global $wpdb;
    $t = $wpdb->prefix . 'ims_chopped';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT closing_whole FROM $t WHERE fruit = %s AND date_created < %s ORDER BY date_created DESC, id DESC LIMIT 1",
        $fruit, $dateYmd . ' 00:00:00'
    ));
    return $row ? max(0.0, floatval($row->closing_whole)) : 0.0;
}

/* =========================
   Admin page renderers (with filter + delete actions)
   ========================= */
function ims_admin_dashboard_page() {
    echo '<div class="wrap">';
    echo '<h1><iconify-icon icon="solar:target-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Inventory Management System</h1>';
    echo '<p>Current Time: ' . esc_html(ims_get_lagos_time()) . ' (Lagos)</p>';
    echo '</div>';
}

/* Products Admin Page: list by category, add/edit/delete */
function ims_products_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'ims_products';

    $active_tab = isset($_GET['tab']) && in_array($_GET['tab'], array('all','chopped'), true) ? $_GET['tab'] : 'all';
    $search = isset($_GET['s']) ? trim(sanitize_text_field($_GET['s'])) : '';
    $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $edit_row = null;

    if ($edit_id > 0) {
        $edit_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d LIMIT 1", $edit_id));
        if (!$edit_row) $edit_id = 0;
    }

    $count_all = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE type = 'all'");
    $count_chopped = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE type = 'chopped'");

    $where = "WHERE type = %s";
    $params = array($active_tab);
    if ($search !== '') {
        $where .= " AND name LIKE %s";
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table $where ORDER BY sort_order ASC, name ASC LIMIT 500", $params));

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Products</h1>';

    echo '<h2 class="nav-tab-wrapper" style="margin-top:12px;">';
    echo '<a href="'.esc_url(ims_admin_url('ims-products', array('tab'=>'all'))).'" class="nav-tab '.($active_tab==='all'?'nav-tab-active':'').'">All Products <span class="count">('.$count_all.')</span></a>';
    echo '<a href="'.esc_url(ims_admin_url('ims-products', array('tab'=>'chopped'))).'" class="nav-tab '.($active_tab==='chopped'?'nav-tab-active':'').'">Chopped Products <span class="count">('.$count_chopped.')</span></a>';
    echo '</h2>';

    echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;">';
    echo '<input type="hidden" name="page" value="ims-products">';
    echo '<input type="hidden" name="tab" value="'.esc_attr($active_tab).'">';
    echo '<input type="search" name="s" value="'.esc_attr($search).'" placeholder="Search by name..." class="regular-text">';
    echo '<button class="button">Search</button>';
    if ($search !== '') {
        echo '<a class="button" href="'.esc_url(ims_admin_url('ims-products', array('tab'=>$active_tab))).'">Clear</a>';
    }
    echo '</form>';

    $is_editing = ($edit_id > 0 && $edit_row);
    echo '<div class="card" style="max-width:900px;margin:12px 0;padding:16px 20px;">';
    echo '<h2 style="margin-top:0;">'.($is_editing?'Edit Product':'Add New Product').'</h2>';
    echo '<form method="post">';
    wp_nonce_field('ims_products_form', 'ims_products_nonce');
    echo '<input type="hidden" name="ims_prod_action" value="'.($is_editing?'update':'add').'">';
    if ($is_editing) {
        echo '<input type="hidden" name="id" value="'.esc_attr($edit_row->id).'">';
    }
    echo '<p><label><strong>Name</strong><br>';
    echo '<input type="text" name="name" class="regular-text" required value="'.esc_attr($is_editing?$edit_row->name:'').'"></label></p>';
    $type_val = $is_editing ? $edit_row->type : $active_tab;
    echo '<p><label><strong>Category</strong><br>';
    echo '<select name="type">';
    echo '<option value="all" '.selected($type_val,'all',false).'>All</option>';
    echo '<option value="chopped" '.selected($type_val,'chopped',false).'>Chopped</option>';
    echo '</select>';
    echo '</label></p>';
    echo '<p><label><strong>Sort Order</strong><br>';
    echo '<input type="number" name="sort_order" step="1" min="0" value="'.esc_attr($is_editing?intval($edit_row->sort_order):0).'"></label></p>';
    $active_checked = $is_editing ? (intval($edit_row->is_active)===1) : true;
    echo '<p><label><input type="checkbox" name="is_active" value="1" '.checked($active_checked, true, false).'> Active</label></p>';

    echo '<p>';
    echo '<button class="button button-primary">'.($is_editing?'Update Product':'Add Product').'</button> ';
    if ($is_editing) {
        echo '<a class="button" href="'.esc_url(ims_admin_url('ims-products', array('tab'=>$type_val))).'">Cancel</a>';
    }
    echo '</p>';
    echo '</form>';
    echo '</div>';

    echo '<h2 style="margin-top:20px;">'.($active_tab==='all'?'All Products':'Chopped Products').'</h2>';
    echo '<table class="wp-list-table widefat striped" style="max-width:1100px;">';
    echo '<thead><tr>';
    echo '<th style="width:70px;">ID</th>';
    echo '<th>Name</th>';
    echo '<th style="width:140px;">Category</th>';
    echo '<th style="width:120px;">Active</th>';
    echo '<th style="width:120px;">Sort Order</th>';
    echo '<th style="width:180px;">Actions</th>';
    echo '</tr></thead><tbody>';

    if ($rows) {
        foreach ($rows as $r) {
            $edit_url = ims_admin_url('ims-products', array('tab'=>$active_tab, 'edit'=>$r->id));
            $del_url = wp_nonce_url(ims_admin_url('ims-products', array(
                'ims_prod_action'=>'delete',
                'id'=>$r->id,
                'tab'=>$active_tab
            )), 'ims_delete_product_'.$r->id);
            echo '<tr>';
            echo '<td>'.esc_html($r->id).'</td>';
            echo '<td><strong>'.esc_html($r->name).'</strong></td>';
            echo '<td>'.esc_html(ucfirst($r->type)).'</td>';
            echo '<td>'.(intval($r->is_active)===1 ? '<span style="color:#28a745;font-weight:600;">Yes</span>' : '<span style="color:#dc3545;font-weight:600;">No</span>').'</td>';
            echo '<td>'.intval($r->sort_order).'</td>';
            echo '<td>';
            echo '<a class="button button-small" href="'.esc_url($edit_url).'">Edit</a> ';
            echo '<a class="button button-small" style="color:#a00;border-color:#a00;" href="'.esc_url($del_url).'" onclick="return confirm(\'Delete this product? This does not remove existing records using this name.\');">Delete</a>';
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6"><em>No products found.</em></td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

function ims_settings_page() {
    echo '<div class="wrap"><h1>Settings</h1><p>IMS version: ' . esc_html(IMS_VERSION) . '</p></div>';
}

function ims_render_filters_and_delete_filtered($table_key, $page_slug, $name_label, $name_param_key) {
    $date_from = isset($_GET['ims_date_from']) ? sanitize_text_field($_GET['ims_date_from']) : '';
    $date_to   = isset($_GET['ims_date_to']) ? sanitize_text_field($_GET['ims_date_to']) : '';
    $name_val  = isset($_GET[$name_param_key]) ? sanitize_text_field($_GET[$name_param_key]) : '';
    ?>
    <div class="ims-filters" style="margin:12px 0; display:flex; gap:8px; flex-wrap:wrap;">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:8px; align-items:flex-end;">
            <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
            <div>
                <label for="ims_date_from"><strong>From</strong></label><br>
                <input type="date" id="ims_date_from" name="ims_date_from" value="<?php echo esc_attr($date_from); ?>">
            </div>
            <div>
                <label for="ims_date_to"><strong>To</strong></label><br>
                <input type="date" id="ims_date_to" name="ims_date_to" value="<?php echo esc_attr($date_to); ?>">
            </div>
            <div>
                <label for="<?php echo esc_attr($name_param_key); ?>"><strong><?php echo esc_html($name_label); ?></strong></label><br>
                <input type="text" id="<?php echo esc_attr($name_param_key); ?>" name="<?php echo esc_attr($name_param_key); ?>" value="<?php echo esc_attr($name_val); ?>" placeholder="Exact match">
            </div>
            <div>
                <button class="button button-primary">Filter</button>
                <a class="button" href="<?php echo esc_url(ims_admin_url($page_slug)); ?>">Clear</a>
            </div>
        </form>

        <form method="post" onsubmit="return confirm('Are you sure you want to permanently delete all filtered records? This cannot be undone.');" style="margin-left:auto;">
            <input type="hidden" name="ims_action" value="delete_filtered">
            <input type="hidden" name="table_key" value="<?php echo esc_attr($table_key); ?>">
            <?php wp_nonce_field("ims_delete_filtered_{$table_key}", 'ims_delete_filtered_nonce'); ?>
            <input type="hidden" name="ims_date_from" value="<?php echo esc_attr($date_from); ?>">
            <input type="hidden" name="ims_date_to" value="<?php echo esc_attr($date_to); ?>">
            <input type="hidden" name="ims_name" value="<?php echo esc_attr($name_val); ?>">
            <label style="margin-right:8px;">
                <input type="checkbox" name="ims_also_delete_linked" value="1"> Also delete linked integrations (cascade)
            </label>
            <button class="button button-secondary" style="border-color:#d33;color:#d33;">Delete Filtered</button>
        </form>
    </div>
    <?php
}

function ims_import_records_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ims_imports';

    $date_from = isset($_GET['ims_date_from']) ? sanitize_text_field($_GET['ims_date_from']) : '';
    $date_to   = isset($_GET['ims_date_to']) ? sanitize_text_field($_GET['ims_date_to']) : '';
    $product   = isset($_GET['ims_product']) ? sanitize_text_field($_GET['ims_product']) : '';

    $where = 'WHERE 1=1';
    $params = array();

    if ($date_from !== '') { $where .= " AND DATE(date_created) >= %s"; $params[] = $date_from; }
    if ($date_to !== '')   { $where .= " AND DATE(date_created) <= %s"; $params[] = $date_to; }
    if ($product !== '')   { $where .= " AND product = %s";             $params[] = $product; }

    $sql = "SELECT id, product, quantity, staff_name, date_created, timestamp_created, processed FROM {$table} {$where} ORDER BY date_created DESC LIMIT 500";
    $rows = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

    echo '<div class="wrap"><h1><iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Import Records</h1>';
    ims_render_filters_and_delete_filtered('imports', 'ims-import-records', 'Product', 'ims_product');

    if ($wpdb->last_error) {
        echo '<div class="notice notice-error"><p>' . esc_html($wpdb->last_error) . '</p></div>';
    }

    echo '<form method="post" onsubmit="return confirm(\'Delete selected records permanently?\');">';
    echo '<input type="hidden" name="ims_action" value="bulk_delete">';
    echo '<input type="hidden" name="table_key" value="imports">';
    wp_nonce_field("ims_bulk_delete_imports", 'ims_bulk_delete_nonce');

    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<td style="width:20px;"><input type="checkbox" onclick="jQuery(\'.ims-row-check\').prop(\'checked\', this.checked);"></td>';
    echo '<th>ID</th><th>Product</th><th>Quantity</th><th>Staff</th><th>Date</th><th>Time</th><th>Processed</th><th>Actions</th>';
    echo '</tr></thead><tbody>';

    if ($rows) {
        foreach ($rows as $r) {
            $delete_url = wp_nonce_url(ims_admin_url('ims-import-records', array(
                'ims_action' => 'delete_row',
                'table_key'  => 'imports',
                'id'         => $r->id,
                'cascade'    => '1'
            )), "ims_delete_row_imports_{$r->id}");

            echo '<tr>';
            echo '<td><input type="checkbox" class="ims-row-check" name="selected_ids[]" value="' . esc_attr($r->id) . '"></td>';
            echo '<td>' . esc_html($r->id) . '</td>';
            echo '<td>' . esc_html($r->product) . '</td>';
            echo '<td>' . number_format(max(0.0,(float)$r->quantity), 2) . '</td>';
            echo '<td>' . esc_html($r->staff_name) . '</td>';
            echo '<td>' . esc_html(date('Y-m-d', strtotime($r->date_created))) . '</td>';
            echo '<td>' . esc_html(date('H:i:s', strtotime($r->timestamp_created))) . '</td>';
            echo '<td>' . ($r->processed ? 'Yes' : 'No') . '</td>';
            echo '<td><a href="' . esc_url($delete_url) . '" class="button-link delete">Delete (cascade)</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="9"><em>No records</em></td></tr>';
    }

    echo '</tbody></table>';
    echo '<p><label><input type="checkbox" name="ims_also_delete_linked" value="1"> Also delete linked integrations (cascade)</label> ';
    echo '<button class="button button-secondary">Delete Selected</button></p>';
    echo '</form></div>';
}

function ims_stock_records_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ims_stock';

    $date_from = isset($_GET['ims_date_from']) ? sanitize_text_field($_GET['ims_date_from']) : '';
    $date_to   = isset($_GET['ims_date_to']) ? sanitize_text_field($_GET['ims_date_to']) : '';
    $product   = isset($_GET['ims_product']) ? sanitize_text_field($_GET['ims_product']) : '';

    $has_remarks = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'remarks'",
        $table
    ));

    $where = 'WHERE 1=1';
    $params = array();

    if ($date_from !== '') { $where .= " AND DATE(date_created) >= %s"; $params[] = $date_from; }
    if ($date_to !== '')   { $where .= " AND DATE(date_created) <= %s"; $params[] = $date_to; }
    if ($product !== '')   { $where .= " AND product = %s";             $params[] = $product; }

    $cols = "id, product, opening_packs, added_packs, used_packs, closing_packs, staff_name, date_created, timestamp_created" . ($has_remarks ? ", remarks" : "");
    $sql  = "SELECT $cols FROM {$table} {$where} ORDER BY date_created DESC LIMIT 500";
    $rows = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

    echo '<div class="wrap"><h1><iconify-icon icon="solar:chart-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Stock Records</h1>';
    ims_render_filters_and_delete_filtered('stock', 'ims-stock-records', 'Product', 'ims_product');

    if ($wpdb->last_error) {
        echo '<div class="notice notice-error"><p>' . esc_html($wpdb->last_error) . '</p></div>';
    }

    echo '<form method="post" onsubmit="return confirm(\'Delete selected records permanently?\');">';
    echo '<input type="hidden" name="ims_action" value="bulk_delete">';
    echo '<input type="hidden" name="table_key" value="stock">';
    wp_nonce_field("ims_bulk_delete_stock", 'ims_bulk_delete_nonce');

    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<td style="width:20px;"><input type="checkbox" onclick="jQuery(\'.ims-row-check\').prop(\'checked\', this.checked);"></td>';
    echo '<th>ID</th><th>Product</th><th>Opening</th><th>Added</th><th>Used</th><th>Closing</th><th>Staff</th><th>Date</th><th>Time</th>' . ($has_remarks ? '<th>Remarks</th>' : '') . '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    if ($rows) {
        foreach ($rows as $r) {
            $delete_url = wp_nonce_url(ims_admin_url('ims-stock-records', array(
                'ims_action' => 'delete_row',
                'table_key'  => 'stock',
                'id'         => $r->id,
                'cascade'    => '1'
            )), "ims_delete_row_stock_{$r->id}");

            echo '<tr>';
            echo '<td><input type="checkbox" class="ims-row-check" name="selected_ids[]" value="' . esc_attr($r->id) . '"></td>';
            echo '<td>' . esc_html($r->id) . '</td>';
            echo '<td>' . esc_html($r->product) . '</td>';
            echo '<td>' . number_format(max(0.0,(float)$r->opening_packs), 2) . '</td>';
            echo '<td>' . number_format(max(0.0,(float)$r->added_packs), 2) . '</td>';
            echo '<td>' . number_format(max(0.0,(float)$r->used_packs), 2) . '</td>';
            echo '<td>' . number_format(max(0.0,(float)$r->closing_packs), 2) . '</td>';
            echo '<td>' . esc_html($r->staff_name) . '</td>';
            echo '<td>' . esc_html(date('Y-m-d', strtotime($r->date_created))) . '</td>';
            echo '<td>' . esc_html(date('H:i:s', strtotime($r->timestamp_created))) . '</td>';
            if ($has_remarks) {
                $remarks = isset($r->remarks) ? trim((string)$r->remarks) : '';
                echo '<td>' . ($remarks !== '' ? nl2br(esc_html($remarks)) : '<em>No remarks</em>') . '</td>';
            }
            echo '<td><a href="' . esc_url($delete_url) . '" class="button-link delete">Delete (cascade)</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="' . ($has_remarks ? 12 : 11) . '"><em>No records</em></td></tr>';
    }

    echo '</tbody></table>';
    echo '<p><label><input type="checkbox" name="ims_also_delete_linked" value="1"> Also delete linked integrations (cascade)</label> ';
    echo '<button class="button button-secondary">Delete Selected</button></p>';
    echo '</form></div>';
}

function ims_chopped_records_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ims_chopped';

    $date_from = isset($_GET['ims_date_from']) ? sanitize_text_field($_GET['ims_date_from']) : '';
    $date_to   = isset($_GET['ims_date_to']) ? sanitize_text_field($_GET['ims_date_to']) : '';
    $fruit     = isset($_GET['ims_fruit']) ? sanitize_text_field($_GET['ims_fruit']) : '';

    $has_remarks = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'remarks'",
        $table
    ));

    $where = 'WHERE 1=1';
    $params = array();

    if ($date_from !== '') { $where .= " AND DATE(date_created) >= %s"; $params[] = $date_from; }
    if ($date_to !== '')   { $where .= " AND DATE(date_created) <= %s"; $params[] = $date_to; }
    if ($fruit !== '')     { $where .= " AND fruit = %s";               $params[] = $fruit; }

    $cols = "id, fruit, opening_whole, import_whole, prepared_whole, closing_whole, packs_gotten, staff_name, date_created, timestamp_created" . ($has_remarks ? ", remarks" : "");
    $sql  = "SELECT $cols FROM {$table} {$where} ORDER BY date_created DESC LIMIT 500";
    $rows = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

    echo '<div class="wrap"><h1><iconify-icon icon="solar:scissors-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Chopped Records</h1>';
    ims_render_filters_and_delete_filtered('chopped', 'ims-chopped-records', 'Fruit', 'ims_fruit');

    if ($wpdb->last_error) {
        echo '<div class="notice notice-error"><p>' . esc_html($wpdb->last_error) . '</p></div>';
    }

    echo '<form method="post" onsubmit="return confirm(\'Delete selected records permanently?\');">';
    echo '<input type="hidden" name="ims_action" value="bulk_delete">';
    echo '<input type="hidden" name="table_key" value="chopped">';
    wp_nonce_field("ims_bulk_delete_chopped", 'ims_bulk_delete_nonce');

    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<td style="width:20px;"><input type="checkbox" onclick="jQuery(\'.ims-row-check\').prop(\'checked\', this.checked);"></td>';
    echo '<th>ID</th><th>Fruit</th><th>Opening</th><th>Import</th><th>Prepared</th><th>Closing</th><th>Packs</th><th>Staff</th><th>Date</th><th>Time</th>' . ($has_remarks ? '<th>Remarks</th>' : '') . '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    if ($rows) {
        foreach ($rows as $r) {
            $delete_url = wp_nonce_url(ims_admin_url('ims-chopped-records', array(
                'ims_action' => 'delete_row',
                'table_key'  => 'chopped',
                'id'         => $r->id,
                'cascade'    => '1'
            )), "ims_delete_row_chopped_{$r->id}");

            echo '<tr>';
            echo '<td><input type="checkbox" class="ims-row-check" name="selected_ids[]" value="' . esc_attr($r->id) . '"></td>';
            echo '<td>' . esc_html($r->id) . '</td>';
            echo '<td><iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> <strong>' . esc_html($r->fruit) . '</strong></td>';
            echo '<td>' . number_format(max(0.0,(float)$r->opening_whole), 2) . '</td>';
            echo '<td style="color:#28a745;">+' . number_format(max(0.0,(float)$r->import_whole), 2) . '</td>';
            echo '<td style="color:#dc3545;">' . number_format(max(0.0,(float)$r->prepared_whole), 2) . '</td>';
            echo '<td>' . number_format(max(0.0,(float)$r->closing_whole), 2) . '</td>';
            echo '<td style="color:#FF0000;">' . number_format(max(0.0,(float)$r->packs_gotten), 2) . '</td>';
            echo '<td>' . esc_html($r->staff_name) . '</td>';
            echo '<td>' . esc_html(date('Y-m-d', strtotime($r->date_created))) . '</td>';
            echo '<td>' . esc_html(date('H:i:s', strtotime($r->timestamp_created))) . '</td>';
            if ($has_remarks) {
                $remarks = isset($r->remarks) ? trim((string)$r->remarks) : '';
                echo '<td>' . ($remarks !== '' ? nl2br(esc_html($remarks)) : '<em>No remarks</em>') . '</td>';
            }
            echo '<td><a href="' . esc_url($delete_url) . '" class="button-link delete">Delete (cascade)</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="' . ($has_remarks ? 13 : 12) . '"><em>No records</em></td></tr>';
    }

    echo '</tbody></table>';
    echo '<p><label><input type="checkbox" name="ims_also_delete_linked" value="1"> Also delete linked integrations (cascade)</label> ';
    echo '<button class="button button-secondary">Delete Selected</button></p>';
    echo '</form></div>';
}

/* =========================
   Database functions
   ========================= */
function ims_create_database_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $table_products = $wpdb->prefix . 'ims_products';
    $sql_products = "CREATE TABLE $table_products (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        type enum('all','chopped') DEFAULT 'all',
        is_active tinyint(1) DEFAULT 1,
        sort_order int(11) DEFAULT 0,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY idx_name_type (name, type),
        KEY idx_type (type),
        KEY idx_active (is_active)
    ) $charset_collate;";

    $table_import = $wpdb->prefix . 'ims_imports';
    $sql_import = "CREATE TABLE $table_import (
        id int(11) NOT NULL AUTO_INCREMENT,
        product varchar(255) NOT NULL,
        quantity decimal(10,2) NOT NULL,
        staff_name varchar(255) NOT NULL,
        date_created datetime NOT NULL,
        timestamp_created datetime NOT NULL,
        processed tinyint(1) DEFAULT 0,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_product (product),
        KEY idx_date (date_created),
        KEY idx_processed (processed)
    ) $charset_collate;";

    $table_stock = $wpdb->prefix . 'ims_stock';
    $sql_stock = "CREATE TABLE $table_stock (
        id int(11) NOT NULL AUTO_INCREMENT,
        product varchar(255) NOT NULL,
        opening_packs decimal(10,2) DEFAULT 0,
        added_packs decimal(10,2) DEFAULT 0,
        used_packs decimal(10,2) DEFAULT 0,
        closing_packs decimal(10,2) DEFAULT 0,
        staff_name varchar(255) NOT NULL,
        date_created datetime NOT NULL,
        timestamp_created datetime NOT NULL,
        remarks text,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_product (product),
        KEY idx_date (date_created)
    ) $charset_collate;";

    $table_chopped = $wpdb->prefix . 'ims_chopped';
    $sql_chopped = "CREATE TABLE $table_chopped (
        id int(11) NOT NULL AUTO_INCREMENT,
        fruit varchar(255) NOT NULL,
        opening_whole decimal(10,2) DEFAULT 0,
        import_whole decimal(10,2) DEFAULT 0,
        prepared_whole decimal(10,2) DEFAULT 0,
        closing_whole decimal(10,2) DEFAULT 0,
        packs_gotten decimal(10,2) DEFAULT 0,
        remarks text DEFAULT NULL,
        staff_name varchar(255) NOT NULL,
        date_created datetime NOT NULL,
        timestamp_created datetime NOT NULL,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_fruit (fruit),
        KEY idx_date (date_created)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_products);
    dbDelta($sql_import);
    dbDelta($sql_stock);
    dbDelta($sql_chopped);
}

function ims_populate_default_products() {
    global $wpdb;

    ims_create_database_tables();

    $products_table = $wpdb->prefix . 'ims_products';

    // Only populate if table is empty — never truncate user data
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $products_table");
    if ($count > 0) {
        return;
    }

    $all_products = array(
        'Almond', 'Apple', 'Baking Powder', 'Banana', 'Blueberry', 'Cake',
        'Caramel/Chocolate Syrup', 'Cashew Nut', 'Cherry', 'Chia Seed',
        'Cinnamon', 'Cocoa Powder', 'Coconut Flakes', 'Coffee', 'Condensed Milk',
        'Cucumber', 'Dates', 'Egg', 'Evaporated Milk', 'Fresh Coconut',
        'Ginger', 'Granola', 'Grape', 'Groundnuts', 'Honey', 'Ice Cream',
        'Kiwi', 'Lemon', 'Lime', 'Maca Powder', 'Nut Packed', 'Nutri Choco',
        'Oat', 'Oranges', 'Paw Paw', 'Peanut Butter', 'Peanuts', 'Pineapple',
        'Powdered Milk', 'Pumpkin Seed', 'Raisin', 'Rapha Yoghurt', 'Strawberry',
        'Sugar', 'Sunflower Seed', 'Tiger Nut', 'Watermelon', 'Whey Protein'
    );

    $chopped_products = array(
        'Cucumber', 'Dates', 'Fresh Coconut', 'Ginger', 'Grape',
        'Ice Cream', 'Kiwi', 'Lemon', 'Lime', 'Paw Paw', 'Pineapple',
        'Tiger Nut', 'Watermelon'
    );

    // Insert each product once with the correct type
    foreach ($all_products as $index => $product) {
        $type = in_array($product, $chopped_products, true) ? 'chopped' : 'all';
        $wpdb->insert(
            $products_table,
            array(
                'name' => $product,
                'type' => $type,
                'is_active' => 1,
                'sort_order' => $index + 1
            ),
            array('%s', '%s', '%d', '%d')
        );
    }
}

/* =========================
   Data helpers for forms
   ========================= */
function ims_get_products($type = 'all') {
    global $wpdb;

    $products_table = $wpdb->prefix . 'ims_products';

    if ($type === 'chopped') {
        $results = $wpdb->get_results(
            "SELECT DISTINCT name FROM $products_table WHERE type = 'chopped' AND is_active = 1 ORDER BY sort_order ASC, name ASC"
        );
    } else {
        $results = $wpdb->get_results(
            "SELECT DISTINCT name FROM $products_table WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
        );
    }

    if (!empty($results)) {
        return array_values(array_unique(array_column($results, 'name')));
    }

    $all_products = array(
        'Almond', 'Apple', 'Baking Powder', 'Banana', 'Blueberry', 'Cake',
        'Caramel/Chocolate Syrup', 'Cashew Nut', 'Cherry', 'Chia Seed',
        'Cinnamon', 'Cocoa Powder', 'Coconut Flakes', 'Coffee', 'Condensed Milk',
        'Cucumber', 'Dates', 'Egg', 'Evaporated Milk', 'Fresh Coconut',
        'Ginger', 'Granola', 'Grape', 'Groundnuts', 'Honey', 'Ice Cream',
        'Kiwi', 'Lemon', 'Lime', 'Maca Powder', 'Nut Packed', 'Nutri Choco',
        'Oat', 'Oranges', 'Paw Paw', 'Peanut Butter', 'Peanuts', 'Pineapple',
        'Powdered Milk', 'Pumpkin Seed', 'Raisin', 'Rapha Yoghurt', 'Strawberry',
        'Sugar', 'Sunflower Seed', 'Tiger Nut', 'Watermelon', 'Whey Protein'
    );

    $chopped_products = array(
        'Cucumber', 'Dates', 'Fresh Coconut', 'Ginger', 'Grape',
        'Ice Cream', 'Kiwi', 'Lemon', 'Lime', 'Paw Paw', 'Pineapple',
        'Tiger Nut', 'Watermelon'
    );

    return ($type === 'chopped') ? $chopped_products : $all_products;
}

/* =========================
   Integration functions for live totals
   ========================= */
function ims_get_today_import_total($product) {
    global $wpdb;

    $lagos_time = ims_get_lagos_time();
    $today = date('Y-m-d', strtotime($lagos_time));

    if (ims_is_fruit($product)) {
        $chopped_table = $wpdb->prefix . 'ims_chopped';
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT import_whole FROM $chopped_table 
             WHERE fruit = %s AND DATE(date_created) = %s 
             ORDER BY id DESC LIMIT 1",
            $product, $today
        ));
        return max(0.0, floatval($result));
    } else {
        $imports_table = $wpdb->prefix . 'ims_imports';
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantity) FROM $imports_table 
             WHERE product = %s AND DATE(date_created) = %s",
            $product, $today
        ));
        return max(0.0, floatval($result));
    }
}

function ims_get_today_chopped_packs_total($fruit) {
    global $wpdb;

    $chopped_table = $wpdb->prefix . 'ims_chopped';
    $today = date('Y-m-d', strtotime(ims_get_lagos_time()));

    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(packs_gotten) FROM $chopped_table 
         WHERE fruit = %s AND DATE(date_created) = %s",
        $fruit, $today
    ));

    return max(0.0, ($total ? floatval($total) : 0));
}

/* PERSISTENCE FIX: keep last known values even if no inputs today */
function ims_get_today_stock_data($product) {
    global $wpdb;

    $stock_table = $wpdb->prefix . 'ims_stock';
    $lagos_time = ims_get_lagos_time();
    $today = date('Y-m-d', strtotime($lagos_time));

    $today_data = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $stock_table 
         WHERE product = %s AND DATE(date_created) = %s 
         ORDER BY id DESC LIMIT 1",
        $product, $today
    ));

    if ($today_data) {
        $o = max(0.0, floatval($today_data->opening_packs));
        $a = max(0.0, floatval($today_data->added_packs));
        $u = max(0.0, min(floatval($today_data->used_packs), $o + $a));
        $c = max(0.0, $o + $a - $u);
        return (object) array(
            'opening_packs' => $o,
            'added_packs' => $a,
            'used_packs' => $u,
            'closing_packs' => $c,
            'remarks' => $today_data->remarks ?? ''
        );
    }

    // fallback: last known prior closing
    $opening_packs = ims_get_last_stock_closing_before($product, $today);
    $added_packs = ims_is_fruit($product) ? ims_get_today_chopped_packs_total($product) : ims_get_today_import_total($product);

    return (object) array(
        'opening_packs' => $opening_packs,
        'added_packs' => $added_packs,
        'used_packs' => 0.0,
        'closing_packs' => max(0.0, $opening_packs + $added_packs),
        'remarks' => ''
    );
}

function ims_get_today_chopped_data($fruit) {
    global $wpdb;

    $chopped_table = $wpdb->prefix . 'ims_chopped';
    $lagos_time = ims_get_lagos_time();
    $today = date('Y-m-d', strtotime($lagos_time));

    $today_data = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $chopped_table 
         WHERE fruit = %s AND DATE(date_created) = %s 
         ORDER BY id DESC LIMIT 1",
        $fruit, $today
    ));

    if ($today_data) {
        $o = max(0.0, floatval($today_data->opening_whole));
        $i = max(0.0, floatval($today_data->import_whole));
        $p = max(0.0, min(floatval($today_data->prepared_whole), $o + $i));
        $c = max(0.0, $o + $i - $p);
        $pg= max(0.0, floatval($today_data->packs_gotten));
        return (object) array(
            'opening_whole' => $o,
            'import_whole' => $i,
            'prepared_whole' => $p,
            'closing_whole' => $c,
            'packs_gotten' => $pg,
            'remarks' => $today_data->remarks ?? ''
        );
    }

    // fallback: last known prior closing
    $opening_whole = ims_get_last_chopped_closing_before($fruit, $today);
    $import_whole = ims_get_today_import_total($fruit);

    return (object) array(
        'opening_whole' => $opening_whole,
        'import_whole' => $import_whole,
        'prepared_whole' => 0.0,
        'closing_whole' => max(0.0, $opening_whole + $import_whole),
        'packs_gotten' => 0.0,
        'remarks' => ''
    );
}

// INTEGRATION HELPER: Update stock form added packs field (generic, used by imports)
function ims_update_stock_added_field($product, $quantity, $current_user, $lagos_time, $today) {
    global $wpdb;

    $stock_table = $wpdb->prefix . 'ims_stock';

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $stock_table WHERE product = %s AND DATE(date_created) = %s ORDER BY id DESC LIMIT 1",
        $product, $today
    ));

    if ($existing) {
        $opening = max(0.0, floatval($existing->opening_packs));
        $prev_added = max(0.0, floatval($existing->added_packs));
        $new_added_packs = max(0.0, $prev_added + floatval($quantity));
        $used = max(0.0, min(floatval($existing->used_packs), $opening + $new_added_packs));
        $new_closing_packs = max(0.0, $opening + $new_added_packs - $used);

        $wpdb->update(
            $stock_table,
            array(
                'added_packs' => $new_added_packs,
                'used_packs' => $used,
                'closing_packs' => $new_closing_packs,
                'timestamp_created' => $lagos_time
            ),
            array('id' => $existing->id),
            array('%f', '%f', '%f', '%s'),
            array('%d')
        );
    } else {
        // opening from last known value (not strictly yesterday)
        $opening_packs = ims_get_last_stock_closing_before($product, $today);
        $added = max(0.0, floatval($quantity));
        $closing_packs = max(0.0, $opening_packs + $added);

        $wpdb->insert(
            $stock_table,
            array(
                'product' => $product,
                'opening_packs' => $opening_packs,
                'added_packs' => $added,
                'used_packs' => 0,
                'closing_packs' => $closing_packs,
                'staff_name' => $current_user->display_name,
                'date_created' => $lagos_time,
                'timestamp_created' => $lagos_time
            ),
            array('%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s')
        );
    }
}

function ims_sync_chopped_packs_to_stock($fruit, $delta_quantity, $current_user, $lagos_time, $today) {
    if (abs(floatval($delta_quantity)) < 0.00001) {
        return;
    }
    ims_update_stock_added_field($fruit, $delta_quantity, $current_user, $lagos_time, $today);
}

/* =========================
   Shortcodes (rendering)
   ========================= */

// STOCK FORM SHORTCODE (with typing UX fix for Used Packs)
add_shortcode('ims_stock_form', function($atts) {
    $products = ims_get_products('all');
    $current_user = wp_get_current_user();
    $lagos_time = ims_get_lagos_time();
    $is_admin = ims_user_can_edit_all_fields();
    $is_staff = ims_is_staff_user();

    if (!is_user_logged_in()) {
        return '<div class="ims-message error">Please log in to access the stock form.</div>';
    }

    ob_start();
    ?>
    <div class="ims-container" style="background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);">
        <div class="ims-form-header" style="background:linear-gradient(135deg,#ffffff 0%,#fff8f8 100%);">
            <h2 class="ims-form-title">Stock Form</h2>
            <p class="ims-subtitle">Manage opening and closing stock levels for all <?php echo count($products); ?> products</p>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
                <p class="ims-time">Current Time: <?php echo esc_html($lagos_time); ?> (Lagos) | User: <?php echo esc_html($current_user->display_name); ?></p>
                <div style="background:<?php echo $is_admin ? '#d4edda' : '#fff3cd'; ?>;color:<?php echo $is_admin ? '#155724' : '#856404'; ?>;padding:8px 16px;border-radius:20px;font-weight:bold;">
                    <?php echo $is_admin ? '<iconify-icon icon="solar:crown-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> ADMIN - Full Access' : '<iconify-icon icon="solar:user-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> STAFF - Used Packs Only'; ?>
                </div>
            </div>
        </div>

        <form class="ims-form" method="post" id="ims-stock-form">
            <?php wp_nonce_field('ims_stock_form', 'ims_stock_nonce'); ?>

            <div class="ims-table-container" style="max-height:600px;overflow-y:auto;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
                <table class="ims-table" style="width:100%;border-collapse:collapse;">
                    <thead style="position:sticky;top:0;z-index:10;background:linear-gradient(135deg,#FF0000 0%,#cc0000 100%);color:#fff;">
                        <tr>
                            <th style="padding:10px;text-align:left;">Product</th>
                            <th style="padding:10px;text-align:left;">Opening Packs <?php echo $is_admin ? '<iconify-icon icon="solar:pen-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>' : '<iconify-icon icon="solar:lock-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>'; ?></th>
                            <th style="padding:10px;text-align:left;">Added Packs (Auto) <iconify-icon icon="solar:lock-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon></th>
                            <th style="padding:10px;text-align:left;">Used Packs <?php echo $is_staff ? '<iconify-icon icon="solar:pen-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>' : ($is_admin ? '<iconify-icon icon="solar:pen-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>' : ''); ?></th>
                            <th style="padding:10px;text-align:left;">Closing Packs <iconify-icon icon="solar:lock-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product):
                            $stock_data = ims_get_today_stock_data($product);
                            $opening_packs = $stock_data->opening_packs ?? 0.0;
                            $added_packs   = $stock_data->added_packs ?? 0.0;
                            $used_packs    = $stock_data->used_packs ?? 0.0;
                            $closing_packs = max(0.0, $opening_packs + $added_packs - $used_packs);
                        ?>
                        <tr data-product="<?php echo esc_attr($product); ?>" style="border-bottom:1px solid #eee;">
                            <td style="padding:10px;">
                                <strong><?php echo ims_is_fruit($product) ? '<iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>' : '<iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>'; ?> <?php echo esc_html($product); ?></strong>
                                <?php if ($added_packs > 0): ?>
                                    <div style="font-size:.8rem;color:#28a745;font-weight:bold;">+<?php echo number_format($added_packs,2); ?> auto</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;">
                                <input type="number" name="opening_packs[<?php echo esc_attr($product); ?>]"
                                       class="opening-packs"
                                       value="<?php echo esc_attr($opening_packs); ?>"
                                       step="0.01" min="0" <?php echo $is_admin ? '' : 'readonly'; ?>
                                       inputmode="decimal" autocomplete="off">
                            </td>
                            <td style="padding:10px;">
                                <input type="number" class="added-packs"
                                       value="<?php echo esc_attr($added_packs); ?>"
                                       step="0.01" min="0" readonly disabled>
                            </td>
                            <td style="padding:10px;">
                                <input type="number" name="used_packs[<?php echo esc_attr($product); ?>]"
                                       class="used-packs"
                                       value="<?php echo esc_attr($used_packs); ?>"
                                       placeholder="0.00" step="0.01" min="0"
                                       inputmode="decimal" autocomplete="off">
                            </td>
                            <td style="padding:10px;">
                                <input type="number" class="closing-packs"
                                       value="<?php echo esc_attr($closing_packs); ?>"
                                       step="0.01" min="0" readonly disabled>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="text-align:center;padding:20px 0;">
                <button type="submit" style="background:linear-gradient(135deg,#FF0000 0%,#cc0000 100%);color:white;padding:16px 28px;border:none;border-radius:8px;font-weight:bold;">
                    <iconify-icon icon="solar:chart-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> <?php echo $is_admin ? 'Save Stock Data (ADMIN)' : 'Submit Used Packs (STAFF)'; ?>
                </button>
            </div>
        </form>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const clamp = (n) => Math.max(0, isNaN(n) ? 0 : n);

        function recalc(row) {
            const openingEl = row.querySelector('.opening-packs');
            const addedEl   = row.querySelector('.added-packs');
            const usedEl    = row.querySelector('.used-packs');
            const closingEl = row.querySelector('.closing-packs');

            const opening = clamp(parseFloat(openingEl.value));
            const added   = clamp(parseFloat(addedEl.value));
            let used      = clamp(parseFloat(usedEl.value));

            const maxUsed = opening + added;
            if (used > maxUsed) {
                used = maxUsed;
                // Don't format while typing to avoid cursor jump
                usedEl.value = used.toString();
            }

            const closing = opening + added - used;
            closingEl.value = closing.toFixed(2);
        }

        document.querySelectorAll('tr[data-product]').forEach(function(row){
            recalc(row);

            row.querySelectorAll('.opening-packs,.used-packs').forEach(function(inp){
                inp.addEventListener('input', function(){ recalc(row); });
                inp.addEventListener('focus', function(){ this.select(); });
            });

            const usedEl = row.querySelector('.used-packs');
            usedEl.addEventListener('blur', function(){
                const opening = clamp(parseFloat(row.querySelector('.opening-packs').value));
                const added   = clamp(parseFloat(row.querySelector('.added-packs').value));
                let v         = clamp(parseFloat(this.value));
                const maxUsed = opening + added;
                if (v > maxUsed) v = maxUsed;
                this.value = isNaN(v) ? '' : v.toFixed(2);
                recalc(row);
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

// IMPORT FORM SHORTCODE
add_shortcode('ims_import_form', function($atts) {
    $products = ims_get_products('all');
    $current_user = wp_get_current_user();
    $lagos_time = ims_get_lagos_time();

    if (!is_user_logged_in()) {
        return '<div class="ims-message error">Please log in to access the import form.</div>';
    }

    ob_start();
    ?>
    <div class="ims-container" style="background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);">
        <div class="ims-form-header" style="background:linear-gradient(135deg,#ffffff 0%,#fff8f8 100%);">
            <h2 class="ims-form-title">Import Form</h2>
            <p class="ims-time">Current Time: <?php echo esc_html($lagos_time); ?> (Lagos) | User: <?php echo esc_html($current_user->display_name); ?></p>
        </div>

        <form class="ims-form" method="post" id="ims-import-form">
            <?php wp_nonce_field('ims_import_form', 'ims_import_nonce'); ?>
            <div class="ims-table-container" style="max-height:600px;overflow-y:auto;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
                <table class="ims-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="padding:10px;text-align:left;">Product</th>
                            <th style="padding:10px;text-align:left;">Quantity Imported</th>
                            <th style="padding:10px;text-align:left;">Integration Flow</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): $is_fruit = ims_is_fruit($product); ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:10px;"><strong><?php echo $is_fruit ? '<iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> ' : '<iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> '; ?><?php echo esc_html($product); ?></strong></td>
                            <td style="padding:10px;"><input type="number" name="quantity[<?php echo esc_attr($product); ?>]" value="0" step="0.01" min="0" inputmode="decimal" autocomplete="off"></td>
                            <td style="padding:10px;"><?php echo $is_fruit ? '<iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> → Chopped (Import Whole)' : '<iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> → Stock (Added Packs)'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="text-align:center;padding:20px 0;">
                <button type="submit" style="background:linear-gradient(135deg,#28a745 0%,#20a142 100%);color:white;padding:12px 24px;border:none;border-radius:6px;font-weight:bold;">
                    <iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Submit Import Data
                </button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
});

// CHOPPED FORM SHORTCODE (with typing UX fix)
add_shortcode('ims_chopped_form', function($atts) {
    $fruits = ims_get_products('chopped');
    $current_user = wp_get_current_user();
    $lagos_time = ims_get_lagos_time();
    $is_admin = ims_user_can_edit_all_fields();
    $is_staff = ims_is_staff_user();

    if (!is_user_logged_in()) {
        return '<div class="ims-message error">Please log in to access the chopped form.</div>';
    }

    ob_start();
    ?>
    <div class="ims-container" style="background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);">
        <div class="ims-form-header" style="background:linear-gradient(135deg,#ffffff 0%,#fff8f8 100%);">
            <h2 class="ims-form-title">Chopped Form</h2>
            <p class="ims-subtitle">Manage chopped/prepared fruits with role-based permissions & remarks</p>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
                <p class="ims-time">Current Time: <?php echo esc_html($lagos_time); ?> (Lagos) | User: <?php echo esc_html($current_user->display_name); ?></p>
                <div style="background:<?php echo $is_admin ? '#d4edda' : '#fff3cd'; ?>;color:<?php echo $is_admin ? '#155724' : '#856404'; ?>;padding:8px 16px;border-radius:20px;font-weight:bold;">
                    <?php echo $is_admin ? '<iconify-icon icon="solar:crown-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> ADMIN - Full Access' : '<iconify-icon icon="solar:user-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> STAFF - Prepared, Packs & Remarks'; ?>
                </div>
            </div>
        </div>

        <form class="ims-form" method="post" id="ims-chopped-form">
            <?php wp_nonce_field('ims_chopped_form', 'ims_chopped_nonce'); ?>
            <div class="ims-table-container" style="max-height:600px;overflow-y:auto;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
                <table class="ims-table" style="width:100%;border-collapse:collapse;">
                    <thead style="position:sticky;top:0;z-index:10;background:linear-gradient(135deg,#FF0000 0%,#cc0000 100%);color:#fff;">
                        <tr>
                            <th style="padding:10px;text-align:left;">Fruit</th>
                            <th style="padding:10px;text-align:left;">Opening (Whole) <?php echo $is_admin ? '<iconify-icon icon="solar:pen-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>' : '<iconify-icon icon="solar:lock-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>'; ?></th>
                            <th style="padding:10px;text-align:left;">Import (Whole) <iconify-icon icon="solar:lock-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon></th>
                            <th style="padding:10px;text-align:left;">Prepared (Whole) <?php echo $is_staff ? '<iconify-icon icon="solar:pen-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>' : ''; ?></th>
                            <th style="padding:10px;text-align:left;">Closing (Whole) <iconify-icon icon="solar:lock-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon></th>
                            <th style="padding:10px;text-align:left;">Pack(s) Gotten <?php echo $is_staff ? '<iconify-icon icon="solar:pen-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>' : ''; ?></th>
                            <th style="padding:10px;text-align:left;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fruits as $fruit):
                            $ch = ims_get_today_chopped_data($fruit);
                            $opening_whole = $ch->opening_whole ?? 0.0;
                            $import_whole  = $ch->import_whole ?? 0.0;
                            $prepared_whole= $ch->prepared_whole ?? 0.0;
                            $packs_gotten  = $ch->packs_gotten ?? 0.0;
                            $remarks       = $ch->remarks ?? '';
                            $closing_whole = max(0.0, $opening_whole + $import_whole - $prepared_whole);
                        ?>
                        <tr data-fruit="<?php echo esc_attr($fruit); ?>" style="border-bottom:1px solid #eee;">
                            <td style="padding:10px;"><strong style="color:#FF0000;"><iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> <?php echo esc_html($fruit); ?></strong></td>
                            <td style="padding:10px;"><input type="number" name="opening_whole[<?php echo esc_attr($fruit); ?>]" class="opening-whole" value="<?php echo esc_attr($opening_whole); ?>" step="0.01" min="0" <?php echo $is_admin ? '' : 'readonly'; ?> inputmode="decimal" autocomplete="off"></td>
                            <td style="padding:10px;"><input type="number" class="import-whole" value="<?php echo esc_attr($import_whole); ?>" step="0.01" min="0" readonly></td>
                            <td style="padding:10px;"><input type="number" name="prepared_whole[<?php echo esc_attr($fruit); ?>]" class="prepared-whole" value="<?php echo esc_attr($prepared_whole); ?>" placeholder="0.00" step="0.01" min="0" inputmode="decimal" autocomplete="off"></td>
                            <td style="padding:10px;"><input type="number" class="closing-whole" value="<?php echo esc_attr($closing_whole); ?>" step="0.01" min="0" readonly></td>
                            <td style="padding:10px;"><input type="number" name="packs_gotten[<?php echo esc_attr($fruit); ?>]" class="packs-gotten" value="<?php echo esc_attr($packs_gotten); ?>" placeholder="0.00" step="0.01" min="0" inputmode="decimal" autocomplete="off"></td>
                            <td style="padding:10px;"><textarea name="remarks[<?php echo esc_attr($fruit); ?>]" rows="2" placeholder="<?php echo $is_staff ? 'Add notes/remarks...' : 'Enter remarks...'; ?>"><?php echo esc_textarea($remarks); ?></textarea></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="text-align:center;padding:20px 0;">
                <button type="submit" style="background:linear-gradient(135deg,#FF0000 0%,#cc0000 100%);color:white;padding:16px 28px;border:none;border-radius:8px;font-weight:bold;">
                    <iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> <?php echo $is_admin ? 'Save Chopped Data (ADMIN)' : 'Submit Prepared, Packs & Remarks (STAFF)'; ?>
                </button>
            </div>
        </form>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const clamp = (n) => Math.max(0, isNaN(n) ? 0 : n);

        function calc(row) {
            const openEl = row.querySelector('.opening-whole');
            const impEl  = row.querySelector('.import-whole');
            const prepEl = row.querySelector('.prepared-whole');
            const closeEl= row.querySelector('.closing-whole');

            const opening  = clamp(parseFloat(openEl.value));
            const imported = clamp(parseFloat(impEl.value));
            let prepared   = clamp(parseFloat(prepEl.value));

            const maxPrepared = opening + imported;
            if (prepared > maxPrepared) {
                prepared = maxPrepared;
                // Avoid formatting while typing to not block deletion
                prepEl.value = prepared.toString();
            }

            const closing = opening + imported - prepared;
            closeEl.value = closing.toFixed(2);
        }

        document.querySelectorAll('tr[data-fruit]').forEach(function(row){
            calc(row);

            row.querySelectorAll('.opening-whole,.prepared-whole').forEach(function(inp){
                inp.addEventListener('input', function(){ calc(row); });
                inp.addEventListener('focus', function(){ this.select(); });
            });

            const prepEl = row.querySelector('.prepared-whole');
            prepEl.addEventListener('blur', function(){
                const open = clamp(parseFloat(row.querySelector('.opening-whole').value));
                const imp  = clamp(parseFloat(row.querySelector('.import-whole').value));
                let v      = clamp(parseFloat(this.value));
                const maxP = open + imp;
                if (v > maxP) v = maxP;
                this.value = isNaN(v) ? '' : v.toFixed(2);
                calc(row);
            });

            const packsEl = row.querySelector('.packs-gotten');
            if (packsEl) {
                packsEl.addEventListener('focus', function(){ this.select(); });
                packsEl.addEventListener('blur', function(){
                    let v = clamp(parseFloat(this.value));
                    this.value = isNaN(v) ? '' : v.toFixed(2);
                });
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

/* =========================
   Role-based permissions
   ========================= */
function ims_user_can_edit_all_fields() {
    return current_user_can('administrator') || current_user_can('manage_options');
}
function ims_is_staff_user() {
    return is_user_logged_in() && !ims_user_can_edit_all_fields();
}
function ims_user_can_submit_forms() {
    if (!is_user_logged_in()) return false;
    return current_user_can('read') || current_user_can('edit_posts') || current_user_can('manage_options');
}

/* =========================
   Form submission handlers (traditional POST only — skip during AJAX)
   ========================= */
add_action('init', function() {
    if (wp_doing_ajax()) return; // AJAX requests are handled by IMS_Ajax class
    if ($_POST && isset($_POST['ims_stock_nonce']) && wp_verify_nonce($_POST['ims_stock_nonce'], 'ims_stock_form')) {
        ims_handle_stock_submission();
    }
});
add_action('init', function() {
    if (wp_doing_ajax()) return; // AJAX requests are handled by IMS_Ajax class
    if ($_POST && isset($_POST['ims_import_nonce']) && wp_verify_nonce($_POST['ims_import_nonce'], 'ims_import_form')) {
        ims_handle_import_submission();
    }
});
add_action('init', function() {
    if (wp_doing_ajax()) return; // AJAX requests are handled by IMS_Ajax class
    if ($_POST && isset($_POST['ims_chopped_nonce']) && wp_verify_nonce($_POST['ims_chopped_nonce'], 'ims_chopped_form')) {
        ims_handle_chopped_submission();
    }
});

/* =========================
   STOCK submission: non-negative + capping + persistence baseline
   ========================= */
function ims_handle_stock_submission() {
    if (!ims_user_can_submit_forms()) {
        wp_die('Unauthorized access - you do not have permission to submit this form.');
    }

    global $wpdb;
    // The active stock form still posts legacy field names (`opening[]`, `used[]`).
    // Accept both legacy and canonical field names while the templates converge.
    $opening_values = ims_extract_post_array_variants('opening_packs');
    $used_values    = ims_extract_post_array_variants('used_packs');
    $current_user   = wp_get_current_user();
    $lagos_time     = ims_get_lagos_time();
    $today          = date('Y-m-d', strtotime($lagos_time));
    $stock_table    = $wpdb->prefix . 'ims_stock';
    $is_admin       = ims_user_can_edit_all_fields();
    $eps            = 1e-6;

    // Process ALL products — do not filter by role for the list
    $products = ims_get_products('all');
    $saved = 0;

    foreach ($products as $product) {
        $product = (string)$product;
        if ($product === '') continue;

        // Baseline: existing today, else last known closing before today
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $stock_table WHERE product = %s AND DATE(date_created) = %s ORDER BY id DESC LIMIT 1",
            $product, $today
        ));

        if ($existing) {
            $base_open  = max(0.0, floatval($existing->opening_packs));
            $base_added = max(0.0, floatval($existing->added_packs));
            $base_used  = max(0.0, floatval($existing->used_packs));
        } else {
            $base_open  = ims_get_last_stock_closing_before($product, $today);
            $base_added = ims_is_fruit($product) ? ims_get_today_chopped_packs_total($product) : ims_get_today_import_total($product);
            $base_used  = 0.0;
        }

        $open_in = isset($opening_values[$product]) ? max(0.0, floatval($opening_values[$product])) : $base_open;
        $used_in = isset($used_values[$product])    ? max(0.0, floatval($used_values[$product]))    : 0.0;

        // For non-admin: if no form value submitted and no existing record, skip this product
        if (!$is_admin && !isset($used_values[$product]) && !$existing) {
            continue;
        }

        // Admin can set opening from form; staff always gets it from database
        if ($is_admin && isset($opening_values[$product])) {
            $final_open = max(0.0, floatval($opening_values[$product]));
        } else {
            $final_open = $base_open;
        }
        $final_added = $base_added;

        if ($is_admin) {
            $final_used = min($used_in, $final_open + $final_added);
        } else {
            $final_used = min($base_used + $used_in, $final_open + $final_added);
        }
        $final_used  = max(0.0, $final_used);
        $final_close = max(0.0, $final_open + $final_added - $final_used);

        $data = array(
            'opening_packs'     => $final_open,
            'added_packs'       => $final_added,
            'used_packs'        => $final_used,
            'closing_packs'     => $final_close,
            'staff_name'        => $current_user->display_name,
            'timestamp_created' => $lagos_time
        );

        if ($existing) {
            $res = $wpdb->update($stock_table, $data, array('id' => $existing->id), array('%f','%f','%f','%f','%s','%s'), array('%d'));
        } else {
            $data['product']      = $product;
            $data['date_created'] = $lagos_time;
            $res = $wpdb->insert($stock_table, $data, array('%s','%f','%f','%f','%f','%s','%s','%s'));
        }

        if ($res !== false) $saved++;
    }

    if ($saved > 0) {
        $flag = $is_admin ? 'stock_admin' : 'stock_staff';
        $redirect_url = wp_get_raw_referer();
        if (!$redirect_url && isset($_SERVER['REQUEST_URI'])) {
            $redirect_url = home_url(remove_query_arg(array('ims_success', 'ims_error'), wp_unslash($_SERVER['REQUEST_URI'])));
        }
        if (!$redirect_url) {
            $redirect_url = home_url('/');
        }
        wp_redirect(add_query_arg(array('ims_success' => $flag, 'count' => $saved), $redirect_url)); exit;
    } else {
        $redirect_url = wp_get_raw_referer();
        if (!$redirect_url && isset($_SERVER['REQUEST_URI'])) {
            $redirect_url = home_url(remove_query_arg(array('ims_success', 'ims_error'), wp_unslash($_SERVER['REQUEST_URI'])));
        }
        if (!$redirect_url) {
            $redirect_url = home_url('/');
        }
        wp_redirect(add_query_arg('ims_error', 'stock_no_data', $redirect_url)); exit;
    }
}

/* =========================
   IMPORT submission: non-negative + integration
   ========================= */
function ims_handle_import_submission() {
    if (!ims_user_can_submit_forms()) {
        wp_die('Unauthorized access - you do not have permission to submit this form.');
    }

    global $wpdb;
    // Support both single-product form (scalar product + quantity) and batch form (quantity[product] array)
    if (isset($_POST['quantity']) && is_array($_POST['quantity'])) {
        $quantities = $_POST['quantity'];
    } elseif (isset($_POST['product']) && isset($_POST['quantity']) && !is_array($_POST['quantity'])) {
        $product_name = sanitize_text_field($_POST['product']);
        $qty_val = floatval($_POST['quantity']);
        $quantities = ($product_name !== '' && $qty_val > 0) ? array($product_name => $qty_val) : array();
    } else {
        $quantities = array();
    }
    $current_user = wp_get_current_user();
    $lagos_time   = ims_get_lagos_time();
    $today        = date('Y-m-d', strtotime($lagos_time));
    $saved = 0; $total_qty = 0.0;

    foreach ($quantities as $product => $q) {
        $q = max(0.0, floatval($q));
        if ($q <= 0) continue;

        $ins = $wpdb->insert(
            $wpdb->prefix . 'ims_imports',
            array(
                'product'          => sanitize_text_field($product),
                'quantity'         => $q,
                'staff_name'       => $current_user->display_name,
                'date_created'     => $lagos_time,
                'timestamp_created'=> $lagos_time,
                'processed'        => 1
            ),
            array('%s','%f','%s','%s','%s','%d')
        );
        if ($ins !== false) {
            $saved++;
            $total_qty += $q;

            if (ims_is_fruit($product)) {
                ims_update_chopped_import_field($product, $q, $current_user, $lagos_time, $today);
            } else {
                ims_update_stock_added_field($product, $q, $current_user, $lagos_time, $today);
            }
        }
    }

    if ($saved > 0) {
        $redirect_url = wp_get_raw_referer();
        if (!$redirect_url && isset($_SERVER['REQUEST_URI'])) {
            $redirect_url = home_url(remove_query_arg(array('ims_success', 'ims_error'), wp_unslash($_SERVER['REQUEST_URI'])));
        }
        if (!$redirect_url) {
            $redirect_url = home_url('/');
        }
        wp_redirect(add_query_arg(array(
            'ims_success' => 'import',
            'count' => $saved,
            'total' => $total_qty,
            'time'  => urlencode($lagos_time),
        ), $redirect_url)); exit;
    } else {
        $redirect_url = wp_get_raw_referer();
        if (!$redirect_url && isset($_SERVER['REQUEST_URI'])) {
            $redirect_url = home_url(remove_query_arg(array('ims_success', 'ims_error'), wp_unslash($_SERVER['REQUEST_URI'])));
        }
        if (!$redirect_url) {
            $redirect_url = home_url('/');
        }
        wp_redirect(add_query_arg('ims_error', 'import_save_failed', $redirect_url)); exit;
    }
}

/* =========================
   Update chopped import (fruits) with non-negative and capping
   ========================= */
function ims_update_chopped_import_field($fruit, $quantity, $current_user, $lagos_time, $today) {
    global $wpdb;

    $chopped_table = $wpdb->prefix . 'ims_chopped';

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $chopped_table WHERE fruit = %s AND DATE(date_created) = %s ORDER BY id DESC LIMIT 1",
        $fruit, $today
    ));

    if ($existing) {
        $open = max(0.0, floatval($existing->opening_whole));
        $imp_prev = max(0.0, floatval($existing->import_whole));
        $prep = max(0.0, floatval($existing->prepared_whole));
        $new_imp = max(0.0, $imp_prev + floatval($quantity));
        $prep = min($prep, $open + $new_imp);
        $close = max(0.0, $open + $new_imp - $prep);

        $wpdb->update(
            $chopped_table,
            array(
                'import_whole'      => $new_imp,
                'prepared_whole'    => $prep,
                'closing_whole'     => $close,
                'timestamp_created' => $lagos_time
            ),
            array('id' => $existing->id),
            array('%f','%f','%f','%s'),
            array('%d')
        );
    } else {
        $opening = ims_get_last_chopped_closing_before($fruit, $today);
        $imp = max(0.0, floatval($quantity));
        $close = max(0.0, $opening + $imp);

        $wpdb->insert(
            $chopped_table,
            array(
                'fruit'             => $fruit,
                'opening_whole'     => $opening,
                'import_whole'      => $imp,
                'prepared_whole'    => 0,
                'closing_whole'     => $close,
                'packs_gotten'      => 0,
                'staff_name'        => $current_user->display_name,
                'date_created'      => $lagos_time,
                'timestamp_created' => $lagos_time
            ),
            array('%s','%f','%f','%f','%f','%f','%s','%s','%s')
        );
    }
}

/* =========================
   CHOPPED submission: non-negative + capping + sync packs→stock
   ========================= */
function ims_handle_chopped_submission() {
    if (!ims_user_can_submit_forms()) {
        wp_die('Unauthorized access - you do not have permission to submit this form.');
    }

    global $wpdb;

    // The active chopped form still posts legacy field names (`opening[]`, `prepared[]`, `packs[]`).
    // Accept both legacy and canonical field names while the templates converge.
    $opening_values  = ims_extract_post_array_variants('opening_whole');
    $prepared_values = ims_extract_post_array_variants('prepared_whole');
    $packs_values    = ims_extract_post_array_variants('packs_gotten');
    
    $remarks_raw     = $_POST['remarks'] ?? '';
    // Support both per-fruit array and scalar remarks
    if (is_array($remarks_raw)) {
        $remarks_values = $remarks_raw;
        $remarks_scalar = '';
    } else {
        $remarks_values = array();
        $remarks_scalar = ims_normalize_remarks(sanitize_textarea_field($remarks_raw));
    }

    $current_user = wp_get_current_user();
    $lagos_time   = ims_get_lagos_time();
    $is_admin     = ims_user_can_edit_all_fields();
    $is_staff     = ims_is_staff_user();

    $chopped_table= $wpdb->prefix . 'ims_chopped';
    $today        = date('Y-m-d', strtotime($lagos_time));
    $fruits       = ims_get_products('chopped');
    $eps          = 1e-6;
    $saved        = 0;

    foreach ($fruits as $fruit) {
        $open_in = isset($opening_values[$fruit])  ? max(0.0, floatval($opening_values[$fruit]))  : 0.0;
        $prep_in = isset($prepared_values[$fruit]) ? max(0.0, floatval($prepared_values[$fruit])) : 0.0;
        $packs_in= isset($packs_values[$fruit])    ? max(0.0, floatval($packs_values[$fruit]))    : 0.0;

        // Resolve remarks: per-fruit array, scalar, or empty
        if (isset($remarks_values[$fruit])) {
            $rem_in = ims_normalize_remarks(sanitize_textarea_field($remarks_values[$fruit]));
        } elseif ($remarks_scalar !== '') {
            $rem_in = $remarks_scalar;
        } else {
            $rem_in = '';
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $chopped_table WHERE fruit = %s AND DATE(date_created) = %s ORDER BY id DESC LIMIT 1",
            $fruit, $today
        ));

        if ($existing) {
            $base_open  = max(0.0, floatval($existing->opening_whole));
            $base_imp   = max(0.0, floatval($existing->import_whole));
            $base_prep  = max(0.0, floatval($existing->prepared_whole));
            $base_packs = max(0.0, floatval($existing->packs_gotten));
            $base_rem   = $existing->remarks ?? '';
        } else {
            $base_open  = ims_get_last_chopped_closing_before($fruit, $today);
            $base_imp   = ims_get_today_import_total($fruit);
            $base_prep  = 0.0;
            $base_packs = 0.0;
            $base_rem   = '';
        }

        // For non-admin: skip fruit only if no form data submitted and no existing record
        if (!$is_admin && !isset($prepared_values[$fruit]) && !isset($packs_values[$fruit]) && !$existing) {
            continue;
        }

        if ($is_staff) {
            // Staff: opening always from database
            $final_open  = $base_open;
            $final_imp   = $base_imp;
            $final_prep  = min($base_prep + $prep_in, $final_open + $final_imp);
            $final_packs = max(0.0, $base_packs + $packs_in);
            if (!empty($rem_in) && $existing) {
                $ts = date('H:i', strtotime($lagos_time));
                $note = "[$ts - " . $current_user->display_name . "] " . $rem_in;
                $final_rem = $base_rem !== '' ? ($base_rem . "\n" . $note) : $note;
            } else {
                $final_rem = $existing ? $base_rem : '';
            }
        } else {
            // Admin can set opening from form
            $final_open  = ($open_in > 0) ? $open_in : $base_open;
            $final_imp   = $base_imp;
            $final_prep  = min($prep_in, $final_open + $final_imp);
            $final_packs = max(0.0, $packs_in);
            $final_rem   = $rem_in;
        }

        $final_close = max(0.0, $final_open + $final_imp - $final_prep);

        $data = array(
            'opening_whole'     => $final_open,
            'import_whole'      => $final_imp,
            'prepared_whole'    => $final_prep,
            'closing_whole'     => $final_close,
            'packs_gotten'      => $final_packs,
            'remarks'           => $final_rem,
            'staff_name'        => $current_user->display_name,
            'timestamp_created' => $lagos_time
        );

        if ($existing) {
            $res = $wpdb->update($chopped_table, $data, array('id' => $existing->id), array('%f','%f','%f','%f','%f','%s','%s','%s'), array('%d'));
        } else {
            $data['fruit']        = sanitize_text_field($fruit);
            $data['date_created'] = $lagos_time;
            $res = $wpdb->insert($chopped_table, $data, array('%s','%f','%f','%f','%f','%f','%s','%s','%s','%s'));
        }

        if ($res !== false) {
            $saved++;
            // Sync packs delta to stock "Added Packs"
            $delta_packs = $existing ? ($final_packs - $base_packs) : $final_packs;
            if (abs($delta_packs) > $eps) {
                ims_sync_chopped_packs_to_stock($fruit, $delta_packs, $current_user, $lagos_time, $today);
            }
        }
    }

    if ($saved > 0) {
        $flag = $is_staff ? 'chopped_staff' : 'chopped_admin';
        $redirect_url = wp_get_raw_referer();
        if (!$redirect_url && isset($_SERVER['REQUEST_URI'])) {
            $redirect_url = home_url(remove_query_arg(array('ims_success', 'ims_error'), wp_unslash($_SERVER['REQUEST_URI'])));
        }
        if (!$redirect_url) {
            $redirect_url = home_url('/');
        }
        wp_redirect(add_query_arg(array('ims_success' => $flag, 'count' => $saved, 'time' => urlencode($lagos_time)), $redirect_url)); exit;
    } else {
        $redirect_url = wp_get_raw_referer();
        if (!$redirect_url && isset($_SERVER['REQUEST_URI'])) {
            $redirect_url = home_url(remove_query_arg(array('ims_success', 'ims_error'), wp_unslash($_SERVER['REQUEST_URI'])));
        }
        if (!$redirect_url) {
            $redirect_url = home_url('/');
        }
        wp_redirect(add_query_arg('ims_error', 'chopped_no_data', $redirect_url)); exit;
    }
}

/* =========================
   Success toast messages
   ========================= */
add_action('wp_footer', function() {
    if (!isset($_GET['ims_success'])) return;

    $current_user = wp_get_current_user();
    $lagos_time = ims_get_lagos_time();
    $type = sanitize_text_field($_GET['ims_success']);
    $message = '';
    switch ($type) {
        case 'stock_admin':
            $count = intval($_GET['count'] ?? 0);
            $message = '<iconify-icon icon="solar:check-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Stock form submitted successfully! ' . $count . ' products saved.';
            break;
        case 'stock_staff':
            $count = intval($_GET['count'] ?? 0);
            $message = '<iconify-icon icon="solar:check-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Used packs submitted successfully! ' . $count . ' products updated.';
            break;
        case 'import':
            $count = intval($_GET['count'] ?? 0);
            $total = floatval($_GET['total'] ?? 0);
            $message = '<iconify-icon icon="solar:check-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Import submitted successfully! ' . $count . ' products imported. Total: ' . number_format($total, 2) . '.';
            break;
        case 'chopped_admin':
            $count = intval($_GET['count'] ?? 0);
            $message = '<iconify-icon icon="solar:check-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Admin: Chopped form submitted! ' . $count . ' fruits saved.';
            break;
        case 'chopped_staff':
            $count = intval($_GET['count'] ?? 0);
            $message = '<iconify-icon icon="solar:check-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Staff: Prepared, Packs & Remarks submitted! ' . $count . ' fruits updated.';
            break;
        default:
            $message = '<iconify-icon icon="solar:check-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Form submitted by ' . $current_user->display_name . ' at ' . $lagos_time . ' (Lagos).';
            break;
    }

    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        var message = document.createElement("div");
        message.innerHTML = ' . json_encode($message) . ';
        message.style.cssText = "position: fixed; top: 20px; right: 20px; background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; border: 2px solid #c3e6cb; z-index: 9999; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-width: 520px; font-size: 14px;";
        document.body.appendChild(message);
        setTimeout(function() { message.remove(); }, 10000);
    });
    </script>';
});

/* =========================
   Error toast messages (for traditional POST form failures)
   ========================= */
add_action('wp_footer', function() {
    if (!isset($_GET['ims_error'])) return;

    $type = sanitize_text_field($_GET['ims_error']);
    $message = '';
    switch ($type) {
        case 'stock_no_data':
            $message = '<iconify-icon icon="solar:danger-triangle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Stock form: No data was saved. Please enter values and try again.';
            break;
        case 'chopped_no_data':
            $message = '<iconify-icon icon="solar:danger-triangle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Chopped form: No data was saved. Please enter values and try again.';
            break;
        case 'import_save_failed':
            $message = '<iconify-icon icon="solar:danger-triangle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Import form: No data was saved. Please select a product and enter a quantity.';
            break;
        default:
            $message = '<iconify-icon icon="solar:danger-triangle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Form submission failed. Please try again.';
            break;
    }

    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        var message = document.createElement("div");
        message.innerHTML = ' . json_encode($message) . ';
        message.style.cssText = "position: fixed; top: 20px; right: 20px; background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; border: 2px solid #f5c6cb; z-index: 9999; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-width: 520px; font-size: 14px;";
        document.body.appendChild(message);
        setTimeout(function() { message.remove(); }, 10000);
    });
    </script>';
});

/* =========================
   Enqueue Iconify for Solar Linear icons
   ========================= */
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script('iconify', 'https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js', array(), '2.1.0', true);

    // Enqueue frontend JS for AJAX form submissions
    wp_enqueue_script('ims-frontend', IMS_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), IMS_VERSION, true);
    wp_localize_script('ims-frontend', 'ims_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ims_nonce'),
    ));
});
add_action('admin_enqueue_scripts', function() {
    wp_enqueue_script('iconify', 'https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js', array(), '2.1.0', true);
});

/* =========================
   Load shared helpers
   ========================= */
require_once IMS_PLUGIN_PATH . 'includes/functions.php';

/* =========================
   Load AJAX handler class
   ========================= */
require_once IMS_PLUGIN_PATH . 'includes/class-ajax.php';
new IMS_Ajax();

/* =========================
   One-time migration v2: restore ALL historical opening values aggressively
   Walks every record chronologically per product/fruit.
   For each record, opening MUST equal the previous record's closing.
   If it doesn't, fix it and cascade-recalculate closing too.
   ========================= */
add_action('init', function() {
    $migration_version = get_option('ims_opening_migration_version', 0);
    if ((int)$migration_version >= 2) {
        return; // Already ran v2
    }

    global $wpdb;
    $stock_table   = $wpdb->prefix . 'ims_stock';
    $chopped_table = $wpdb->prefix . 'ims_chopped';

    // --- Fix ALL stock opening values ---
    $stock_rows = $wpdb->get_results(
        "SELECT id, product, opening_packs, added_packs, used_packs, closing_packs, date_created
         FROM $stock_table
         ORDER BY product ASC, date_created ASC, id ASC"
    );

    $stock_by_product = array();
    foreach ($stock_rows as $row) {
        $stock_by_product[$row->product][] = $row;
    }

    foreach ($stock_by_product as $product => $rows) {
        $prev_closing = null;
        foreach ($rows as $row) {
            $opening = floatval($row->opening_packs);
            $added   = max(0.0, floatval($row->added_packs));
            $used    = max(0.0, floatval($row->used_packs));

            $need_update = false;
            $new_opening = $opening;

            // If there's a previous record and its closing differs from this opening, fix it
            if ($prev_closing !== null && abs($opening - $prev_closing) > 0.001) {
                $new_opening = $prev_closing;
                $need_update = true;
            }

            // Always recalculate closing for consistency
            $new_closing = max(0.0, $new_opening + $added - $used);

            if ($need_update || abs($new_closing - floatval($row->closing_packs)) > 0.001) {
                $wpdb->update(
                    $stock_table,
                    array(
                        'opening_packs' => $new_opening,
                        'closing_packs' => $new_closing
                    ),
                    array('id' => $row->id),
                    array('%f', '%f'),
                    array('%d')
                );
            }

            $prev_closing = $new_closing;
        }
    }

    // --- Fix ALL chopped opening values ---
    $chopped_rows = $wpdb->get_results(
        "SELECT id, fruit, opening_whole, import_whole, prepared_whole, closing_whole, packs_gotten, date_created
         FROM $chopped_table
         ORDER BY fruit ASC, date_created ASC, id ASC"
    );

    $chopped_by_fruit = array();
    foreach ($chopped_rows as $row) {
        $chopped_by_fruit[$row->fruit][] = $row;
    }

    foreach ($chopped_by_fruit as $fruit => $rows) {
        $prev_closing = null;
        foreach ($rows as $row) {
            $opening  = floatval($row->opening_whole);
            $imported = max(0.0, floatval($row->import_whole));
            $prepared = max(0.0, floatval($row->prepared_whole));

            $need_update = false;
            $new_opening = $opening;

            // If there's a previous record and its closing differs from this opening, fix it
            if ($prev_closing !== null && abs($opening - $prev_closing) > 0.001) {
                $new_opening = $prev_closing;
                $need_update = true;
            }

            // Always recalculate closing for consistency
            $new_closing = max(0.0, $new_opening + $imported - $prepared);

            if ($need_update || abs($new_closing - floatval($row->closing_whole)) > 0.001) {
                $wpdb->update(
                    $chopped_table,
                    array(
                        'opening_whole' => $new_opening,
                        'closing_whole' => $new_closing
                    ),
                    array('id' => $row->id),
                    array('%f', '%f'),
                    array('%d')
                );
            }

            $prev_closing = $new_closing;
        }
    }

    // Mark migration v2 as complete
    update_option('ims_opening_migration_version', 2);
    update_option('ims_opening_values_restored', true);
    error_log('IMS: Historical opening values restoration v2 completed - all records fixed');
});

/* =========================
   Daily reset: carry forward previous closing as today's opening
   Runs once per day on first page load (WP-Cron fallback)
   ========================= */
add_action('init', function() {
    $lagos_time = ims_get_lagos_time();
    $today = date('Y-m-d', strtotime($lagos_time));
    $last_reset = get_option('ims_last_daily_reset_date', '');

    if ($last_reset === $today) {
        return; // Already ran today
    }

    // Mark as done first to prevent concurrent runs
    update_option('ims_last_daily_reset_date', $today);

    global $wpdb;

    // Carry forward stock closing → opening
    $stock_products = ims_get_products('all');
    foreach ($stock_products as $product) {
        $today_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ims_stock WHERE product = %s AND DATE(date_created) = %s",
            $product, $today
        ));
        if ($today_exists > 0) {
            continue;
        }
        $previous_closing = ims_get_last_stock_closing_before($product, $today);
        if ($previous_closing > 0) {
            $wpdb->insert(
                $wpdb->prefix . 'ims_stock',
                array(
                    'product'           => $product,
                    'opening_packs'     => $previous_closing,
                    'added_packs'       => 0,
                    'used_packs'        => 0,
                    'closing_packs'     => $previous_closing,
                    'staff_name'        => 'System Reset',
                    'date_created'      => $lagos_time,
                    'timestamp_created' => $lagos_time,
                    'remarks'           => 'Daily reset - carried forward'
                ),
                array('%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
            );
        }
    }

    // Carry forward chopped closing → opening
    $chopped_fruits = ims_get_products('chopped');
    foreach ($chopped_fruits as $fruit) {
        $today_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ims_chopped WHERE fruit = %s AND DATE(date_created) = %s",
            $fruit, $today
        ));
        if ($today_exists > 0) {
            continue;
        }
        $previous_closing = ims_get_last_chopped_closing_before($fruit, $today);
        if ($previous_closing > 0) {
            $wpdb->insert(
                $wpdb->prefix . 'ims_chopped',
                array(
                    'fruit'             => $fruit,
                    'opening_whole'     => $previous_closing,
                    'import_whole'      => 0,
                    'prepared_whole'    => 0,
                    'closing_whole'     => $previous_closing,
                    'packs_gotten'      => 0,
                    'staff_name'        => 'System Reset',
                    'date_created'      => $lagos_time,
                    'timestamp_created' => $lagos_time,
                    'remarks'           => 'Daily reset - carried forward'
                ),
                array('%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
            );
        }
    }

    error_log("IMS Daily Reset completed at: $lagos_time");
});

/* =========================
   Activation hook
   ========================= */
register_activation_hook(__FILE__, function() {
    ims_create_database_tables();
    ims_populate_default_products();
    add_option('ims_low_stock_threshold', 10);
    add_option('ims_timezone', 'Africa/Lagos');
    add_option('ims_plugin_version', IMS_VERSION);
});

/* =========================
   FRONTEND HISTORIES + ANALYTICS (filters + pagination)
   ========================= */

// FRONTEND: Stock History with filters + pagination (persistent, no data loss)
add_shortcode('ims_stock_history', function($atts) {
    global $wpdb;
    $t = $wpdb->prefix . 'ims_stock';

    // Filters
    $per_page = 20;
    $page = isset($_GET['ims_page']) ? max(1, intval($_GET['ims_page'])) : 1;
    $offset = ($page - 1) * $per_page;

    $product = isset($_GET['ims_product']) ? sanitize_text_field($_GET['ims_product']) : '';
    $date_from = isset($_GET['ims_date_from']) ? sanitize_text_field($_GET['ims_date_from']) : '';
    $date_to   = isset($_GET['ims_date_to'])   ? sanitize_text_field($_GET['ims_date_to'])   : '';

    $where = 'WHERE 1=1';
    $vals = array();

    if ($product !== '') { $where .= " AND product = %s";             $vals[] = $product; }
    if ($date_from !== '') { $where .= " AND DATE(date_created) >= %s"; $vals[] = $date_from; }
    if ($date_to !== '')   { $where .= " AND DATE(date_created) <= %s"; $vals[] = $date_to; }

    // Data with LIMIT/OFFSET
    $sql = "SELECT * FROM $t $where ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($vals, array($per_page, $offset))));

    // Total for pagination
    $count_sql = "SELECT COUNT(*) FROM $t $where";
    $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, $vals));
    $total_pages = max(1, (int)ceil($total_rows / $per_page));

    $products = ims_get_products('all');
    $current_user = wp_get_current_user();
    $lagos_time = ims_get_lagos_time();

    // Helper for pagination links (preserve filters)
    $build_link = function($pageNum) use ($product,$date_from,$date_to){
        $args = array_filter(array(
            'ims_product' => $product,
            'ims_date_from' => $date_from,
            'ims_date_to' => $date_to,
            'ims_page' => $pageNum
        ), function($v){ return $v !== '' && $v !== null; });
        return esc_url(add_query_arg($args));
    };

    ob_start(); ?>
    <div style="max-width:1400px;margin:20px auto;padding:20px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-radius:12px;border:2px solid #FF0000;box-shadow:0 8px 32px rgba(255,0,0,0.1);">
        <h2 style="color:#FF0000;text-align:center;"><iconify-icon icon="solar:chart-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Stock History</h2>

        <form method="get" style="margin-bottom:15px;display:flex;gap:10px;flex-wrap:wrap;">
            <select name="ims_product">
                <option value="">All Products</option>
                <?php foreach ($products as $p): ?>
                <option value="<?php echo esc_attr($p); ?>" <?php selected($_GET['ims_product'] ?? '', $p); ?>><?php echo esc_html($p); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="ims_date_from" value="<?php echo esc_attr($date_from); ?>">
            <input type="date" name="ims_date_to" value="<?php echo esc_attr($date_to); ?>">
            <button type="submit" class="button button-primary">Filter</button>
            <a href="<?php echo esc_url(remove_query_arg(array('ims_product','ims_date_from','ims_date_to','ims_page'))); ?>" class="button">Clear</a>
        </form>

        <table style="width:100%;border-collapse:collapse;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
            <thead style="background:#cc0000;color:#fff;">
                <tr>
                    <th style="padding:10px;text-align:left;">ID</th>
                    <th style="padding:10px;text-align:left;">Product</th>
                    <th style="padding:10px;text-align:left;">Opening</th>
                    <th style="padding:10px;text-align:left;">Added</th>
                    <th style="padding:10px;text-align:left;">Used</th>
                    <th style="padding:10px;text-align:left;">Closing</th>
                    <th style="padding:10px;text-align:left;">Staff</th>
                    <th style="padding:10px;text-align:left;">Date</th>
                    <th style="padding:10px;text-align:left;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;"><?php echo esc_html($r->id); ?></td>
                    <td style="padding:10px;"><?php echo ims_is_fruit($r->product) ? '<iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> ' : '<iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> '; echo esc_html($r->product); ?></td>
                    <td style="padding:10px;"><?php echo number_format(max(0.0,(float)$r->opening_packs), 2); ?></td>
                    <td style="padding:10px;color:#28a745;">+<?php echo number_format(max(0.0,(float)$r->added_packs), 2); ?></td>
                    <td style="padding:10px;color:#dc3545;"><?php echo number_format(max(0.0,(float)$r->used_packs), 2); ?></td>
                    <td style="padding:10px;color:#FF0000;"><?php echo number_format(max(0.0,(float)$r->closing_packs), 2); ?></td>
                    <td style="padding:10px;"><?php echo esc_html($r->staff_name); ?></td>
                    <td style="padding:10px;"><?php echo esc_html(date('Y-m-d H:i', strtotime($r->date_created))); ?></td>
                    <td style="padding:10px;"><?php $rem=trim((string)($r->remarks??'')); echo $rem!==''?nl2br(esc_html($rem)):'<em>No remarks</em>'; ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="9" style="padding:20px;text-align:center;"><em>No stock records found.</em></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;align-items:center;margin-top:12px;">
            <?php if ($page > 1): ?>
                <a class="button" href="<?php echo $build_link(1); ?>">« First</a>
                <a class="button" href="<?php echo $build_link($page-1); ?>">‹ Prev</a>
            <?php endif; ?>
            <span>Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
                <a class="button" href="<?php echo $build_link($page+1); ?>">Next ›</a>
                <a class="button" href="<?php echo $build_link($total_pages); ?>">Last »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="text-align:center;margin-top:10px;color:#6c757d;">Current Time: <?php echo esc_html($lagos_time); ?> | User: <?php echo esc_html($current_user->display_name); ?></div>
    </div>
    <?php
    return ob_get_clean();
});

// FRONTEND: Import History with filters + pagination
add_shortcode('ims_import_history', function($atts){
    global $wpdb;
    $t = $wpdb->prefix . 'ims_imports';

    $per_page = 20;
    $page = isset($_GET['ims_page']) ? max(1, intval($_GET['ims_page'])) : 1;
    $offset = ($page - 1) * $per_page;

    $product = isset($_GET['ims_product']) ? sanitize_text_field($_GET['ims_product']) : '';
    $date_from = isset($_GET['ims_date_from']) ? sanitize_text_field($_GET['ims_date_from']) : '';
    $date_to   = isset($_GET['ims_date_to'])   ? sanitize_text_field($_GET['ims_date_to'])   : '';

    $where = 'WHERE 1=1';
    $vals = array();

    if ($product !== '')   { $where .= " AND product = %s";             $vals[] = $product; }
    if ($date_from !== '') { $where .= " AND DATE(date_created) >= %s"; $vals[] = $date_from; }
    if ($date_to !== '')   { $where .= " AND DATE(date_created) <= %s"; $vals[] = $date_to; }

    $sql = "SELECT id, product, quantity, staff_name, date_created, timestamp_created FROM $t $where ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($vals, array($per_page, $offset))));

    $count_sql = "SELECT COUNT(*) FROM $t $where";
    $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, $vals));
    $total_pages = max(1, (int)ceil($total_rows / $per_page));

    $products = ims_get_products('all');

    $build_link = function($pageNum) use ($product,$date_from,$date_to){
        $args = array_filter(array(
            'ims_product' => $product,
            'ims_date_from' => $date_from,
            'ims_date_to' => $date_to,
            'ims_page' => $pageNum
        ), function($v){ return $v !== '' && $v !== null; });
        return esc_url(add_query_arg($args));
    };

    ob_start(); ?>
    <div style="max-width:1400px;margin:20px auto;padding:20px;background:#fff;border-radius:12px;border:2px solid rgba(40,167,69,.1);">
        <h2 style="color:#28a745;text-align:center;"><iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Import History</h2>

        <form method="get" style="margin-bottom:15px;display:flex;gap:10px;flex-wrap:wrap;">
            <select name="ims_product">
                <option value="">All Products</option>
                <?php foreach ($products as $p): ?>
                <option value="<?php echo esc_attr($p); ?>" <?php selected($_GET['ims_product'] ?? '', $p); ?>><?php echo esc_html($p); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="ims_date_from" value="<?php echo esc_attr($date_from); ?>">
            <input type="date" name="ims_date_to" value="<?php echo esc_attr($date_to); ?>">
            <button type="submit" class="button button-primary">Filter</button>
            <a href="<?php echo esc_url(remove_query_arg(array('ims_product','ims_date_from','ims_date_to','ims_page'))); ?>" class="button">Clear</a>
        </form>

        <table style="width:100%;border-collapse:collapse;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
            <thead style="background:#20a142;color:#fff;">
                <tr>
                    <th style="padding:10px;text-align:left;">ID</th>
                    <th style="padding:10px;text-align:left;">Product</th>
                    <th style="padding:10px;text-align:left;">Quantity</th>
                    <th style="padding:10px;text-align:left;">Staff</th>
                    <th style="padding:10px;text-align:left;">Date</th>
                    <th style="padding:10px;text-align:left;">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;"><?php echo esc_html($r->id); ?></td>
                    <td style="padding:10px;"><?php echo ims_is_fruit($r->product) ? '<iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> ' : '<iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> '; echo esc_html($r->product); ?></td>
                    <td style="padding:10px;color:#28a745;">+<?php echo number_format(max(0.0,(float)$r->quantity), 2); ?></td>
                    <td style="padding:10px;"><?php echo esc_html($r->staff_name); ?></td>
                    <td style="padding:10px;"><?php echo esc_html(date('Y-m-d', strtotime($r->date_created))); ?></td>
                    <td style="padding:10px;"><?php echo esc_html(date('H:i', strtotime($r->timestamp_created))); ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" style="padding:20px;text-align:center;"><em>No import records found.</em></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;align-items:center;margin-top:12px;">
            <?php if ($page > 1): ?>
                <a class="button" href="<?php echo $build_link(1); ?>">« First</a>
                <a class="button" href="<?php echo $build_link($page-1); ?>">‹ Prev</a>
            <?php endif; ?>
            <span>Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
                <a class="button" href="<?php echo $build_link($page+1); ?>">Next ›</a>
                <a class="button" href="<?php echo $build_link($total_pages); ?>">Last »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

/* Analytics renderer shared by multiple aliases */
function ims_render_inventory_analytics_shortcode() {
    global $wpdb;

    $date_from = isset($_GET['ims_date_from']) ? sanitize_text_field($_GET['ims_date_from']) : '';
    $date_to   = isset($_GET['ims_date_to'])   ? sanitize_text_field($_GET['ims_date_to'])   : '';
    if ($date_from === '' && $date_to === '') {
        // default: last 7 days
        $date_to = date('Y-m-d', strtotime(ims_get_lagos_time()));
        $date_from = date('Y-m-d', strtotime($date_to . ' -6 days'));
    }

    $where_date_imports = 'WHERE 1=1';
    $where_date_stock   = 'WHERE 1=1';
    $where_date_chopped = 'WHERE 1=1';
    $params_i = $params_s = $params_c = array();

    if ($date_from !== '') {
        $where_date_imports .= " AND DATE(date_created) >= %s";
        $where_date_stock   .= " AND DATE(date_created) >= %s";
        $where_date_chopped .= " AND DATE(date_created) >= %s";
        $params_i[] = $params_s[] = $params_c[] = $date_from;
    }
    if ($date_to !== '') {
        $where_date_imports .= " AND DATE(date_created) <= %s";
        $where_date_stock   .= " AND DATE(date_created) <= %s";
        $where_date_chopped .= " AND DATE(date_created) <= %s";
        $params_i[] = $params_s[] = $params_c[] = $date_to;
    }

    $ti = $wpdb->prefix.'ims_imports';
    $ts = $wpdb->prefix.'ims_stock';
    $tc = $wpdb->prefix.'ims_chopped';

    // Totals overall
    $imports_total = (float) ($params_i ? $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(quantity),0) FROM $ti $where_date_imports", $params_i)) : $wpdb->get_var("SELECT COALESCE(SUM(quantity),0) FROM $ti"));
    $stock_added_total = (float) ($params_s ? $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(added_packs),0) FROM $ts $where_date_stock", $params_s)) : $wpdb->get_var("SELECT COALESCE(SUM(added_packs),0) FROM $ts"));
    $stock_used_total  = (float) ($params_s ? $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(used_packs),0) FROM $ts $where_date_stock", $params_s)) : $wpdb->get_var("SELECT COALESCE(SUM(used_packs),0) FROM $ts"));
    $ch_import_total   = (float) ($params_c ? $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(import_whole),0) FROM $tc $where_date_chopped", $params_c)) : $wpdb->get_var("SELECT COALESCE(SUM(import_whole),0) FROM $tc"));
    $ch_prep_total     = (float) ($params_c ? $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(prepared_whole),0) FROM $tc $where_date_chopped", $params_c)) : $wpdb->get_var("SELECT COALESCE(SUM(prepared_whole),0) FROM $tc"));
    $ch_packs_total    = (float) ($params_c ? $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(packs_gotten),0) FROM $tc $where_date_chopped", $params_c)) : $wpdb->get_var("SELECT COALESCE(SUM(packs_gotten),0) FROM $tc"));

    // Per-product breakdowns
    $imports_rows = $params_i
        ? $wpdb->get_results($wpdb->prepare("SELECT product, COALESCE(SUM(quantity),0) total_qty FROM $ti $where_date_imports GROUP BY product ORDER BY total_qty DESC LIMIT 500", $params_i))
        : $wpdb->get_results("SELECT product, COALESCE(SUM(quantity),0) total_qty FROM $ti GROUP BY product ORDER BY total_qty DESC LIMIT 500");

    $stock_rows = $params_s
        ? $wpdb->get_results($wpdb->prepare("SELECT product, COALESCE(SUM(added_packs),0) added, COALESCE(SUM(used_packs),0) used FROM $ts $where_date_stock GROUP BY product ORDER BY added DESC LIMIT 500", $params_s))
        : $wpdb->get_results("SELECT product, COALESCE(SUM(added_packs),0) added, COALESCE(SUM(used_packs),0) used FROM $ts GROUP BY product ORDER BY added DESC LIMIT 500");

    $chopped_rows = $params_c
        ? $wpdb->get_results($wpdb->prepare("SELECT fruit, COALESCE(SUM(import_whole),0) imported, COALESCE(SUM(prepared_whole),0) prepared, COALESCE(SUM(packs_gotten),0) packs FROM $tc $where_date_chopped GROUP BY fruit ORDER BY packs DESC LIMIT 500", $params_c))
        : $wpdb->get_results("SELECT fruit, COALESCE(SUM(import_whole),0) imported, COALESCE(SUM(prepared_whole),0) prepared, COALESCE(SUM(packs_gotten),0) packs FROM $tc GROUP BY fruit ORDER BY packs DESC LIMIT 500");

    ob_start(); ?>
    <div style="max-width:1400px;margin:20px auto;padding:20px;background:#fff;border-radius:12px;border:2px solid rgba(0,0,0,.06);">
        <h2 style="text-align:center;"><iconify-icon icon="solar:graph-up-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Inventory Analytics</h2>
        <form method="get" style="margin-bottom:15px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
            <input type="date" name="ims_date_from" value="<?php echo esc_attr($date_from); ?>">
            <input type="date" name="ims_date_to" value="<?php echo esc_attr($date_to); ?>">
            <button type="submit" class="button button-primary">Apply</button>
            <a class="button" href="<?php echo esc_url(remove_query_arg(array('ims_date_from','ims_date_to'))); ?>">Reset</a>
        </form>

        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:12px 0;">
            <div style="background:#f7fff9;border:1px solid #d6f5df;border-radius:8px;padding:14px;">
                <div style="font-weight:700;color:#20a142;">Imports (All)</div>
                <div style="font-size:1.4rem;font-weight:800;"><?php echo number_format($imports_total,2); ?></div>
                <small>Sum of import quantities</small>
            </div>
            <div style="background:#fff7f7;border:1px solid #f5d6d6;border-radius:8px;padding:14px;">
                <div style="font-weight:700;color:#cc0000;">Stock Added / Used</div>
                <div style="font-size:1.1rem;"><strong>Added:</strong> <?php echo number_format($stock_added_total,2); ?> &nbsp; | &nbsp; <strong>Used:</strong> <?php echo number_format($stock_used_total,2); ?></div>
                <small>Aggregated across products</small>
            </div>
            <div style="background:#fffaf2;border:1px solid #f7e2bf;border-radius:8px;padding:14px;">
                <div style="font-weight:700;color:#e69500;">Chopped (Whole/Packs)</div>
                <div style="font-size:1.1rem;"><strong>Import:</strong> <?php echo number_format($ch_import_total,2); ?> &nbsp; | &nbsp; <strong>Prepared:</strong> <?php echo number_format($ch_prep_total,2); ?> &nbsp; | &nbsp; <strong>Packs:</strong> <?php echo number_format($ch_packs_total,2); ?></div>
                <small>Fruit processing totals</small>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:16px;">
            <div>
                <h3><iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Imports by Product</h3>
                <table style="width:100%;border-collapse:collapse;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
                    <thead><tr style="background:#eef8f0;"><th style="text-align:left;padding:8px;">Product</th><th style="text-align:right;padding:8px;">Total Qty</th></tr></thead>
                    <tbody>
                        <?php if ($imports_rows): foreach ($imports_rows as $row): ?>
                        <tr style="border-bottom:1px solid #eee;"><td style="padding:8px;"><?php echo ims_is_fruit($row->product)?'<iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> ':'<iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> '; echo esc_html($row->product); ?></td><td style="padding:8px;text-align:right;"><?php echo number_format(max(0.0,(float)$row->total_qty),2); ?></td></tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="2" style="padding:10px;text-align:center;"><em>No data</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <h3><iconify-icon icon="solar:chart-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Stock Added/Used by Product</h3>
                <table style="width:100%;border-collapse:collapse;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
                    <thead><tr style="background:#fff0f0;"><th style="text-align:left;padding:8px;">Product</th><th style="text-align:right;padding:8px;">Added</th><th style="text-align:right;padding:8px;">Used</th></tr></thead>
                    <tbody>
                        <?php if ($stock_rows): foreach ($stock_rows as $row): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px;"><?php echo ims_is_fruit($row->product)?'<iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> ':'<iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> '; echo esc_html($row->product); ?></td>
                            <td style="padding:8px;text-align:right;color:#28a745;">+<?php echo number_format(max(0.0,(float)$row->added),2); ?></td>
                            <td style="padding:8px;text-align:right;color:#dc3545;"><?php echo number_format(max(0.0,(float)$row->used),2); ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="3" style="padding:10px;text-align:center;"><em>No data</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <h3><iconify-icon icon="solar:scissors-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Chopped by Fruit</h3>
                <table style="width:100%;border-collapse:collapse;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
                    <thead><tr style="background:#fff8ef;"><th style="text-align:left;padding:8px;">Fruit</th><th style="text-align:right;padding:8px;">Import</th><th style="text-align:right;padding:8px;">Prepared</th><th style="text-align:right;padding:8px;">Packs</th></tr></thead>
                    <tbody>
                        <?php if ($chopped_rows): foreach ($chopped_rows as $row): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px;"><iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> <?php echo esc_html($row->fruit); ?></td>
                            <td style="padding:8px;text-align:right;"><?php echo number_format(max(0.0,(float)$row->imported),2); ?></td>
                            <td style="padding:8px;text-align:right;"><?php echo number_format(max(0.0,(float)$row->prepared),2); ?></td>
                            <td style="padding:8px;text-align:right;color:#FF0000;"><?php echo number_format(max(0.0,(float)$row->packs),2); ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="4" style="padding:10px;text-align:center;"><em>No data</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
/* Shortcode aliases for analytics (fix for "shortcode shows instead of rendering") */
add_shortcode('ims_inventory_analytics', 'ims_render_inventory_analytics_shortcode');
add_shortcode('import_chopped_stock_analytics', 'ims_render_inventory_analytics_shortcode'); // underscore version
add_shortcode('import-chopped-stock-analytics', 'ims_render_inventory_analytics_shortcode'); // hyphen version

/* =========================
   FRONTEND: Chopped History (with pagination)
   ========================= */
add_shortcode('ims_chopped_history', function($atts) {
    global $wpdb;
    $t = $wpdb->prefix . 'ims_chopped';

    $per_page = 20;
    $page = isset($_GET['ims_page']) ? max(1, intval($_GET['ims_page'])) : 1;
    $offset = ($page - 1) * $per_page;

    $where = "WHERE 1=1";
    $vals = array();

    if (!empty($_GET['ims_fruit'])) {
        $where .= " AND fruit = %s";
        $vals[] = sanitize_text_field($_GET['ims_fruit']);
    }
    if (!empty($_GET['ims_date_from'])) {
        $where .= " AND DATE(date_created) >= %s";
        $vals[] = sanitize_text_field($_GET['ims_date_from']);
    }
    if (!empty($_GET['ims_date_to'])) {
        $where .= " AND DATE(date_created) <= %s";
        $vals[] = sanitize_text_field($_GET['ims_date_to']);
    }

        $q = "SELECT * FROM $t $where ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($q, array_merge($vals, array($per_page, $offset))));

    // Total count for pagination
    $count_sql = "SELECT COUNT(*) FROM $t $where";
    if (!empty($vals)) {
        $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, $vals));
    } else {
        $total_rows = (int) $wpdb->get_var($count_sql);
    }
    $total_pages = max(1, (int)ceil($total_rows / $per_page));

    // For filter controls and preserved query params
    $fruit_param     = isset($_GET['ims_fruit']) ? sanitize_text_field($_GET['ims_fruit']) : '';
    $date_from_param = isset($_GET['ims_date_from']) ? sanitize_text_field($_GET['ims_date_from']) : '';
    $date_to_param   = isset($_GET['ims_date_to']) ? sanitize_text_field($_GET['ims_date_to']) : '';

    $fruits = ims_get_products('chopped');
    $current_user = wp_get_current_user();
    $lagos_time = ims_get_lagos_time();

    // Helper for pagination links (preserve filters)
    $build_link = function($pageNum) use ($fruit_param,$date_from_param,$date_to_param){
        $args = array_filter(array(
            'ims_fruit'     => $fruit_param,
            'ims_date_from' => $date_from_param,
            'ims_date_to'   => $date_to_param,
            'ims_page'      => $pageNum
        ), function($v){ return $v !== '' && $v !== null; });
        return esc_url(add_query_arg($args));
    };

    ob_start(); ?>
    <div style="max-width:1400px;margin:20px auto;padding:20px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-radius:12px;border:2px solid #FF0000;box-shadow:0 8px 32px rgba(255,0,0,0.1);">
        <h2 style="color:#FF0000;text-align:center;"><iconify-icon icon="solar:scissors-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Chopped History</h2>
        <form method="get" style="margin-bottom:15px;display:flex;gap:10px;flex-wrap:wrap;">
            <select name="ims_fruit">
                <option value="">All Fruits</option>
                <?php foreach ($fruits as $f): ?>
                <option value="<?php echo esc_attr($f); ?>" <?php selected($_GET['ims_fruit'] ?? '', $f); ?>><?php echo esc_html($f); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="ims_date_from" value="<?php echo esc_attr($_GET['ims_date_from'] ?? ''); ?>">
            <input type="date" name="ims_date_to" value="<?php echo esc_attr($_GET['ims_date_to'] ?? ''); ?>">
            <button type="submit" class="button button-primary">Filter</button>
            <a href="<?php echo esc_url(remove_query_arg(array('ims_fruit','ims_date_from','ims_date_to','ims_page'))); ?>" class="button">Clear</a>
        </form>
        <table style="width:100%;border-collapse:collapse;border:2px solid #FF0000;border-radius:12px;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 8px 32px rgba(255,0,0,0.1);">
            <thead style="background:#cc0000;color:#fff;">
                <tr>
                    <th style="padding:10px;text-align:left;">ID</th>
                    <th style="padding:10px;text-align:left;">Fruit</th>
                    <th style="padding:10px;text-align:left;">Opening</th>
                    <th style="padding:10px;text-align:left;">Import</th>
                    <th style="padding:10px;text-align:left;">Prepared</th>
                    <th style="padding:10px;text-align:left;">Closing</th>
                    <th style="padding:10px;text-align:left;">Packs</th>
                    <th style="padding:10px;text-align:left;">Staff</th>
                    <th style="padding:10px;text-align:left;">Date</th>
                    <th style="padding:10px;text-align:left;"><iconify-icon icon="solar:document-text-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;"><?php echo esc_html($r->id); ?></td>
                    <td style="padding:10px;"><iconify-icon icon="solar:leaf-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> <strong><?php echo esc_html($r->fruit); ?></strong></td>
                    <td style="padding:10px;"><?php echo number_format(max(0.0, (float)$r->opening_whole), 2); ?></td>
                    <td style="padding:10px;color:#28a745;">+<?php echo number_format(max(0.0, (float)$r->import_whole), 2); ?></td>
                    <td style="padding:10px;color:#dc3545;"><?php echo number_format(max(0.0, (float)$r->prepared_whole), 2); ?></td>
                    <td style="padding:10px;"><?php echo number_format(max(0.0, (float)$r->closing_whole), 2); ?></td>
                    <td style="padding:10px;color:#FF0000;"><?php echo number_format(max(0.0, (float)$r->packs_gotten), 2); ?></td>
                    <td style="padding:10px;"><?php echo esc_html($r->staff_name); ?></td>
                    <td style="padding:10px;"><?php echo esc_html(date('Y-m-d H:i', strtotime($r->date_created))); ?></td>
                    <td style="padding:10px;max-width:320px;"><?php $rem = trim((string)($r->remarks ?? '')); echo $rem !== '' ? nl2br(esc_html($rem)) : '<em>No remarks</em>'; ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="10" style="padding:20px;text-align:center;"><em>No chopped records found.</em></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;align-items:center;margin-top:12px;">
            <?php if ($page > 1): ?>
                <a class="button" href="<?php echo $build_link(1); ?>">« First</a>
                <a class="button" href="<?php echo $build_link($page-1); ?>">‹ Prev</a>
            <?php endif; ?>
            <span>Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
                <a class="button" href="<?php echo $build_link($page+1); ?>">Next ›</a>
                <a class="button" href="<?php echo $build_link($total_pages); ?>">Last »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="text-align:center;margin-top:10px;color:#6c757d;">Current Time: <?php echo esc_html($lagos_time); ?> | User: <?php echo esc_html($current_user->display_name); ?></div>
    </div>
    <?php
    return ob_get_clean();
});
