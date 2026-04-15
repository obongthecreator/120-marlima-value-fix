<?php
/**
 * Cron class for handling scheduled tasks
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMS_Cron {
    
    public function __construct() {
        // Register the custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        add_action('ims_daily_reset', array($this, 'perform_daily_reset'));
        add_action('ims_low_stock_alert', array($this, 'send_low_stock_alerts'));
        
        // Fallback: on every page load, check if daily reset needs to run
        // This ensures opening values carry forward even if WP-Cron misses
        add_action('init', array($this, 'maybe_run_daily_reset'));
        
        // Schedule low stock alerts if not already scheduled
        if (!wp_next_scheduled('ims_low_stock_alert')) {
            wp_schedule_event(time(), 'hourly', 'ims_low_stock_alert');
        }
    }
    
    /**
     * Register the custom 'daily_reset' cron schedule with WordPress.
     */
    public function add_cron_schedules($schedules) {
        $schedules['daily_reset'] = array(
            'interval' => DAY_IN_SECONDS,
            'display'  => 'Once Daily (IMS Reset)'
        );
        return $schedules;
    }
    
    /**
     * Fallback daily reset: runs on page load if the cron hasn't fired today.
     * Checks the last reset date and triggers carry-forward if it's a new day.
     */
    public function maybe_run_daily_reset() {
        $lagos_time = ims_get_lagos_time();
        $today = date('Y-m-d', strtotime($lagos_time));
        $last_reset = get_option('ims_last_daily_reset_date', '');
        
        if ($last_reset === $today) {
            return; // Already ran today
        }
        
        // Mark as done FIRST to prevent concurrent runs
        update_option('ims_last_daily_reset_date', $today);
        
        // Perform the reset
        $this->perform_daily_reset();
    }
    
    public function perform_daily_reset() {
        global $wpdb;
        
        $lagos_time = ims_get_lagos_time();
        $today = date('Y-m-d', strtotime($lagos_time));
        
        // Log the daily reset
        error_log("IMS Daily Reset started at: $lagos_time");
        
        try {
            // Call integration class method to handle daily reset
            IMS_Integration::reset_daily_values();
            
            // Record the reset date
            update_option('ims_last_daily_reset_date', $today);
            
            // Clean up old integration tracking records (older than 30 days)
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}ims_integration_tracking 
                 WHERE created_at < %s",
                date('Y-m-d H:i:s', strtotime('-30 days'))
            ));
            
            // Log successful reset
            error_log("IMS Daily Reset completed successfully");
            
        } catch (Exception $e) {
            error_log("IMS Daily Reset failed: " . $e->getMessage());
        }
    }
    
    public function send_low_stock_alerts() {
        global $wpdb;
        
        $threshold = get_option('ims_low_stock_threshold', 10);
        $today = date('Y-m-d', strtotime(ims_get_lagos_time()));
        
        // Get low stock items
        $low_stock_items = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT product, closing_packs 
             FROM {$wpdb->prefix}ims_stock 
             WHERE closing_packs <= %d AND DATE(date_created) = %s
             ORDER BY closing_packs ASC",
            $threshold, $today
        ));
        
        if (!empty($low_stock_items)) {
            $this->send_alert_email($low_stock_items);
        }
    }
    
    private function send_alert_email($low_stock_items) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = "[{$site_name}] Low Stock Alert - " . date('Y-m-d');
        
        $message = "The following items are running low in stock:\n\n";
        
        foreach ($low_stock_items as $item) {
            $message .= "- {$item->product}: {$item->closing_packs} packs remaining\n";
        }
        
        $message .= "\nPlease restock these items as soon as possible.\n\n";
        $message .= "This is an automated message from the Inventory Management System.";
        
        $headers = array(
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Content-Type: text/plain; charset=UTF-8'
        );
        
        wp_mail($admin_email, $subject, $message, $headers);
        
        // Log the alert
        error_log("IMS Low Stock Alert sent for " . count($low_stock_items) . " items");
    }
    
    public static function clear_scheduled_events() {
        wp_clear_scheduled_hook('ims_daily_reset');
        wp_clear_scheduled_hook('ims_low_stock_alert');
    }
    
    public static function reschedule_events() {
        self::clear_scheduled_events();
        
        // Schedule daily reset using WordPress built-in 'daily' schedule as fallback
        // The custom 'daily_reset' schedule is also registered via cron_schedules filter
        if (!wp_next_scheduled('ims_daily_reset')) {
            wp_schedule_event(strtotime('tomorrow 00:00:00'), 'daily', 'ims_daily_reset');
        }
        
        // Schedule low stock alerts every hour
        if (!wp_next_scheduled('ims_low_stock_alert')) {
            wp_schedule_event(time(), 'hourly', 'ims_low_stock_alert');
        }
    }
}
?>