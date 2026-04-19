<?php
/**
 * Analytics class for handling analytics and reporting
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMS_Analytics {
    
    public static function get_dashboard_data() {
        global $wpdb;
        
        $today = date('Y-m-d', strtotime(ims_get_lagos_time()));
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        $week_ago = date('Y-m-d', strtotime($today . ' -7 days'));
        $month_ago = date('Y-m-d', strtotime($today . ' -30 days'));
        
        return array(
            'totals' => self::get_total_counts(),
            'today' => self::get_daily_stats($today),
            'yesterday' => self::get_daily_stats($yesterday),
            'weekly' => self::get_period_stats($week_ago, $today),
            'monthly' => self::get_period_stats($month_ago, $today),
            'low_stock' => self::get_low_stock_items(),
            'top_products' => self::get_top_products(),
            'trends' => self::get_trend_data()
        );
    }
    
    private static function get_total_counts() {
        global $wpdb;
        
        $import_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ims_imports");
        $stock_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ims_stock");
        $chopped_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ims_chopped");
        $products_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ims_products WHERE is_active = 1");
        
        return array(
            'imports' => intval($import_count),
            'stock' => intval($stock_count),
            'chopped' => intval($chopped_count),
            'products' => intval($products_count)
        );
    }
    
    private static function get_daily_stats($date) {
        global $wpdb;
        
        $import_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ims_imports WHERE DATE(date_created) = %s",
            $date
        ));
        
        $import_quantity = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantity) FROM {$wpdb->prefix}ims_imports WHERE DATE(date_created) = %s",
            $date
        ));
        
        $stock_updates = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT product) FROM {$wpdb->prefix}ims_stock WHERE DATE(date_created) = %s",
            $date
        ));
        
        $chopped_updates = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT fruit) FROM {$wpdb->prefix}ims_chopped WHERE DATE(date_created) = %s",
            $date
        ));
        
        return array(
            'import_count' => intval($import_count),
            'import_quantity' => floatval($import_quantity),
            'stock_updates' => intval($stock_updates),
            'chopped_updates' => intval($chopped_updates)
        );
    }
    
    private static function get_period_stats($start_date, $end_date) {
        global $wpdb;
        
        $import_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ims_imports 
             WHERE DATE(date_created) BETWEEN %s AND %s",
            $start_date, $end_date
        ));
        
        $import_quantity = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantity) FROM {$wpdb->prefix}ims_imports 
             WHERE DATE(date_created) BETWEEN %s AND %s",
            $start_date, $end_date
        ));
        
        return array(
            'import_count' => intval($import_count),
            'import_quantity' => floatval($import_quantity)
        );
    }
    
    private static function get_low_stock_items() {
        global $wpdb;
        
        $threshold = get_option('ims_low_stock_threshold', 10);
        $today = date('Y-m-d', strtotime(ims_get_lagos_time()));
        
        $low_stock = $wpdb->get_results($wpdb->prepare(
            "SELECT product, closing_packs 
             FROM {$wpdb->prefix}ims_stock 
             WHERE closing_packs <= %d AND DATE(date_created) = %s
             ORDER BY closing_packs ASC
             LIMIT 10",
            $threshold, $today
        ));
        
        return $low_stock;
    }
    
    private static function get_top_products() {
        global $wpdb;
        
        $week_ago = date('Y-m-d', strtotime('-7 days'));
        
        $top_imports = $wpdb->get_results($wpdb->prepare(
            "SELECT product, SUM(quantity) as total_quantity, COUNT(*) as import_count
             FROM {$wpdb->prefix}ims_imports 
             WHERE DATE(date_created) >= %s
             GROUP BY product 
             ORDER BY total_quantity DESC 
             LIMIT 5",
            $week_ago
        ));
        
        return $top_imports;
    }
    
    private static function get_trend_data() {
        global $wpdb;
        
        $days = array();
        $import_data = array();
        $stock_data = array();
        
        // Get last 7 days data
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $days[] = $date;
            
            $import_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ims_imports WHERE DATE(date_created) = %s",
                $date
            ));
            
            $stock_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ims_stock WHERE DATE(date_created) = %s",
                $date
            ));
            
            $import_data[] = intval($import_count);
            $stock_data[] = intval($stock_count);
        }
        
        return array(
            'labels' => $days,
            'imports' => $import_data,
            'stock' => $stock_data
        );
    }
    
    public static function generate_report($type = 'weekly', $format = 'array') {
        global $wpdb;
        
        $end_date = date('Y-m-d', strtotime(ims_get_lagos_time()));
        
        switch ($type) {
            case 'daily':
                $start_date = $end_date;
                break;
            case 'weekly':
                $start_date = date('Y-m-d', strtotime($end_date . ' -7 days'));
                break;
            case 'monthly':
                $start_date = date('Y-m-d', strtotime($end_date . ' -30 days'));
                break;
            default:
                $start_date = date('Y-m-d', strtotime($end_date . ' -7 days'));
        }
        
        $report_data = array(
            'period' => array(
                'start' => $start_date,
                'end' => $end_date,
                'type' => $type
            ),
            'summary' => self::get_period_stats($start_date, $end_date),
            'imports' => self::get_import_report($start_date, $end_date),
            'stock' => self::get_stock_report($start_date, $end_date),
            'chopped' => self::get_chopped_report($start_date, $end_date),
            'alerts' => self::get_alerts_report($start_date, $end_date)
        );
        
        if ($format === 'json') {
            return json_encode($report_data);
        }
        
        return $report_data;
    }
    
    private static function get_import_report($start_date, $end_date) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT product, SUM(quantity) as total_quantity, COUNT(*) as import_count,
                    AVG(quantity) as avg_quantity
             FROM {$wpdb->prefix}ims_imports 
             WHERE DATE(date_created) BETWEEN %s AND %s
             GROUP BY product 
             ORDER BY total_quantity DESC",
            $start_date, $end_date
        ));
    }
    
    private static function get_stock_report($start_date, $end_date) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT product, 
                    AVG(opening_packs) as avg_opening,
                    AVG(added_packs) as avg_added,
                    AVG(used_packs) as avg_used,
                    AVG(closing_packs) as avg_closing,
                    COUNT(*) as update_count
             FROM {$wpdb->prefix}ims_stock 
             WHERE DATE(date_created) BETWEEN %s AND %s
             GROUP BY product 
             ORDER BY avg_closing DESC",
            $start_date, $end_date
        ));
    }
    
    private static function get_chopped_report($start_date, $end_date) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT fruit,
                    AVG(opening_whole) as avg_opening,
                    AVG(import_whole) as avg_import,
                    AVG(prepared_whole) as avg_prepared,
                    AVG(closing_whole) as avg_closing,
                    SUM(packs_gotten) as total_packs_gotten,
                    COUNT(*) as update_count
             FROM {$wpdb->prefix}ims_chopped 
             WHERE DATE(date_created) BETWEEN %s AND %s
             GROUP BY fruit 
             ORDER BY total_packs_gotten DESC",
            $start_date, $end_date
        ));
    }
    
    private static function get_alerts_report($start_date, $end_date) {
        global $wpdb;
        
        $threshold = get_option('ims_low_stock_threshold', 10);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(date_created) as alert_date, product, closing_packs
             FROM {$wpdb->prefix}ims_stock 
             WHERE closing_packs <= %d 
             AND DATE(date_created) BETWEEN %s AND %s
             ORDER BY alert_date DESC, closing_packs ASC",
            $threshold, $start_date, $end_date
        ));
    }
}
?>