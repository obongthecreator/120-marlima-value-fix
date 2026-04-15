<?php
/**
 * Global functions for the inventory management system
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get current time in Lagos timezone
 * @return string Formatted datetime string
 */
function ims_get_current_time() {
    return ims_get_lagos_time();
}

/**
 * Format number for display
 * @param float $number The number to format
 * @param int $decimals Number of decimal places
 * @return string Formatted number
 */
function ims_format_number($number, $decimals = 2) {
    return number_format(floatval($number), $decimals, '.', ',');
}

/**
 * Validate decimal input
 * @param mixed $value The value to validate
 * @param float $min Minimum allowed value
 * @param float $max Maximum allowed value
 * @return float|false Validated value or false if invalid
 */
function ims_validate_decimal($value, $min = 0, $max = null) {
    $value = floatval($value);
    
    if ($value < $min) {
        return false;
    }
    
    if ($max !== null && $value > $max) {
        return false;
    }
    
    return $value;
}

/**
 * Check if current user can edit inventory
 * @return bool True if user can edit
 */
function ims_can_edit_inventory() {
    return is_user_logged_in() && (current_user_can('edit_posts') || current_user_can('manage_options'));
}

/**
 * Check if current user is admin
 * @return bool True if user is admin
 */
function ims_is_admin() {
    return current_user_can('manage_options');
}

/**
 * Get product type (fruit or non-fruit)
 * @param string $product Product name
 * @return string 'fruit' or 'non-fruit'
 */
function ims_get_product_type($product) {
    return ims_is_fruit($product) ? 'fruit' : 'non-fruit';
}

/**
 * Log system activity
 * @param string $action The action performed
 * @param string $details Additional details
 * @param string $user_id User ID (optional)
 */
function ims_log_activity($action, $details = '', $user_id = null) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }
    
    $user = get_userdata($user_id);
    $username = $user ? $user->display_name : 'System';
    
    $log_message = sprintf(
        '[IMS] %s - User: %s - Action: %s - Details: %s',
        ims_get_lagos_time(),
        $username,
        $action,
        $details
    );
    
    error_log($log_message);
}

/**
 * Send notification to admin
 * @param string $subject Email subject
 * @param string $message Email message
 * @param string $type Notification type
 */
function ims_send_admin_notification($subject, $message, $type = 'info') {
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    
    $full_subject = "[{$site_name}] {$subject}";
    
    $headers = array(
        'From: ' . $site_name . ' <' . $admin_email . '>',
        'Content-Type: text/html; charset=UTF-8'
    );
    
    $html_message = "
    <html>
    <body>
        <h2>{$subject}</h2>
        <p>{$message}</p>
        <hr>
        <p><small>This is an automated message from the Inventory Management System.<br>
        Time: " . ims_get_lagos_time() . "</small></p>
    </body>
    </html>";
    
    wp_mail($admin_email, $full_subject, $html_message, $headers);
}

/**
 * Calculate closing value
 * @param float $opening Opening value
 * @param float $added Added value
 * @param float $used Used value
 * @return float Closing value
 */
function ims_calculate_closing($opening, $added, $used) {
    return floatval($opening) + floatval($added) - floatval($used);
}

/**
 * Get the most recent closing value for a product before today
 * @param string $product Product name
 * @param string $table Table name (stock or chopped)
 * @param string $column Column name for closing value
 * @return float Most recent closing value before today
 */
function ims_get_yesterday_closing($product, $table, $column) {
    global $wpdb;
    
    $today = date('Y-m-d', strtotime(ims_get_lagos_time()));
    
    $product_column = ($table === 'chopped') ? 'fruit' : 'product';
    $table_name = $wpdb->prefix . 'ims_' . $table;
    
    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT {$column} FROM {$table_name} 
         WHERE {$product_column} = %s AND date_created < %s 
         ORDER BY date_created DESC, id DESC LIMIT 1",
        $product, $today . ' 00:00:00'
    ));
    
    return $result ? floatval($result) : 0;
}

/**
 * Clean and validate form data
 * @param array $data Form data
 * @param array $rules Validation rules
 * @return array|WP_Error Cleaned data or error
 */
