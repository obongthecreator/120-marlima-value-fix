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
        
        // The import form sends a single product + quantity pair
        // Build the quantities array from either format (single or batch)
        if (isset($_POST['quantity']) && is_array($_POST['quantity'])) {
            $quantities = $_POST['quantity'];
        } elseif (isset($_POST['product']) && isset($_POST['quantity']) && !is_array($_POST['quantity'])) {
            $product_name = sanitize_text_field($_POST['product']);
            $qty_val = floatval($_POST['quantity']);
            $quantities = ($product_name !== '' && $qty_val > 0) ? array($product_name => $qty_val) : array();
        } else {
            $quantities = array();
        }
        
        if (empty($quantities)) {
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
        
        // The stock form sends opening_packs[product] and used_packs[product] arrays
        $opening_values = isset($_POST['opening_packs']) && is_array($_POST['opening_packs']) ? $_POST['opening_packs'] : array();
        $used_values    = isset($_POST['used_packs']) && is_array($_POST['used_packs']) ? $_POST['used_packs'] : array();
        $remarks_raw    = isset($_POST['remarks']) ? $_POST['remarks'] : '';
        $remarks_text   = is_string($remarks_raw) ? sanitize_textarea_field($remarks_raw) : '';
        if (function_exists('ims_normalize_remarks')) {
            $remarks_text = ims_normalize_remarks($remarks_text);
        }
        
        // Always use the full product list — never rely solely on form keys
        $products = ims_get_products('all');
        if (empty($products)) {
            wp_send_json_error('No products configured in the system.');
        }
        $saved = 0;
        
        foreach ($products as $product) {
            $product = (string)$product;
            if ($product === '') continue;
            
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
            
            // Used packs: prefer form value, fall back to existing, then 0
            if (isset($used_values[$product])) {
                $used_packs = max(0.0, floatval($used_values[$product]));
            } elseif ($existing) {
                $used_packs = max(0.0, floatval($existing->used_packs));
            } else {
                $used_packs = 0.0;
            }
            
            // For non-admin: if no form value submitted and no existing record, skip this product
            // (avoids creating empty rows for products the user didn't interact with)
            if (!$is_admin && !isset($used_values[$product]) && !$existing) {
                continue;
            }
            
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
                'remarks'           => $remarks_text,
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
            // Provide debug info to help diagnose
            $debug = array(
                'opening_count' => count($opening_values),
                'used_count'    => count($used_values),
                'products_count'=> count($products),
                'is_admin'      => $is_admin,
            );
            wp_send_json_error('No stock data to save. Debug: ' . json_encode($debug));
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
        
        // The chopped form sends opening_whole[fruit], prepared_whole[fruit], packs_gotten[fruit], remarks (scalar or per-fruit array)
        $opening_values  = isset($_POST['opening_whole']) && is_array($_POST['opening_whole']) ? $_POST['opening_whole'] : array();
        $prepared_values = isset($_POST['prepared_whole']) && is_array($_POST['prepared_whole']) ? $_POST['prepared_whole'] : array();
        $packs_values    = isset($_POST['packs_gotten']) && is_array($_POST['packs_gotten']) ? $_POST['packs_gotten'] : array();
        $remarks_raw     = isset($_POST['remarks']) ? $_POST['remarks'] : '';
        // Support both per-fruit array and scalar remarks
        if (is_array($remarks_raw)) {
            $remarks_values = $remarks_raw;
            $remarks_scalar = '';
        } else {
            $remarks_values = array();
            $remarks_scalar = sanitize_textarea_field($remarks_raw);
            if (function_exists('ims_normalize_remarks')) {
                $remarks_scalar = ims_normalize_remarks($remarks_scalar);
            }
        }
        
        // Always use the full fruit list — never rely solely on form keys
        $fruits = ims_get_products('chopped');
        if (empty($fruits)) {
            wp_send_json_error('No chopped/fruit products configured in the system.');
        }
        $saved = 0;
        
        foreach ($fruits as $fruit) {
            $fruit = sanitize_text_field((string)$fruit);
            if ($fruit === '') continue;
            
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
            
            // Prepared from form, fall back to existing, then 0
            if (isset($prepared_values[$fruit])) {
                $final_prep = max(0.0, floatval($prepared_values[$fruit]));
            } elseif ($existing) {
                $final_prep = max(0.0, floatval($existing->prepared_whole));
            } else {
                $final_prep = 0.0;
            }
            
            // Cap prepared
            $max_prep = $final_open + $final_imp;
            if ($final_prep > $max_prep + $eps) {
                $final_prep = $max_prep;
            }
            
            // Packs gotten from form, fall back to existing, then 0
            if (isset($packs_values[$fruit])) {
                $final_packs = max(0.0, floatval($packs_values[$fruit]));
            } elseif ($existing) {
                $final_packs = max(0.0, floatval($existing->packs_gotten));
            } else {
                $final_packs = 0.0;
            }
            $base_packs  = $existing ? floatval($existing->packs_gotten) : 0.0;
            
            // For non-admin: if no form values submitted and no existing record, skip
            if (!$is_admin && !isset($prepared_values[$fruit]) && !isset($packs_values[$fruit]) && !$existing) {
                continue;
            }
            
            // Remarks: use per-fruit array if available, else scalar, else existing DB value
            if (isset($remarks_values[$fruit])) {
                $final_remarks = sanitize_textarea_field($remarks_values[$fruit]);
            } elseif ($remarks_scalar !== '') {
                $final_remarks = $remarks_scalar;
            } else {
                $final_remarks = $existing ? $existing->remarks : '';
            }
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
            $debug = array(
                'opening_count'  => count($opening_values),
                'prepared_count' => count($prepared_values),
                'packs_count'    => count($packs_values),
                'fruits_count'   => count($fruits),
                'is_admin'       => $is_admin,
            );
            wp_send_json_error('No chopped data to save. Debug: ' . json_encode($debug));
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