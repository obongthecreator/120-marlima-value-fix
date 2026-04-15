<?php
/**
 * Database class for handling all database operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMS_Database {
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Import table
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
        
        // Stock table
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
        
        // Chopped table
        $table_chopped = $wpdb->prefix . 'ims_chopped';
        $sql_chopped = "CREATE TABLE $table_chopped (
            id int(11) NOT NULL AUTO_INCREMENT,
            fruit varchar(255) NOT NULL,
            opening_whole decimal(10,2) DEFAULT 0,
            import_whole decimal(10,2) DEFAULT 0,
            prepared_whole decimal(10,2) DEFAULT 0,
            closing_whole decimal(10,2) DEFAULT 0,
            packs_gotten decimal(10,2) DEFAULT 0,
            staff_name varchar(255) NOT NULL,
            date_created datetime NOT NULL,
            timestamp_created datetime NOT NULL,
            remarks text,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_fruit (fruit),
            KEY idx_date (date_created)
        ) $charset_collate;";
        
        // Integration tracking table
        $table_integration = $wpdb->prefix . 'ims_integration_tracking';
        $sql_integration = "CREATE TABLE $table_integration (
            id int(11) NOT NULL AUTO_INCREMENT,
            source_table varchar(50) NOT NULL,
            source_id int(11) NOT NULL,
            target_table varchar(50) NOT NULL,
            target_product varchar(255) NOT NULL,
            processed tinyint(1) DEFAULT 0,
            processed_at datetime NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source (source_table, source_id),
            KEY idx_target (target_table, target_product),
            KEY idx_processed (processed)
        ) $charset_collate;";
        
        // Products management table
        $table_products = $wpdb->prefix . 'ims_products';
        $sql_products = "CREATE TABLE $table_products (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            type enum('all','chopped') DEFAULT 'all',
            is_active tinyint(1) DEFAULT 1,
            sort_order int(11) DEFAULT 0,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_name_type (name, type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_import);
        dbDelta($sql_stock);
        dbDelta($sql_chopped);
        dbDelta($sql_integration);
        dbDelta($sql_products);
        
        // Insert default products
        self::insert_default_products();
    }
    
    private static function insert_default_products() {
        global $wpdb;
        
        $table_products = $wpdb->prefix . 'ims_products';
        
        // Check if products already exist
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_products");
        if ($count > 0) {
            return;
        }
        
        $all_products = ims_get_products('all');
        $chopped_products = ims_get_products('chopped');
        
        foreach ($all_products as $index => $product) {
            $type = in_array($product, $chopped_products) ? 'chopped' : 'all';
            
            $wpdb->insert(
                $table_products,
                array(
                    'name' => $product,
                    'type' => $type,
                    'is_active' => 1,
                    'sort_order' => $index + 1
                ),
                array('%s', '%s', '%d', '%d')
            );
        }
        
        // Insert chopped-specific products
        foreach ($chopped_products as $index => $product) {
            $wpdb->insert(
                $table_products,
                array(
                    'name' => $product,
                    'type' => 'chopped',
                    'is_active' => 1,
                    'sort_order' => $index + 1
                ),
                array('%s', '%s', '%d', '%d')
            );
        }
    }
    
    public static function get_today_import_value($product) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ims_imports';
        $today = date('Y-m-d', strtotime(ims_get_lagos_time()));
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantity) FROM $table 
             WHERE product = %s AND DATE(date_created) = %s",
            $product, $today
        ));
        
        return $result ? floatval($result) : 0;
    }
    
    public static function get_today_stock_data($product) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ims_stock';
        $today = date('Y-m-d', strtotime(ims_get_lagos_time()));
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE product = %s AND DATE(date_created) = %s 
             ORDER BY id DESC LIMIT 1",
            $product, $today
        ));
        
        return $result;
    }
    
    public static function get_today_chopped_data($fruit) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ims_chopped';
        $today = date('Y-m-d', strtotime(ims_get_lagos_time()));
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE fruit = %s AND DATE(date_created) = %s 
             ORDER BY id DESC LIMIT 1",
            $fruit, $today
        ));
        
        return $result;
    }
    
    public static function update_integration_tracking($source_table, $source_id, $target_table, $target_product) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ims_integration_tracking';
        
        return $wpdb->insert(
            $table,
            array(
                'source_table' => $source_table,
                'source_id' => $source_id,
                'target_table' => $target_table,
                'target_product' => $target_product,
                'processed' => 1,
                'processed_at' => ims_get_lagos_time()
            ),
            array('%s', '%d', '%s', '%s', '%d', '%s')
        );
    }
    
    public static function get_analytics_data() {
        global $wpdb;
        
        $import_table = $wpdb->prefix . 'ims_imports';
        $stock_table = $wpdb->prefix . 'ims_stock';
        $chopped_table = $wpdb->prefix . 'ims_chopped';
        
        $import_count = $wpdb->get_var("SELECT COUNT(*) FROM $import_table");
        $stock_count = $wpdb->get_var("SELECT COUNT(*) FROM $stock_table");
        $chopped_count = $wpdb->get_var("SELECT COUNT(*) FROM $chopped_table");
        
        // Get low stock count
        $threshold = get_option('ims_low_stock_threshold', 10);
        $low_stock_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT product) FROM $stock_table 
             WHERE closing_packs <= %d AND DATE(date_created) = %s",
            $threshold, date('Y-m-d')
        ));
        
        return array(
            'imports' => $import_count,
            'stock' => $stock_count,
            'chopped' => $chopped_count,
            'low_stock' => $low_stock_count
        );
    }
}
?>