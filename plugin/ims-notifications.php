<?php
/**
 * IMS Real-Time Notification System
 * Mobile, Tablet & Desktop Notifications
 * 
 * Current Time: 2025-08-03 10:50:55 UTC
 * Current User: Officialese
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access not allowed.');
}

// Only run if main IMS plugin is active
if (!function_exists('ims_get_lagos_time')) {
    return;
}

// ========== NOTIFICATION CORE SYSTEM ==========

class IMS_Notification_System {
    
    private $notifications_table;
    private $user_settings_table;
    
    public function __construct() {
        global $wpdb;
        $this->notifications_table = $wpdb->prefix . 'ims_notifications';
        $this->user_settings_table = $wpdb->prefix . 'ims_notification_settings';
        
        add_action('init', array($this, 'init_hooks'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_notification_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_notification_assets'));
        add_action('wp_ajax_ims_get_notifications', array($this, 'ajax_get_notifications'));
        add_action('wp_ajax_ims_mark_notification_read', array($this, 'ajax_mark_notification_read'));
        add_action('wp_ajax_ims_update_notification_settings', array($this, 'ajax_update_notification_settings'));
        
        // Create tables on activation
        add_action('admin_init', array($this, 'create_notification_tables'));
    }
    
    public function init_hooks() {
        // Hook into all IMS form submissions
        add_action('ims_import_submitted', array($this, 'handle_import_notification'), 10, 3);
        add_action('ims_chopped_submitted', array($this, 'handle_chopped_notification'), 10, 3);
        add_action('ims_stock_submitted', array($this, 'handle_stock_notification'), 10, 3);
        
        // Add notification bar to all pages
        add_action('wp_footer', array($this, 'render_notification_bar'));
        add_action('admin_footer', array($this, 'render_notification_bar'));
    }
    
    public function create_notification_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Notifications table
        $notifications_sql = "CREATE TABLE IF NOT EXISTS {$this->notifications_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            message text NOT NULL,
            data longtext DEFAULT NULL,
            is_read tinyint(1) DEFAULT 0,
            created_at datetime NOT NULL,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_type (type),
            KEY idx_created_at (created_at),
            KEY idx_is_read (is_read)
        ) $charset_collate;";
        
        // User settings table
        $settings_sql = "CREATE TABLE IF NOT EXISTS {$this->user_settings_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            setting_name varchar(100) NOT NULL,
            setting_value text NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_setting (user_id, setting_name)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($notifications_sql);
        dbDelta($settings_sql);
        
        error_log("IMS NOTIFICATIONS: Database tables created/verified at 2025-08-03 10:50:55");
    }
    
    public function enqueue_notification_assets() {
        if (!is_user_logged_in()) {
            return;
        }
        
        // Inline CSS for notifications
        wp_add_inline_style('wp-admin', $this->get_notification_css());
        
        // Inline JavaScript for notifications
        wp_add_inline_script('jquery', $this->get_notification_js());
    }
    
    public function handle_import_notification($imported_count, $total_quantity, $products) {
        $current_user = wp_get_current_user();
        $lagos_time = ims_get_lagos_time();
        
        $title = "<iconify-icon icon=\"solar:box-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon> Import Completed";
        $message = "Successfully imported {$imported_count} products (Total: " . number_format($total_quantity, 2) . " units)";
        
        $data = array(
            'imported_count' => $imported_count,
            'total_quantity' => $total_quantity,
            'products' => $products,
            'time' => $lagos_time,
            'integration_status' => 'auto_updated'
        );
        
        $this->create_notification('import', $title, $message, $data);
        
        // Broadcast to all IMS users
        $this->broadcast_notification('import', $title, $message . " by " . $current_user->display_name, $data);
        
        error_log("IMS NOTIFICATION: Import notification created for {$imported_count} products at 2025-08-03 10:50:55");
    }
    
    public function handle_chopped_notification($saved_count, $remarks_count, $user_type) {
        $current_user = wp_get_current_user();
        $lagos_time = ims_get_lagos_time();
        
        $title = "<iconify-icon icon=\"solar:scissors-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon> Chopped Form Updated";
        $message = "{$user_type} updated {$saved_count} fruits";
        
        if ($remarks_count > 0) {
            $message .= " with {$remarks_count} remarks";
        }
        
        $data = array(
            'saved_count' => $saved_count,
            'remarks_count' => $remarks_count,
            'user_type' => $user_type,
            'time' => $lagos_time
        );
        
        $this->create_notification('chopped', $title, $message, $data);
        
        // Broadcast to relevant users
        $this->broadcast_notification('chopped', $title, $message . " by " . $current_user->display_name, $data);
        
        error_log("IMS NOTIFICATION: Chopped notification created for {$saved_count} fruits at 2025-08-03 10:50:55");
    }
    
    public function handle_stock_notification($saved_count, $user_type, $products) {
        $current_user = wp_get_current_user();
        $lagos_time = ims_get_lagos_time();
        
        $title = "<iconify-icon icon=\"solar:chart-2-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon> Stock Form Updated";
        $message = "{$user_type} updated {$saved_count} products";
        
        $data = array(
            'saved_count' => $saved_count,
            'user_type' => $user_type,
            'products' => $products,
            'time' => $lagos_time
        );
        
        $this->create_notification('stock', $title, $message, $data);
        
        // Broadcast to relevant users
        $this->broadcast_notification('stock', $title, $message . " by " . $current_user->display_name, $data);
        
        error_log("IMS NOTIFICATION: Stock notification created for {$saved_count} products at 2025-08-03 10:50:55");
    }
    
    public function create_notification($type, $title, $message, $data = array(), $user_id = null) {
        global $wpdb;
        
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $lagos_time = ims_get_lagos_time();
        $expires_at = date('Y-m-d H:i:s', strtotime($lagos_time . ' +24 hours'));
        
        $result = $wpdb->insert(
            $this->notifications_table,
            array(
                'user_id' => $user_id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => json_encode($data),
                'is_read' => 0,
                'created_at' => $lagos_time,
                'expires_at' => $expires_at
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($result !== false) {
            error_log("IMS NOTIFICATION: Created notification ID {$wpdb->insert_id} for user {$user_id}");
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    public function broadcast_notification($type, $title, $message, $data = array()) {
        // Get all IMS users (users who can submit forms)
        $users = get_users(array(
            'capability' => 'read',
            'fields' => 'ID'
        ));
        
        $current_user_id = get_current_user_id();
        
        foreach ($users as $user_id) {
            // Don't send to current user (they already have their own notification)
            if ($user_id == $current_user_id) {
                continue;
            }
            
            $this->create_notification($type, $title, $message, $data, $user_id);
        }
    }
    
    public function ajax_get_notifications() {
        if (!is_user_logged_in()) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->notifications_table} 
             WHERE user_id = %d AND (expires_at IS NULL OR expires_at > NOW()) 
             ORDER BY created_at DESC LIMIT 20",
            $user_id
        ));
        
        $unread_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->notifications_table} 
             WHERE user_id = %d AND is_read = 0 AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id
        ));
        
        wp_send_json_success(array(
            'notifications' => $notifications,
            'unread_count' => intval($unread_count),
            'timestamp' => current_time('mysql')
        ));
    }
    
    public function ajax_mark_notification_read() {
        if (!is_user_logged_in()) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $notification_id = intval($_POST['notification_id']);
        $user_id = get_current_user_id();
        
        $result = $wpdb->update(
            $this->notifications_table,
            array('is_read' => 1),
            array('id' => $notification_id, 'user_id' => $user_id),
            array('%d'),
            array('%d', '%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Notification marked as read'));
        } else {
            wp_send_json_error(array('message' => 'Failed to update notification'));
        }
    }
    
    public function get_notification_css() {
        return '
        /* IMS Notification System Styles */
        #ims-notification-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999999;
            background: linear-gradient(135deg, #FF0000 0%, #cc0000 100%);
            color: white;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            transform: translateY(-100%);
            transition: transform 0.3s ease-in-out;
        }
        
        #ims-notification-bar.show {
            transform: translateY(0);
        }
        
        .ims-notification-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .ims-notification-icon {
            font-size: 24px;
            margin-right: 12px;
            animation: pulse 2s infinite;
        }
        
        .ims-notification-text {
            flex: 1;
            font-weight: 600;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .ims-notification-time {
            font-size: 12px;
            opacity: 0.9;
            margin-left: 15px;
        }
        
        .ims-notification-actions {
            display: flex;
            gap: 10px;
            margin-left: 15px;
        }
        
        .ims-notification-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .ims-notification-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-1px);
        }
        
        .ims-notification-close {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }
        
        .ims-notification-close:hover {
            opacity: 1;
        }
        
        .ims-notification-counter {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #FF0000;
            color: white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            z-index: 999998;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(255,0,0,0.3);
            transition: all 0.3s ease;
        }
        
        .ims-notification-counter:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(255,0,0,0.4);
        }
        
        .ims-notification-counter.hidden {
            display: none;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .ims-notification-content {
                padding: 10px 15px;
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .ims-notification-text {
                font-size: 13px;
            }
            
            .ims-notification-time {
                font-size: 11px;
                margin-left: 0;
            }
            
            .ims-notification-actions {
                margin-left: 0;
                flex-wrap: wrap;
            }
            
            .ims-notification-counter {
                top: 10px;
                right: 10px;
                width: 35px;
                height: 35px;
                font-size: 12px;
            }
        }
        
        /* Tablet */
        @media (min-width: 769px) and (max-width: 1024px) {
            .ims-notification-content {
                padding: 11px 18px;
            }
            
            .ims-notification-text {
                font-size: 13.5px;
            }
        }
        
        /* Desktop */
        @media (min-width: 1025px) {
            .ims-notification-content {
                padding: 12px 20px;
            }
        }
        
        /* Animations */
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        @keyframes slideInDown {
            from { transform: translateY(-100%); }
            to { transform: translateY(0); }
        }
        
        @keyframes slideOutUp {
            from { transform: translateY(0); }
            to { transform: translateY(-100%); }
        }
        
        .ims-notification-slide-in {
            animation: slideInDown 0.3s ease-out;
        }
        
        .ims-notification-slide-out {
            animation: slideOutUp 0.3s ease-in;
        }
        ';
    }
    
    public function get_notification_js() {
        $ajax_url = admin_url('admin-ajax.php');
        $current_user = wp_get_current_user();
        
        return "
        // IMS Notification System JavaScript
        class IMSNotificationSystem {
            constructor() {
                this.ajaxUrl = '{$ajax_url}';
                this.currentUser = '{$current_user->display_name}';
                this.notifications = [];
                this.unreadCount = 0;
                this.isVisible = false;
                this.checkInterval = null;
                this.currentNotification = null;
                
                this.init();
            }
            
            init() {
                console.log('IMS Notifications: Initializing system at 2025-08-03 10:50:55 for user: {$current_user->display_name}');
                
                this.createNotificationElements();
                this.startPolling();
                this.bindEvents();
                
                // Initial load
                this.loadNotifications();
            }
            
            createNotificationElements() {
                // Create notification bar
                const notificationBar = document.createElement('div');
                notificationBar.id = 'ims-notification-bar';
                notificationBar.innerHTML = `
                    <div class='ims-notification-content'>
                        <div style='display: flex; align-items: center;'>
                            <span class='ims-notification-icon'><iconify-icon icon=\"solar:notification-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon></span>
                            <div class='ims-notification-text'>Loading notifications...</div>
                        </div>
                        <div style='display: flex; align-items: center;'>
                            <div class='ims-notification-time'></div>
                            <div class='ims-notification-actions'></div>
                            <button class='ims-notification-close' onclick='imsNotifications.hideNotification()'>&times;</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(notificationBar);
                
                // Create notification counter
                const notificationCounter = document.createElement('div');
                notificationCounter.id = 'ims-notification-counter';
                notificationCounter.className = 'ims-notification-counter hidden';
                notificationCounter.onclick = () => this.showLatestNotification();
                document.body.appendChild(notificationCounter);
            }
            
            bindEvents() {
                // Listen for custom events from form submissions
                document.addEventListener('ims_form_submitted', (e) => {
                    this.handleFormSubmission(e.detail);
                });
                
                // Visibility change detection
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) {
                        this.loadNotifications();
                    }
                });
            }
            
            startPolling() {
                // Check for new notifications every 10 seconds
                this.checkInterval = setInterval(() => {
                    this.loadNotifications();
                }, 10000);
            }
            
            loadNotifications() {
                jQuery.post(this.ajaxUrl, {
                    action: 'ims_get_notifications',
                    security: '" . wp_create_nonce('ims_notifications') . "'
                }, (response) => {
                    if (response.success) {
                        this.updateNotifications(response.data.notifications, response.data.unread_count);
                    }
                });
            }
            
            updateNotifications(notifications, unreadCount) {
                const previousUnreadCount = this.unreadCount;
                this.notifications = notifications;
                this.unreadCount = unreadCount;
                
                this.updateCounter();
                
                // Show notification if there are new unread notifications
                if (unreadCount > previousUnreadCount && !this.isVisible) {
                    const latestNotification = notifications.find(n => n.is_read == 0);
                    if (latestNotification) {
                        this.showNotification(latestNotification);
                    }
                }
            }
            
            updateCounter() {
                const counter = document.getElementById('ims-notification-counter');
                if (this.unreadCount > 0) {
                    counter.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
                    counter.classList.remove('hidden');
                } else {
                    counter.classList.add('hidden');
                }
            }
            
            showNotification(notification) {
                if (this.isVisible) return;
                
                this.currentNotification = notification;
                this.isVisible = true;
                
                const bar = document.getElementById('ims-notification-bar');
                const content = bar.querySelector('.ims-notification-content');
                
                // Update content
                const icon = this.getNotificationIcon(notification.type);
                const timeAgo = this.timeAgo(notification.created_at);
                
                content.innerHTML = `
                    <div style='display: flex; align-items: center; flex: 1;'>
                        <span class='ims-notification-icon'>\${icon}</span>
                        <div class='ims-notification-text'>
                            <strong>\${notification.title}</strong><br>
                            \${notification.message}
                        </div>
                    </div>
                    <div style='display: flex; align-items: center;'>
                        <div class='ims-notification-time'>\${timeAgo}</div>
                        <div class='ims-notification-actions'>
                            <button class='ims-notification-btn' onclick='imsNotifications.markAsRead(\${notification.id})'>Mark Read</button>
                            <button class='ims-notification-btn' onclick='imsNotifications.viewDetails(\${notification.id})'>Details</button>
                        </div>
                        <button class='ims-notification-close' onclick='imsNotifications.hideNotification()'>&times;</button>
                    </div>
                `;
                
                // Show with animation
                bar.classList.add('show');
                
                // Auto-hide after 10 seconds
                setTimeout(() => {
                    if (this.isVisible && this.currentNotification && this.currentNotification.id === notification.id) {
                        this.hideNotification();
                    }
                }, 10000);
                
                console.log('IMS Notifications: Showing notification:', notification);
            }
            
            hideNotification() {
                const bar = document.getElementById('ims-notification-bar');
                bar.classList.remove('show');
                this.isVisible = false;
                this.currentNotification = null;
            }
            
            showLatestNotification() {
                const unreadNotification = this.notifications.find(n => n.is_read == 0);
                if (unreadNotification) {
                    this.showNotification(unreadNotification);
                }
            }
            
            markAsRead(notificationId) {
                jQuery.post(this.ajaxUrl, {
                    action: 'ims_mark_notification_read',
                    notification_id: notificationId,
                    security: '" . wp_create_nonce('ims_notifications') . "'
                }, (response) => {
                    if (response.success) {
                        this.loadNotifications();
                        this.hideNotification();
                    }
                });
            }
            
            viewDetails(notificationId) {
                const notification = this.notifications.find(n => n.id == notificationId);
                if (notification) {
                    const data = JSON.parse(notification.data || '{}');
                    
                    let detailsHtml = `
                        <h3>\${notification.title}</h3>
                        <p><strong>Message:</strong> \${notification.message}</p>
                        <p><strong>Time:</strong> \${notification.created_at}</p>
                        <p><strong>Type:</strong> \${notification.type}</p>
                    `;
                    
                    if (data.imported_count) {
                        detailsHtml += `<p><strong>Imported:</strong> \${data.imported_count} products</p>`;
                    }
                    if (data.saved_count) {
                        detailsHtml += `<p><strong>Saved:</strong> \${data.saved_count} items</p>`;
                    }
                    if (data.remarks_count) {
                        detailsHtml += `<p><strong>Remarks:</strong> \${data.remarks_count} added</p>`;
                    }
                    
                    // Create modal or alert (simple version)
                    alert(detailsHtml.replace(/<[^>]*>/g, '\\n').replace(/&nbsp;/g, ' '));
                }
            }
            
            getNotificationIcon(type) {
                const icons = {
                    'import': '<iconify-icon icon=\"solar:box-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon>',
                    'chopped': '<iconify-icon icon=\"solar:scissors-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon>',
                    'stock': '<iconify-icon icon=\"solar:chart-2-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon>',
                    'system': '<iconify-icon icon=\"solar:settings-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon>',
                    'error': '<iconify-icon icon=\"solar:close-circle-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon>',
                    'success': '<iconify-icon icon=\"solar:check-circle-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon>'
                };
                return icons[type] || '<iconify-icon icon=\"solar:notification-linear\" style=\"font-size:1.2em;vertical-align:middle;\"></iconify-icon>';
            }
            
            timeAgo(datetime) {
                const now = new Date();
                const time = new Date(datetime);
                const diffInSeconds = Math.floor((now - time) / 1000);
                
                if (diffInSeconds < 60) return 'Just now';
                if (diffInSeconds < 3600) return Math.floor(diffInSeconds / 60) + 'm ago';
                if (diffInSeconds < 86400) return Math.floor(diffInSeconds / 3600) + 'h ago';
                return Math.floor(diffInSeconds / 86400) + 'd ago';
            }
            
            handleFormSubmission(data) {
                // Trigger immediate notification check after form submission
                setTimeout(() => {
                    this.loadNotifications();
                }, 1000);
            }
            
            destroy() {
                if (this.checkInterval) {
                    clearInterval(this.checkInterval);
                }
                
                const bar = document.getElementById('ims-notification-bar');
                const counter = document.getElementById('ims-notification-counter');
                
                if (bar) bar.remove();
                if (counter) counter.remove();
            }
        }
        
        // Initialize notification system when DOM is ready
        jQuery(document).ready(function() {
            window.imsNotifications = new IMSNotificationSystem();
            
            console.log('IMS Notifications: System initialized for user {$current_user->display_name} at 2025-08-03 10:50:55');
        });
        ";
    }
    
    public function render_notification_bar() {
        if (!is_user_logged_in()) {
            return;
        }
        
        // The notification bar and counter are created dynamically by JavaScript
        // This ensures they appear on all pages without conflicting with themes
    }
}

// ========== INTEGRATION WITH EXISTING FORMS ==========

// Hook into existing form submissions to trigger notifications
add_action('init', function() {
    // Import form integration
    if ($_POST && isset($_POST['ims_import_nonce']) && wp_verify_nonce($_POST['ims_import_nonce'], 'ims_import_form')) {
        add_action('wp_loaded', function() {
            $quantities = $_POST['quantity'] ?? array();
            $imported_count = 0;
            $total_quantity = 0;
            $products = array();
            
            foreach ($quantities as $product => $quantity) {
                if (floatval($quantity) > 0) {
                    $imported_count++;
                    $total_quantity += floatval($quantity);
                    $products[] = $product;
                }
            }
            
            if ($imported_count > 0) {
                do_action('ims_import_submitted', $imported_count, $total_quantity, $products);
            }
        }, 999);
    }
    
    // Chopped form integration
    if ($_POST && isset($_POST['ims_chopped_nonce']) && wp_verify_nonce($_POST['ims_chopped_nonce'], 'ims_chopped_form')) {
        add_action('wp_loaded', function() {
            $prepared_values = $_POST['prepared'] ?? array();
            $packs_values = $_POST['packs'] ?? array();
            $remarks_values = $_POST['remarks'] ?? array();
            
            $saved_count = 0;
            $remarks_count = 0;
            
            foreach ($prepared_values as $fruit => $prepared) {
                if (floatval($prepared) > 0 || floatval($packs_values[$fruit] ?? 0) > 0 || !empty($remarks_values[$fruit] ?? '')) {
                    $saved_count++;
                    if (!empty($remarks_values[$fruit] ?? '')) {
                        $remarks_count++;
                    }
                }
            }
            
            if ($saved_count > 0) {
                $user_type = ims_user_can_edit_all_fields() ? 'Admin' : 'Staff';
                do_action('ims_chopped_submitted', $saved_count, $remarks_count, $user_type);
            }
        }, 999);
    }
    
    // Stock form integration
    if ($_POST && isset($_POST['ims_stock_nonce']) && wp_verify_nonce($_POST['ims_stock_nonce'], 'ims_stock_form')) {
        add_action('wp_loaded', function() {
            $opening_values = $_POST['opening'] ?? array();
            $used_values = $_POST['used'] ?? array();
            
            $saved_count = 0;
            $products = array();
            
            foreach ($opening_values as $product => $opening) {
                if (floatval($opening) > 0 || floatval($used_values[$product] ?? 0) > 0) {
                    $saved_count++;
                    $products[] = $product;
                }
            }
            
            if ($saved_count > 0) {
                $user_type = ims_user_can_edit_all_fields() ? 'Admin' : 'Staff';
                do_action('ims_stock_submitted', $saved_count, $user_type, $products);
            }
        }, 999);
    }
});

// ========== ADMIN SETTINGS PAGE ==========

add_action('admin_menu', function() {
    add_submenu_page(
        'inventory-management',
        'Notification Settings',
        'Notifications',
        'manage_options',
        'ims-notifications',
        'ims_notifications_settings_page'
    );
});

function ims_notifications_settings_page() {
    global $wpdb;
    $notifications_table = $wpdb->prefix . 'ims_notifications';
    
    // Get notification statistics
    $total_notifications = $wpdb->get_var("SELECT COUNT(*) FROM $notifications_table");
    $unread_notifications = $wpdb->get_var("SELECT COUNT(*) FROM $notifications_table WHERE is_read = 0");
    $today_notifications = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $notifications_table WHERE DATE(created_at) = %s",
        date('Y-m-d')
    ));
    
    // Get recent notifications
    $recent_notifications = $wpdb->get_results(
        "SELECT * FROM $notifications_table ORDER BY created_at DESC LIMIT 10"
    );
    
    $current_user = wp_get_current_user();
    $lagos_time = ims_get_lagos_time();
    
    ?>
    <div class="wrap">
        <h1><iconify-icon icon="solar:notification-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> IMS Notification Settings</h1>
        
        <div style="background: #fff; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #ddd;">
            <h2>Notification Statistics</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <div style="background: #f0f8ff; padding: 15px; border-radius: 8px; text-align: center;">
                    <h3 style="margin: 0; color: #0066cc;"><?php echo $total_notifications; ?></h3>
                    <p style="margin: 5px 0 0 0;">Total Notifications</p>
                </div>
                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; text-align: center;">
                    <h3 style="margin: 0; color: #856404;"><?php echo $unread_notifications; ?></h3>
                    <p style="margin: 5px 0 0 0;">Unread</p>
                </div>
                <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center;">
                    <h3 style="margin: 0; color: #155724;"><?php echo $today_notifications; ?></h3>
                    <p style="margin: 5px 0 0 0;">Today</p>
                </div>
            </div>
            
            <p><strong>Current Time:</strong> <?php echo $lagos_time; ?> (Lagos)</p>
            <p><strong>Current User:</strong> <?php echo esc_html($current_user->display_name); ?></p>
        </div>
        
        <div style="background: #fff; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #ddd;">
            <h2>Recent Notifications</h2>
            <?php if (!empty($recent_notifications)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Message</th>
                            <th>User</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_notifications as $notification): 
                            $user = get_userdata($notification->user_id);
                            $icons = array('import' => '<iconify-icon icon="solar:box-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>', 'chopped' => '<iconify-icon icon="solar:scissors-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>', 'stock' => '<iconify-icon icon="solar:chart-2-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>');
                            $icon = $icons[$notification->type] ?? '<iconify-icon icon="solar:notification-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>';
                        ?>
                            <tr>
                                <td><?php echo $icon; ?> <?php echo esc_html($notification->type); ?></td>
                                <td><strong><?php echo esc_html($notification->title); ?></strong></td>
                                <td><?php echo esc_html($notification->message); ?></td>
                                <td><?php echo $user ? esc_html($user->display_name) : 'Unknown'; ?></td>
                                <td>
                                    <?php if ($notification->is_read): ?>
                                        <span style="color: #28a745;"><iconify-icon icon="solar:check-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Read</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">📬 Unread</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($notification->created_at)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No notifications found.</p>
            <?php endif; ?>
        </div>
        
        <div style="background: #e1f5fe; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #0288d1;">
            <h3 style="color: #0277bd; margin-top: 0;"><iconify-icon icon="solar:notification-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Notification Features</h3>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li><strong>Real-time Notifications:</strong> Instant notifications for all IMS form submissions</li>
                <li><strong>Multi-device Support:</strong> Works on mobile, tablet, and desktop</li>
                <li><strong>Auto-polling:</strong> Checks for new notifications every 10 seconds</li>
                <li><strong>Responsive Design:</strong> Optimized for all screen sizes</li>
                <li><strong>User Broadcasting:</strong> Notifications sent to all relevant IMS users</li>
                <li><strong>Persistent Storage:</strong> Notifications stored for 24 hours</li>
            </ul>
            <p style="margin: 0; font-size: 0.9rem; color: #0277bd;">
                System Time: 2025-08-03 10:50:55 UTC | Initialized for: <?php echo esc_html($current_user->display_name); ?>
            </p>
        </div>
    </div>
    <?php
}

// Initialize the notification system
new IMS_Notification_System();

error_log("IMS NOTIFICATIONS: Notification system loaded successfully at 2025-08-03 10:50:55 for user: Officialese");

?>