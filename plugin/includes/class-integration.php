<?php
/**
 * Integration class for handling cross-form data integration with persistence rules
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMS_Integration {
    
    public static function process_import_integration($import_id, $product, $quantity) {
        global $wpdb;
        
        $lagos_time = ims_get_lagos_time();
        $today = date('Y-m-d', strtotime($lagos_time));
        
        // Check if this is a fruit (goes to chopped form) or non-fruit (goes to stock form)
        if (ims_is_fruit($product)) {
            // Update chopped form - Import Whole column
            self::update_chopped_import_whole($product, $quantity, $today);
            
            // Track integration
            IMS_Database::update_integration_tracking(
                'imports', $import_id, 'chopped', $product
            );
        } else {
            // Update stock form - Added Packs from chopped/imports column
            self::update_stock_added_packs($product, $quantity, $today, 'import');
            
            // Track integration
            IMS_Database::update_integration_tracking(
                'imports', $import_id, 'stock', $product
            );
        }
        
        // Mark import as processed
        $wpdb->update(
            $wpdb->prefix . 'ims_imports',
            array('processed' => 1),
            array('id' => $import_id),
            array('%d'),
            array('%d')
        );
    }
    
    public static function process_chopped_packs_integration($packs_gotten_updates) {
        global $wpdb;
        
        $lagos_time = ims_get_lagos_time();
        $today = date('Y-m-d', strtotime($lagos_time));
        
        foreach ($packs_gotten_updates as $fruit => $quantity_change) {
            if ($quantity_change != 0) {
                // Update stock form - Added Packs from chopped/imports column
                self::update_stock_added_packs($fruit, $quantity_change, $today, 'chopped');
            }
        }
    }
    
    private static function update_chopped_import_whole($fruit, $quantity, $today) {
        global $wpdb;
        
        // Get current chopped record for today
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_chopped 
             WHERE fruit = %s AND DATE(date_created) = %s 
             ORDER BY id DESC LIMIT 1",
            $fruit, $today
        ));
        
        if ($existing) {
            // Update existing record - add to import_whole
            $new_import_whole = $existing->import_whole + $quantity;
            $new_closing_whole = $existing->opening_whole + $new_import_whole - $existing->prepared_whole;
            
            $wpdb->update(
                $wpdb->prefix . 'ims_chopped',
                array(
                    'import_whole' => $new_import_whole,
                    'closing_whole' => $new_closing_whole
                ),
                array('id' => $existing->id),
                array('%f', '%f'),
                array('%d')
            );
        } else {
            // Get the most recent closing value before today as opening (not just yesterday)
            $previous_data = $wpdb->get_row($wpdb->prepare(
                "SELECT closing_whole FROM {$wpdb->prefix}ims_chopped 
                 WHERE fruit = %s AND date_created < %s 
                 ORDER BY date_created DESC, id DESC LIMIT 1",
                $fruit, $today . ' 00:00:00'
            ));
            
            $opening_whole = $previous_data ? floatval($previous_data->closing_whole) : 0;
            $closing_whole = $opening_whole + $quantity;
            
            // Create new record with import value
            $wpdb->insert(
                $wpdb->prefix . 'ims_chopped',
                array(
                    'fruit' => $fruit,
                    'opening_whole' => $opening_whole,
                    'import_whole' => $quantity,
                    'prepared_whole' => 0,
                    'closing_whole' => $closing_whole,
                    'packs_gotten' => 0,
                    'staff_name' => 'System Integration',
                    'date_created' => ims_get_lagos_time(),
                    'timestamp_created' => ims_get_lagos_time(),
                    'remarks' => 'Auto-updated from import'
                ),
                array('%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
            );
        }
    }
    
    private static function update_stock_added_packs($product, $quantity, $today, $source) {
        global $wpdb;
        
        // Get current stock record for today
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_stock 
             WHERE product = %s AND DATE(date_created) = %s 
             ORDER BY id DESC LIMIT 1",
            $product, $today
        ));
        
        if ($existing) {
            // Update existing record - add to added_packs
            $new_added_packs = $existing->added_packs + $quantity;
            $new_closing_packs = $existing->opening_packs + $new_added_packs - $existing->used_packs;
            
            $wpdb->update(
                $wpdb->prefix . 'ims_stock',
                array(
                    'added_packs' => $new_added_packs,
                    'closing_packs' => $new_closing_packs
                ),
                array('id' => $existing->id),
                array('%f', '%f'),
                array('%d')
            );
        } else {
            // Get the most recent closing value before today as opening (not just yesterday)
            $previous_data = $wpdb->get_row($wpdb->prepare(
                "SELECT closing_packs FROM {$wpdb->prefix}ims_stock 
                 WHERE product = %s AND date_created < %s 
                 ORDER BY date_created DESC, id DESC LIMIT 1",
                $product, $today . ' 00:00:00'
            ));
            
            $opening_packs = $previous_data ? floatval($previous_data->closing_packs) : 0;
            $closing_packs = $opening_packs + $quantity;
            
            // Create new record with added packs
            $wpdb->insert(
                $wpdb->prefix . 'ims_stock',
                array(
                    'product' => $product,
                    'opening_packs' => $opening_packs,
                    'added_packs' => $quantity,
                    'used_packs' => 0,
                    'closing_packs' => $closing_packs,
                    'staff_name' => 'System Integration',
                    'date_created' => ims_get_lagos_time(),
                    'timestamp_created' => ims_get_lagos_time(),
                    'remarks' => "Auto-updated from $source"
                ),
                array('%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
            );
        }
    }
    
    public static function get_persistent_import_value($product, $date) {
        global $wpdb;
        
        // Get total imports for the product on the specified date
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantity) FROM {$wpdb->prefix}ims_imports 
             WHERE product = %s AND DATE(date_created) = %s",
            $product, $date
        ));
        
        return $result ? floatval($result) : 0;
    }
    
    public static function get_persistent_packs_gotten($fruit, $date) {
        global $wpdb;
        
        // Get the latest packs gotten for the fruit on the specified date
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT packs_gotten FROM {$wpdb->prefix}ims_chopped 
             WHERE fruit = %s AND DATE(date_created) = %s 
             ORDER BY id DESC LIMIT 1",
            $fruit, $date
        ));
        
        return $result ? floatval($result) : 0;
    }
    
    public static function reset_daily_values() {
        global $wpdb;
        
        $lagos_time = ims_get_lagos_time();
        $today = date('Y-m-d', strtotime($lagos_time));
        
        // Carry forward closing values as opening values for stock
        $stock_products = ims_get_products('all');
        foreach ($stock_products as $product) {
            // Check if today's record already exists - skip if so
            $today_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ims_stock 
                 WHERE product = %s AND DATE(date_created) = %s",
                $product, $today
            ));
            
            if ($today_exists > 0) {
                continue;
            }
            
            // Get the most recent closing value before today (not just yesterday)
            $previous_closing = $wpdb->get_var($wpdb->prepare(
                "SELECT closing_packs FROM {$wpdb->prefix}ims_stock 
                 WHERE product = %s AND date_created < %s 
                 ORDER BY date_created DESC, id DESC LIMIT 1",
                $product, $today . ' 00:00:00'
            ));
            
            if ($previous_closing !== null) {
                $opening_value = floatval($previous_closing);
                // Create today's opening record
                $wpdb->insert(
                    $wpdb->prefix . 'ims_stock',
                    array(
                        'product' => $product,
                        'opening_packs' => $opening_value,
                        'added_packs' => 0,
                        'used_packs' => 0,
                        'closing_packs' => $opening_value,
                        'staff_name' => 'System Reset',
                        'date_created' => $lagos_time,
                        'timestamp_created' => $lagos_time,
                        'remarks' => 'Daily reset - opening value'
                    ),
                    array('%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
                );
            }
        }
        
        // Carry forward closing values as opening values for chopped
        $chopped_fruits = ims_get_products('chopped');
        foreach ($chopped_fruits as $fruit) {
            // Check if today's record already exists - skip if so
            $today_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ims_chopped 
                 WHERE fruit = %s AND DATE(date_created) = %s",
                $fruit, $today
            ));
            
            if ($today_exists > 0) {
                continue;
            }
            
            // Get the most recent closing value before today (not just yesterday)
            $previous_closing = $wpdb->get_var($wpdb->prepare(
                "SELECT closing_whole FROM {$wpdb->prefix}ims_chopped 
                 WHERE fruit = %s AND date_created < %s 
                 ORDER BY date_created DESC, id DESC LIMIT 1",
                $fruit, $today . ' 00:00:00'
            ));
            
            if ($previous_closing !== null) {
                $opening_value = floatval($previous_closing);
                // Create today's opening record
                $wpdb->insert(
                    $wpdb->prefix . 'ims_chopped',
                    array(
                        'fruit' => $fruit,
                        'opening_whole' => $opening_value,
                        'import_whole' => 0,
                        'prepared_whole' => 0,
                        'closing_whole' => $opening_value,
                        'packs_gotten' => 0,
                        'staff_name' => 'System Reset',
                        'date_created' => $lagos_time,
                        'timestamp_created' => $lagos_time,
                        'remarks' => 'Daily reset - opening value'
                    ),
                    array('%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
                );
            }
        }
    }
}
?>