function ims_validate_form_data($data, $rules) {
    $cleaned_data = array();
    $errors = array();
    
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? '';
        
        // Required field check
        if ($rule['required'] && empty($value)) {
            $errors[] = sprintf('%s is required', $rule['label']);
            continue;
        }
        
        // Type validation
        switch ($rule['type']) {
            case 'decimal':
                $cleaned_value = ims_validate_decimal($value, $rule['min'] ?? 0, $rule['max'] ?? null);
                if ($cleaned_value === false) {
                    $errors[] = sprintf('%s must be a valid decimal number', $rule['label']);
                } else {
                    $cleaned_data[$field] = $cleaned_value;
                }
                break;
                
            case 'text':
                $cleaned_data[$field] = sanitize_text_field($value);
                break;
                
            case 'textarea':
                $cleaned_data[$field] = sanitize_textarea_field($value);
                break;
                
            case 'select':
                if (in_array($value, $rule['options'])) {
                    $cleaned_data[$field] = $value;
                } else {
                    $errors[] = sprintf('%s contains an invalid option', $rule['label']);
                }
                break;
        }
    }
    
    if (!empty($errors)) {
        return new WP_Error('validation_failed', 'Validation failed', $errors);
    }
    
    return $cleaned_data;
}

/**
 * Generate unique transaction ID
 * @param string $prefix Prefix for the ID
 * @return string Unique transaction ID
 */
function ims_generate_transaction_id($prefix = 'IMS') {
    return $prefix . '-' . date('Ymd') . '-' . wp_generate_password(8, false);
}

/**
 * Check if value has changed significantly
 * @param float $old_value Old value
 * @param float $new_value New value
 * @param float $threshold Change threshold (default 0.01)
 * @return bool True if value changed significantly
 */
function ims_value_changed($old_value, $new_value, $threshold = 0.01) {
    return abs(floatval($old_value) - floatval($new_value)) > $threshold;
}

/**
 * Format time for display
 * @param string $datetime Datetime string
 * @param string $format Display format
 * @return string Formatted time
 */
function ims_format_time($datetime, $format = 'Y-m-d H:i:s') {
    return date($format, strtotime($datetime));
}

/**
 * Get system status information
 * @return array System status data
 */
function ims_get_system_status() {
    global $wpdb;
    
    return array(
        'plugin_version' => IMS_VERSION,
        'wordpress_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'database_version' => $wpdb->db_version(),
        'timezone' => get_option('ims_timezone', 'Africa/Lagos'),
        'low_stock_threshold' => get_option('ims_low_stock_threshold', 10),
        'last_daily_reset' => get_option('ims_last_daily_reset'),
        'current_time' => ims_get_lagos_time()
    );
}

/**
 * Backup database tables
 * @return string|false Backup file path or false on failure
 */
function ims_backup_database() {
    global $wpdb;
    
    $upload_dir = wp_upload_dir();
    $backup_dir = $upload_dir['basedir'] . '/ims-backups/';
    
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }
    
    $timestamp = date('Y-m-d-H-i-s');
    $backup_file = $backup_dir . "ims-backup-{$timestamp}.sql";
    
    $tables = array(
        $wpdb->prefix . 'ims_imports',
        $wpdb->prefix . 'ims_stock', 
        $wpdb->prefix . 'ims_chopped',
        $wpdb->prefix . 'ims_integration_tracking',
        $wpdb->prefix . 'ims_products'
    );
    
    $backup_content = '';
    
    foreach ($tables as $table) {
        // Get table structure
        $create_table = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N);
        if ($create_table) {
            $backup_content .= "DROP TABLE IF EXISTS {$table};\n";
            $backup_content .= $create_table[1] . ";\n\n";
        }
        
        // Get table data
        $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $values = array();
                foreach ($row as $value) {
                    $values[] = $wpdb->prepare('%s', $value);
                }
                $backup_content .= "INSERT INTO {$table} VALUES (" . implode(', ', $values) . ");\n";
            }
            $backup_content .= "\n";
        }
    }
    
    if (file_put_contents($backup_file, $backup_content)) {
        return $backup_file;
    }
    
    return false;
}

/**
 * Restore database from backup
 * @param string $backup_file Backup file path
 * @return bool True on success, false on failure
 */
function ims_restore_database($backup_file) {
    if (!file_exists($backup_file)) {
        return false;
    }
    
    global $wpdb;
    
    $sql_content = file_get_contents($backup_file);
    if ($sql_content === false) {
        return false;
    }
    
    // Split SQL content into individual queries
    $queries = explode(';', $sql_content);
    
    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            $result = $wpdb->query($query);
            if ($result === false) {
                return false;
            }
        }
    }
    
    return true;
}
?>