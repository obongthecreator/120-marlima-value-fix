<?php
/**
 * AJAX class for handling AJAX requests
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMS_Ajax {
    
    public function __construct() {
        add_action('wp_ajax_ims_submit_import', array($this, 'submit_import'));
        add_action('wp_ajax_nopriv_ims_submit_import', array($this, 'submit_import'));
        
        add_action('wp_ajax_ims_submit_stock', array($this, 'submit_stock'));
        add_action('wp_ajax_nopriv_ims_submit_stock', array($this, 'submit_stock'));
        
        add_action('wp_ajax_ims_submit_chopped', array($this, 'submit_chopped'));
        add_action('wp_ajax_nopriv_ims_submit_chopped', array($this, 'submit_chopped'));
        
        add_action('wp_ajax_ims_refresh_analytics', array($this, 'refresh_analytics'));
        add_action('wp_ajax_nopriv_ims_refresh_analytics', array($this, 'refresh_analytics'));
        
        add_action('wp_ajax_ims_get_current_time', array($this, 'get_current_time'));
        add_action('wp_ajax_nopriv_ims_get_current_time', array($this, 'get_current_time'));
    }
    
    public function submit_import() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ims_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        // Validate user
        if (!is_user_logged_in() || !ims_user_can_submit_forms()) {
            wp_send_json_error('User not authorized');
        }
        
        // The import form sends quantity[product] = value for each product
        $quantities = isset($_POST['quantity']) && is_array($_POST['quantity']) ? $_POST['quantity'] : array();
        
        if (empty($quantities) || !is_array($quantities)) {
            wp_send_json_error('No import data submitted');
        }
        
        global $wpdb;
        $current_user = wp_get_current_user();
        $lagos_time = ims_get_lagos_time();
        $today = date('Y-m-d', strtotime($lagos_time));
        $saved = 0;
        $total_qty = 0.0;
        
        foreach ($quantities as $product => $q) {
            $q = max(0.0, floatval($q));
            if ($q <= 0) continue;
            
            $product = sanitize_text_field($product);
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'ims_imports',
                array(
                    'product'           => $product,
                    'quantity'          => $q,
                    'staff_name'        => $current_user->display_name,
                    'date_created'      => $lagos_time,
                    'timestamp_created' => $lagos_time,
                    'processed'         => 1
                ),
                array('%s', '%f', '%s', '%s', '%s', '%d')
            );
            
            if ($result !== false) {
                $saved++;
                $total_qty += $q;
                
                // Trigger integration: fruits go to chopped, others to stock
                if (function_exists('ims_is_fruit') && ims_is_fruit($product)) {
                    if (function_exists('ims_update_chopped_import_field')) {
                        ims_update_chopped_import_field($product, $q, $current_user, $lagos_time, $today);
                    }
                } else {
                    if (function_exists('ims_update_stock_added_field')) {
                        ims_update_stock_added_field($product, $q, $current_user, $lagos_time, $today);
                    }
                }
            }
        }
        
        if ($saved > 0) {
            wp_send_json_success(array(
                'message' => 'Import submitted successfully! ' . $saved . ' products imported. Total: ' . number_format($total_qty, 2) . '.',
                'count'   => $saved,
                'total'   => $total_qty,
                'timestamp' => $lagos_time
            ));
        } else {
            wp_send_json_error('No valid import data to save');
        }
    }
    
    public function submit_stock() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ims_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        // Validate user
        if (!is_user_logged_in() || !ims_user_can_submit_forms()) {
            wp_send_json_error('User not authorized');
        }
        
        global $wpdb;
        $current_user = wp_get_current_user();
        $lagos_time   = ims_get_lagos_time();
        $today        = date('Y-m-d', strtotime($lagos_time));
        $stock_table  = $wpdb->prefix . 'ims_stock';
        $is_admin     = ims_user_can_edit_all_fields();
        $eps          = 1e-6;
        
        // The stock form sends opening[product] and used[product] arrays
        $opening_values = isset($_POST['opening']) && is_array($_POST['opening']) ? $_POST['opening'] : array();
        $used_values    = isset($_POST['used']) && is_array($_POST['used']) ? $_POST['used'] : array();
        
        // Process: admin → all products; staff → only submitted ones
        $products = $is_admin ? ims_get_products('all') : array_keys($used_values);
        $saved = 0;
        
        foreach ($products as $product) {
            $product = (string)$product;
            
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $stock_table WHERE product = %s AND DATE(date_created) = %s ORDER BY id DESC LIMIT 1",
                $product, $today
            ));
            
            // Determine opening packs
            if ($is_admin && isset($opening_values[$product])) {
                $opening_packs = max(0.0, floatval($opening_values[$product]));
            } elseif ($existing) {
                $opening_packs = floatval($existing->opening_packs);
            } else {
                $prev = $wpdb->get_row($wpdb->prepare(
                    "SELECT closing_packs FROM $stock_table WHERE product = %s AND date_created < %s ORDER BY date_created DESC, id DESC LIMIT 1",
                    $product, $today . ' 00:00:00'
                ));
                $opening_packs = $prev ? max(0.0, floatval($prev->closing_packs)) : 0.0;
            }
            
            // Added packs from existing record or auto-computed
            $added_packs = $existing ? floatval($existing->added_packs) : 0.0;
            
            // Used packs from form
            $used_packs = isset($used_values[$product]) ? max(0.0, floatval($used_values[$product])) : ($existing ? floatval($existing->used_packs) : 0.0);
            
            // Cap used to opening + added
            $max_used = $opening_packs + $added_packs;
            if ($used_packs > $max_used + $eps) {
                $used_packs = $max_used;
            }
            
            $closing_packs = max(0.0, $opening_packs + $added_packs - $used_packs);
            
            $data = array(
                'opening_packs'     => $opening_packs,
                'used_packs'        => $used_packs,
                'closing_packs'     => $closing_packs,
                'staff_name'        => $current_user->display_name,
                'timestamp_created' => $lagos_time
            );
            
            if ($existing) {
                $res = $wpdb->update($stock_table, $data, array('id' => $existing->id));
            } else {
                $data['product']      = $product;
                $data['added_packs']  = $added_packs;
                $data['date_created'] = $lagos_time;
                $res = $wpdb->insert($stock_table, $data);
            }
            
            if ($res !== false) $saved++;
        }
        
        if ($saved > 0) {
            $label = $is_admin ? 'Stock form submitted successfully' : 'Used packs submitted successfully';
            wp_send_json_success(array(
                'message'   => $label . '! ' . $saved . ' products saved.',
                'count'     => $saved,
                'timestamp' => $lagos_time
            ));
        } else {
            wp_send_json_error('No stock data to save');
        }
    }
    
    public function submit_chopped() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ims_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        // Validate user
        if (!is_user_logged_in() || !ims_user_can_submit_forms()) {
            wp_send_json_error('User not authorized');
        }
        
        global $wpdb;
        $current_user  = wp_get_current_user();
        $lagos_time    = ims_get_lagos_time();
        $today         = date('Y-m-d', strtotime($lagos_time));
        $chopped_table = $wpdb->prefix . 'ims_chopped';
        $is_admin      = ims_user_can_edit_all_fields();
        $is_staff      = ims_is_staff_user();
        $eps           = 1e-6;
        
        // The chopped form sends opening[fruit], prepared[fruit], packs[fruit], remarks[fruit]
        $opening_values  = isset($_POST['opening']) && is_array($_POST['opening']) ? $_POST['opening'] : array();
        $prepared_values = isset($_POST['prepared']) && is_array($_POST['prepared']) ? $_POST['prepared'] : array();
        $packs_values    = isset($_POST['packs']) && is_array($_POST['packs']) ? $_POST['packs'] : array();
        $remarks_values  = isset($_POST['remarks']) && is_array($_POST['remarks']) ? $_POST['remarks'] : array();
        
        $fruits = $is_admin ? ims_get_products('chopped') : array_keys(array_merge(
            is_array($prepared_values) ? $prepared_values : array(),
            is_array($packs_values) ? $packs_values : array()
        ));
        $fruits = array_unique($fruits);
        $saved = 0;
        
        foreach ($fruits as $fruit) {
            $fruit = sanitize_text_field((string)$fruit);
            
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $chopped_table WHERE fruit = %s AND DATE(date_created) = %s ORDER BY id DESC LIMIT 1",
                $fruit, $today
            ));
            
            // Determine opening
            if ($is_admin && isset($opening_values[$fruit])) {
                $final_open = max(0.0, floatval($opening_values[$fruit]));
            } elseif ($existing) {
                $final_open = floatval($existing->opening_whole);
            } else {
                $prev = $wpdb->get_row($wpdb->prepare(
                    "SELECT closing_whole FROM $chopped_table WHERE fruit = %s AND date_created < %s ORDER BY date_created DESC, id DESC LIMIT 1",
                    $fruit, $today . ' 00:00:00'
                ));
                $final_open = $prev ? max(0.0, floatval($prev->closing_whole)) : 0.0;
            }
            
            // Import whole from existing record
            $final_imp = $existing ? floatval($existing->import_whole) : 0.0;
            
            // Prepared from form
            $final_prep = isset($prepared_values[$fruit]) ? max(0.0, floatval($prepared_values[$fruit])) : ($existing ? floatval($existing->prepared_whole) : 0.0);
            
            // Cap prepared
            $max_prep = $final_open + $final_imp;
            if ($final_prep > $max_prep + $eps) {
                $final_prep = $max_prep;
            }
            
            // Packs gotten from form
            $final_packs = isset($packs_values[$fruit]) ? max(0.0, floatval($packs_values[$fruit])) : ($existing ? floatval($existing->packs_gotten) : 0.0);
            $base_packs  = $existing ? floatval($existing->packs_gotten) : 0.0;
            
            // Remarks
            $final_remarks = isset($remarks_values[$fruit]) ? sanitize_textarea_field($remarks_values[$fruit]) : ($existing ? $existing->remarks : '');
            if (function_exists('ims_normalize_remarks')) {
                $final_remarks = ims_normalize_remarks($final_remarks);
            }
            
            $closing = max(0.0, $final_open + $final_imp - $final_prep);
            
            $data = array(
                'opening_whole'     => $final_open,
                'import_whole'      => $final_imp,
                'prepared_whole'    => $final_prep,
                'closing_whole'     => $closing,
                'packs_gotten'      => $final_packs,
                'remarks'           => $final_remarks,
                'staff_name'        => $current_user->display_name,
                'timestamp_created' => $lagos_time
            );
            
            if ($existing) {
                $res = $wpdb->update($chopped_table, $data, array('id' => $existing->id));
            } else {
                $data['fruit']        = $fruit;
                $data['date_created'] = $lagos_time;
                $res = $wpdb->insert($chopped_table, $data);
            }
            
            if ($res !== false) {
                $saved++;
                // Sync packs delta to stock
                $delta_packs = $existing ? ($final_packs - $base_packs) : $final_packs;
                if (abs($delta_packs) > $eps && function_exists('ims_sync_chopped_packs_to_stock')) {
                    ims_sync_chopped_packs_to_stock($fruit, $delta_packs, $current_user, $lagos_time, $today);
                }
            }
        }
        
        if ($saved > 0) {
            $label = $is_staff ? 'Prepared, Packs & Remarks submitted successfully' : 'Chopped form submitted successfully';
            wp_send_json_success(array(
                'message'   => $label . '! ' . $saved . ' fruits saved.',
                'count'     => $saved,
                'timestamp' => $lagos_time
            ));
        } else {
            wp_send_json_error('No chopped data to save');
        }
    }
    
    public function refresh_analytics() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ims_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $analytics = IMS_Database::get_analytics_data();
        
        wp_send_json_success($analytics);
    }
    
    public function get_current_time() {
        $lagos_time = ims_get_lagos_time();
        
        wp_send_json_success(array(
            'date' => date('Y-m-d', strtotime($lagos_time)),
            'time' => date('H:i:s', strtotime($lagos_time)),
            'datetime' => $lagos_time
        ));
    }
    
    public static function handle_form_submission() {
        if (!wp_verify_nonce($_POST['nonce'], 'ims_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $form_type = sanitize_text_field($_POST['form_type']);
        
        switch ($form_type) {
            case 'import':
                self::submit_import();
                break;
            case 'stock':
                self::submit_stock();
                break;
            case 'chopped':
                self::submit_chopped();
                break;
            default:
                wp_send_json_error('Invalid form type');
        }
    }
}
?>