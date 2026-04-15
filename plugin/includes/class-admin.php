<?php
/**
 * Admin class for handling admin functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_ims_admin_delete_record', array($this, 'delete_record'));
        add_action('wp_ajax_ims_admin_edit_record', array($this, 'edit_record'));
        add_action('wp_ajax_ims_admin_clear_all_records', array($this, 'clear_all_records'));
        add_action('wp_ajax_ims_admin_manage_products', array($this, 'manage_products'));
        add_action('wp_ajax_ims_admin_export_data', array($this, 'export_data'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Inventory Management',
            'Inventory',
            'manage_options',
            'inventory-management',
            array($this, 'admin_dashboard'),
            'dashicons-clipboard',
            30
        );
        
        add_submenu_page(
            'inventory-management',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'inventory-management',
            array($this, 'admin_dashboard')
        );
        
        add_submenu_page(
            'inventory-management',
            'Manage Products',
            'Products',
            'manage_options',
            'ims-products',
            array($this, 'manage_products_page')
        );
        
        add_submenu_page(
            'inventory-management',
            'Import Records',
            'Import Records',
            'manage_options',
            'ims-import-records',
            array($this, 'import_records_page')
        );
        
        add_submenu_page(
            'inventory-management',
            'Stock Records',
            'Stock Records',
            'manage_options',
            'ims-stock-records',
            array($this, 'stock_records_page')
        );
        
        add_submenu_page(
            'inventory-management',
            'Chopped Records',
            'Chopped Records',
            'manage_options',
            'ims-chopped-records',
            array($this, 'chopped_records_page')
        );
        
        add_submenu_page(
            'inventory-management',
            'Settings',
            'Settings',
            'manage_options',
            'ims-settings',
            array($this, 'settings_page')
        );
    }
    
    public function admin_dashboard() {
        $analytics = IMS_Database::get_analytics_data();
        ?>
        <div class="wrap ims-admin-dashboard">
            <h1>Inventory Management Dashboard</h1>
            
            <div class="ims-admin-analytics">
                <div class="ims-admin-card">
                    <div class="ims-card-icon"><iconify-icon icon="solar:box-linear"></iconify-icon></div>
                    <div class="ims-card-content">
                        <h3><?php echo number_format($analytics['imports']); ?></h3>
                        <p>Total Imports</p>
                    </div>
                </div>
                
                <div class="ims-admin-card">
                    <div class="ims-card-icon"><iconify-icon icon="solar:chart-2-linear"></iconify-icon></div>
                    <div class="ims-card-content">
                        <h3><?php echo number_format($analytics['stock']); ?></h3>
                        <p>Stock Records</p>
                    </div>
                </div>
                
                <div class="ims-admin-card">
                    <div class="ims-card-icon"><iconify-icon icon="solar:scissors-linear"></iconify-icon></div>
                    <div class="ims-card-content">
                        <h3><?php echo number_format($analytics['chopped']); ?></h3>
                        <p>Chopped Records</p>
                    </div>
                </div>
                
                <div class="ims-admin-card ims-warning-card">
                    <div class="ims-card-icon"><iconify-icon icon="solar:danger-triangle-linear"></iconify-icon></div>
                    <div class="ims-card-content">
                        <h3><?php echo number_format($analytics['low_stock']); ?></h3>
                        <p>Low Stock Items</p>
                    </div>
                </div>
            </div>
            
            <div class="ims-admin-actions">
                <h2>Quick Actions</h2>
                <div class="ims-action-buttons">
                    <a href="<?php echo admin_url('admin.php?page=ims-products'); ?>" class="button button-primary">
                        Manage Products
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=ims-import-records'); ?>" class="button button-secondary">
                        View Import Records
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=ims-stock-records'); ?>" class="button button-secondary">
                        View Stock Records
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=ims-chopped-records'); ?>" class="button button-secondary">
                        View Chopped Records
                    </a>
                    <button type="button" id="ims-export-all-data" class="button button-secondary">
                        Export All Data
                    </button>
                </div>
            </div>
            
            <div class="ims-admin-recent">
                <h2>Recent Activity</h2>
                <?php $this->render_recent_activity(); ?>
            </div>
        </div>
        <?php
    }
    
    public function manage_products_page() {
        global $wpdb;
        
        // Handle form submissions
        if ($_POST['action'] ?? '' === 'add_product') {
            $this->handle_add_product();
        } elseif ($_POST['action'] ?? '' === 'edit_product') {
            $this->handle_edit_product();
        } elseif ($_POST['action'] ?? '' === 'delete_product') {
            $this->handle_delete_product();
        }
        
        $products = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ims_products ORDER BY sort_order ASC"
        );
        ?>
        <div class="wrap ims-admin-products">
            <h1>Manage Products</h1>
            
            <div class="ims-product-form">
                <h2>Add New Product</h2>
                <form method="post" id="ims-add-product-form">
                    <?php wp_nonce_field('ims_admin_nonce', 'ims_admin_nonce'); ?>
                    <input type="hidden" name="action" value="add_product">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="product_name">Product Name</label></th>
                            <td><input type="text" id="product_name" name="product_name" required class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="product_type">Product Type</label></th>
                            <td>
                                <select id="product_type" name="product_type">
                                    <option value="all">All Products</option>
                                    <option value="chopped">Chopped Only</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sort_order">Sort Order</label></th>
                            <td><input type="number" id="sort_order" name="sort_order" value="0" class="small-text"></td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Add Product">
                    </p>
                </form>
            </div>
            
            <div class="ims-products-list">
                <h2>Existing Products</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Sort Order</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo esc_html($product->id); ?></td>
                                <td><?php echo esc_html($product->name); ?></td>
                                <td><?php echo esc_html(ucfirst($product->type)); ?></td>
                                <td><?php echo $product->is_active ? 'Active' : 'Inactive'; ?></td>
                                <td><?php echo esc_html($product->sort_order); ?></td>
                                <td>
                                    <button type="button" class="button button-small ims-edit-product" 
                                            data-id="<?php echo esc_attr($product->id); ?>"
                                            data-name="<?php echo esc_attr($product->name); ?>"
                                            data-type="<?php echo esc_attr($product->type); ?>"
                                            data-active="<?php echo esc_attr($product->is_active); ?>"
                                            data-order="<?php echo esc_attr($product->sort_order); ?>">
                                        Edit
                                    </button>
                                    <button type="button" class="button button-small button-link-delete ims-delete-product" 
                                            data-id="<?php echo esc_attr($product->id); ?>"
                                            data-name="<?php echo esc_attr($product->name); ?>">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Edit Product Modal -->
        <div id="ims-edit-product-modal" class="ims-modal" style="display: none;">
            <div class="ims-modal-content">
                <span class="ims-modal-close">&times;</span>
                <h2>Edit Product</h2>
                <form id="ims-edit-product-form">
                    <?php wp_nonce_field('ims_admin_nonce', 'ims_edit_nonce'); ?>
                    <input type="hidden" name="action" value="edit_product">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="edit_product_name">Product Name</label></th>
                            <td><input type="text" id="edit_product_name" name="product_name" required class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_product_type">Product Type</label></th>
                            <td>
                                <select id="edit_product_type" name="product_type">
                                    <option value="all">All Products</option>
                                    <option value="chopped">Chopped Only</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_sort_order">Sort Order</label></th>
                            <td><input type="number" id="edit_sort_order" name="sort_order" class="small-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_is_active">Status</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="edit_is_active" name="is_active" value="1">
                                    Active
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Update Product">
                        <button type="button" class="button ims-modal-close">Cancel</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    public function import_records_page() {
        global $wpdb;
        
        $per_page = 50;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        $table = $wpdb->prefix . 'ims_imports';
        
        // Handle bulk actions
        if ($_POST['action'] ?? '' === 'bulk_delete') {
            $this->handle_bulk_delete_imports();
        }
        
        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        
        // Get records
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY date_created DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        
        $total_pages = ceil($total / $per_page);
        ?>
        <div class="wrap ims-admin-records">
            <h1>Import Records Management</h1>
            
            <div class="ims-admin-actions">
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('ims_admin_nonce', 'ims_admin_nonce'); ?>
                    <input type="hidden" name="action" value="clear_all_imports">
                    <button type="submit" class="button button-secondary" 
                            onclick="return confirm('Are you sure you want to clear all import records? This action cannot be undone.')">
                        Clear All Records
                    </button>
                </form>
                
                <button type="button" id="ims-export-imports" class="button button-secondary">
                    Export to CSV
                </button>
            </div>
            
            <form method="post" id="ims-bulk-actions-form">
                <?php wp_nonce_field('ims_admin_nonce', 'ims_bulk_nonce'); ?>
                <input type="hidden" name="action" value="bulk_delete">
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <input type="submit" class="button action" value="Apply">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="check-column"><input type="checkbox" id="cb-select-all"></td>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Staff Name</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Processed</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($records)): ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <th class="check-column">
                                        <input type="checkbox" name="record_ids[]" value="<?php echo esc_attr($record->id); ?>">
                                    </th>
                                    <td><?php echo esc_html($record->id); ?></td>
                                    <td><?php echo esc_html($record->product); ?></td>
                                    <td><?php echo esc_html(number_format($record->quantity, 2)); ?></td>
                                    <td><?php echo esc_html($record->staff_name); ?></td>
                                    <td><?php echo esc_html(date('Y-m-d', strtotime($record->date_created))); ?></td>
                                    <td><?php echo esc_html(date('H:i:s', strtotime($record->timestamp_created))); ?></td>
                                    <td><?php echo $record->processed ? '<iconify-icon icon="solar:check-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>' : '<iconify-icon icon="solar:close-circle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon>'; ?></td>
                                    <td>
                                        <button type="button" class="button button-small ims-edit-import" 
                                                data-id="<?php echo esc_attr($record->id); ?>"
                                                data-product="<?php echo esc_attr($record->product); ?>"
                                                data-quantity="<?php echo esc_attr($record->quantity); ?>">
                                            Edit
                                        </button>
                                        <button type="button" class="button button-small button-link-delete ims-delete-import" 
                                                data-id="<?php echo esc_attr($record->id); ?>">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="ims-no-data">No import records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $page,
                            'type' => 'array'
                        ));
                        
                        if ($page_links) {
                            echo '<span class="pagination-links">' . join(' ', $page_links) . '</span>';
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function stock_records_page() {
        // Similar structure to import_records_page but for stock table
        $this->render_records_page('stock', 'Stock Records Management');
    }
    
    public function chopped_records_page() {
        // Similar structure to import_records_page but for chopped table
        $this->render_records_page('chopped', 'Chopped Records Management');
    }
    
    private function render_records_page($type, $title) {
        global $wpdb;
        
        $per_page = 50;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        $table = $wpdb->prefix . 'ims_' . $type;
        
        // Handle bulk actions
        if ($_POST['action'] ?? '' === 'bulk_delete_' . $type) {
            $this->handle_bulk_delete_records($type);
        }
        
        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        
        // Get records
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY date_created DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        
        $total_pages = ceil($total / $per_page);
        ?>
        <div class="wrap ims-admin-records">
            <h1><?php echo esc_html($title); ?></h1>
            
            <div class="ims-admin-actions">
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('ims_admin_nonce', 'ims_admin_nonce'); ?>
                    <input type="hidden" name="action" value="clear_all_<?php echo esc_attr($type); ?>">
                    <button type="submit" class="button button-secondary" 
                            onclick="return confirm('Are you sure you want to clear all <?php echo esc_attr($type); ?> records? This action cannot be undone.')">
                        Clear All Records
                    </button>
                </form>
                
                <button type="button" id="ims-export-<?php echo esc_attr($type); ?>" class="button button-secondary">
                    Export to CSV
                </button>
            </div>
            
            <form method="post" id="ims-bulk-actions-form">
                <?php wp_nonce_field('ims_admin_nonce', 'ims_bulk_nonce'); ?>
                <input type="hidden" name="action" value="bulk_delete_<?php echo esc_attr($type); ?>">
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <input type="submit" class="button action" value="Apply">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="check-column"><input type="checkbox" id="cb-select-all"></td>
                            <?php $this->render_table_headers($type); ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($records)): ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <th class="check-column">
                                        <input type="checkbox" name="record_ids[]" value="<?php echo esc_attr($record->id); ?>">
                                    </th>
                                    <?php $this->render_table_row($type, $record); ?>
                                    <td>
                                        <button type="button" class="button button-small ims-edit-<?php echo esc_attr($type); ?>" 
                                                data-id="<?php echo esc_attr($record->id); ?>">
                                            Edit
                                        </button>
                                        <button type="button" class="button button-small button-link-delete ims-delete-<?php echo esc_attr($type); ?>" 
                                                data-id="<?php echo esc_attr($record->id); ?>">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo $this->get_table_column_count($type); ?>" class="ims-no-data">
                                    No <?php echo esc_html($type); ?> records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $page,
                            'type' => 'array'
                        ));
                        
                        if ($page_links) {
                            echo '<span class="pagination-links">' . join(' ', $page_links) . '</span>';
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_table_headers($type) {
        switch ($type) {
            case 'stock':
                echo '<th>ID</th><th>Product</th><th>Opening Packs</th><th>Added Packs</th><th>Used Packs</th><th>Closing Packs</th><th>Staff Name</th><th>Date</th><th>Time</th><th>Remarks</th>';
                break;
            case 'chopped':
                echo '<th>ID</th><th>Fruit</th><th>Opening (Whole)</th><th>Import (Whole)</th><th>Prepared (Whole)</th><th>Closing (Whole)</th><th>Pack(s) Gotten</th><th>Staff Name</th><th>Date</th><th>Time</th><th>Remarks</th>';
                break;
        }
    }
    
    private function render_table_row($type, $record) {
        switch ($type) {
            case 'stock':
                ?>
                <td><?php echo esc_html($record->id); ?></td>
                <td><?php echo esc_html($record->product); ?></td>
                <td><?php echo esc_html(number_format($record->opening_packs, 2)); ?></td>
                <td><?php echo esc_html(number_format($record->added_packs, 2)); ?></td>
                <td><?php echo esc_html(number_format($record->used_packs, 2)); ?></td>
                <td><?php echo esc_html(number_format($record->closing_packs, 2)); ?></td>
                <td><?php echo esc_html($record->staff_name); ?></td>
                <td><?php echo esc_html(date('Y-m-d', strtotime($record->date_created))); ?></td>
                <td><?php echo esc_html(date('H:i:s', strtotime($record->timestamp_created))); ?></td>
                <td><?php echo esc_html($record->remarks); ?></td>
                <?php
                break;
            case 'chopped':
                ?>
                <td><?php echo esc_html($record->id); ?></td>
                <td><?php echo esc_html($record->fruit); ?></td>
                <td><?php echo esc_html(number_format($record->opening_whole, 2)); ?></td>
                <td><?php echo esc_html(number_format($record->import_whole, 2)); ?></td>
                <td><?php echo esc_html(number_format($record->prepared_whole, 2)); ?></td>
                <td><?php echo esc_html(number_format($record->closing_whole, 2)); ?></td>
                <td><?php echo esc_html(number_format($record->packs_gotten, 2)); ?></td>
                <td><?php echo esc_html($record->staff_name); ?></td>
                <td><?php echo esc_html(date('Y-m-d', strtotime($record->date_created))); ?></td>
                <td><?php echo esc_html(date('H:i:s', strtotime($record->timestamp_created))); ?></td>
                <td><?php echo esc_html($record->remarks); ?></td>
                <?php
                break;
        }
    }
    
    private function get_table_column_count($type) {
        switch ($type) {
            case 'stock':
                return 12; // Including checkbox and actions
            case 'chopped':
                return 13; // Including checkbox and actions
            default:
                return 10;
        }
    }
    
    public function settings_page() {
        if ($_POST['action'] ?? '' === 'update_settings') {
            $this->handle_update_settings();
        }
        
        $low_stock_threshold = get_option('ims_low_stock_threshold', 10);
        $timezone = get_option('ims_timezone', 'Africa/Lagos');
        ?>
        <div class="wrap ims-admin-settings">
            <h1>Inventory Management Settings</h1>
            
            <form method="post">
                <?php wp_nonce_field('ims_admin_nonce', 'ims_admin_nonce'); ?>
                <input type="hidden" name="action" value="update_settings">
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="low_stock_threshold">Low Stock Threshold</label></th>
                        <td>
                            <input type="number" id="low_stock_threshold" name="low_stock_threshold" 
                                   value="<?php echo esc_attr($low_stock_threshold); ?>" min="0" class="small-text">
                            <p class="description">Items below this quantity will be flagged as low stock.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="timezone">Timezone</label></th>
                        <td>
                            <select id="timezone" name="timezone">
                                <option value="Africa/Lagos" <?php selected($timezone, 'Africa/Lagos'); ?>>Lagos/Africa (UTC+1)</option>
                                <option value="UTC" <?php selected($timezone, 'UTC'); ?>>UTC</option>
                            </select>
                            <p class="description">Timezone for all timestamps and date calculations.</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" class="button button-primary" value="Update Settings">
                </p>
            </form>
            
            <hr>
            
            <h2>System Information</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Plugin Version</th>
                    <td><?php echo IMS_VERSION; ?></td>
                </tr>
                <tr>
                    <th scope="row">WordPress Version</th>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <th scope="row">PHP Version</th>
                    <td><?php echo PHP_VERSION; ?></td>
                </tr>
                <tr>
                    <th scope="row">Current Lagos Time</th>
                    <td><?php echo ims_get_lagos_time(); ?></td>
                </tr>
            </table>
            
            <hr>
            
            <h2>Database Operations</h2>
            <div class="ims-dangerous-actions">
                <button type="button" id="ims-reset-daily-values" class="button button-secondary">
                    Reset Daily Values
                </button>
                <button type="button" id="ims-rebuild-database" class="button button-secondary">
                    Rebuild Database Tables
                </button>
                <p class="description"><iconify-icon icon="solar:danger-triangle-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Use these actions with caution. Always backup your data first.</p>
            </div>
        </div>
        <?php
    }
    
    private function render_recent_activity() {
        global $wpdb;
        
        // Get recent imports
        $recent_imports = $wpdb->get_results(
            "SELECT 'import' as type, product as item, quantity as value, staff_name, timestamp_created 
             FROM {$wpdb->prefix}ims_imports 
             ORDER BY timestamp_created DESC LIMIT 5"
        );
        
        // Get recent stock updates
        $recent_stock = $wpdb->get_results(
            "SELECT 'stock' as type, product as item, closing_packs as value, staff_name, timestamp_created 
             FROM {$wpdb->prefix}ims_stock 
             ORDER BY timestamp_created DESC LIMIT 5"
        );
        
        // Get recent chopped updates
        $recent_chopped = $wpdb->get_results(
            "SELECT 'chopped' as type, fruit as item, packs_gotten as value, staff_name, timestamp_created 
             FROM {$wpdb->prefix}ims_chopped 
             ORDER BY timestamp_created DESC LIMIT 5"
        );
        
        // Merge and sort all activities
        $all_activities = array_merge($recent_imports, $recent_stock, $recent_chopped);
        usort($all_activities, function($a, $b) {
            return strtotime($b->timestamp_created) - strtotime($a->timestamp_created);
        });
        
        $recent_activities = array_slice($all_activities, 0, 10);
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Item</th>
                    <th>Value</th>
                    <th>Staff</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($recent_activities)): ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <tr>
                            <td>
                                <span class="ims-activity-type ims-<?php echo esc_attr($activity->type); ?>">
                                    <?php echo esc_html(ucfirst($activity->type)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($activity->item); ?></td>
                            <td><?php echo esc_html(number_format($activity->value, 2)); ?></td>
                            <td><?php echo esc_html($activity->staff_name); ?></td>
                            <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($activity->timestamp_created))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="ims-no-data">No recent activity found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    
    // Handle form submissions
    private function handle_add_product() {
        if (!wp_verify_nonce($_POST['ims_admin_nonce'], 'ims_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        global $wpdb;
        
        $product_name = sanitize_text_field($_POST['product_name']);
        $product_type = sanitize_text_field($_POST['product_type']);
        $sort_order = intval($_POST['sort_order']);
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'ims_products',
            array(
                'name' => $product_name,
                'type' => $product_type,
                'is_active' => 1,
                'sort_order' => $sort_order
            ),
            array('%s', '%s', '%d', '%d')
        );
        
        if ($result) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Product added successfully!</p></div>';
            });
        }
    }
    
    private function handle_edit_product() {
        if (!wp_verify_nonce($_POST['ims_edit_nonce'], 'ims_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        global $wpdb;
        
        $product_id = intval($_POST['product_id']);
        $product_name = sanitize_text_field($_POST['product_name']);
        $product_type = sanitize_text_field($_POST['product_type']);
        $sort_order = intval($_POST['sort_order']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ims_products',
            array(
                'name' => $product_name,
                'type' => $product_type,
                'is_active' => $is_active,
                'sort_order' => $sort_order
            ),
            array('id' => $product_id),
            array('%s', '%s', '%d', '%d'),
            array('%d')
        );
        
        wp_send_json_success('Product updated successfully');
    }
    
    private function handle_delete_product() {
        if (!wp_verify_nonce($_POST['ims_admin_nonce'], 'ims_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        global $wpdb;
        
        $product_id = intval($_POST['product_id']);
        
        $result = $wpdb->delete(
            $wpdb->prefix . 'ims_products',
            array('id' => $product_id),
            array('%d')
        );
        
        wp_send_json_success('Product deleted successfully');
    }
    
    private function handle_update_settings() {
        if (!wp_verify_nonce($_POST['ims_admin_nonce'], 'ims_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        $low_stock_threshold = intval($_POST['low_stock_threshold']);
        $timezone = sanitize_text_field($_POST['timezone']);
        
        update_option('ims_low_stock_threshold', $low_stock_threshold);
        update_option('ims_timezone', $timezone);
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Settings updated successfully!</p></div>';
        });
    }
    
    // AJAX handlers
    public function delete_record() {
        if (!wp_verify_nonce($_POST['nonce'], 'ims_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        $table_type = sanitize_text_field($_POST['table_type']);
        $record_id = intval($_POST['record_id']);
        
        $table_name = $wpdb->prefix . 'ims_' . $table_type;
        
        $result = $wpdb->delete(
            $table_name,
            array('id' => $record_id),
            array('%d')
        );
        
        if ($result) {
            wp_send_json_success('Record deleted successfully');
        } else {
            wp_send_json_error('Failed to delete record');
        }
    }
    
    public function edit_record() {
        if (!wp_verify_nonce($_POST['nonce'], 'ims_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        $table_type = sanitize_text_field($_POST['table_type']);
        $record_id = intval($_POST['record_id']);
        $update_data = $_POST['update_data'];
        
        $table_name = $wpdb->prefix . 'ims_' . $table_type;
        
        // Sanitize update data based on table type
        $sanitized_data = $this->sanitize_record_data($table_type, $update_data);
        
        $result = $wpdb->update(
            $table_name,
            $sanitized_data,
            array('id' => $record_id),
            $this->get_format_array($table_type),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Record updated successfully');
        } else {
            wp_send_json_error('Failed to update record');
        }
    }
    
    public function clear_all_records() {
        if (!wp_verify_nonce($_POST['nonce'], 'ims_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        $table_type = sanitize_text_field($_POST['table_type']);
        $table_name = $wpdb->prefix . 'ims_' . $table_type;
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if ($result !== false) {
            wp_send_json_success('All records cleared successfully');
        } else {
            wp_send_json_error('Failed to clear records');
        }
    }
    
    public function manage_products() {
        if (!wp_verify_nonce($_POST['nonce'], 'ims_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $action = sanitize_text_field($_POST['product_action']);
        
        switch ($action) {
            case 'add':
                $this->handle_add_product();
                break;
            case 'edit':
                $this->handle_edit_product();
                break;
            case 'delete':
                $this->handle_delete_product();
                break;
            default:
                wp_send_json_error('Invalid action');
        }
    }
    
    public function export_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'ims_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $export_type = sanitize_text_field($_POST['export_type']);
        
        // Use the export class
        $exporter = new IMS_Export();
        $file_url = $exporter->export_to_csv($export_type);
        
        if ($file_url) {
            wp_send_json_success(array(
                'download_url' => $file_url,
                'message' => 'Export completed successfully'
            ));
        } else {
            wp_send_json_error('Export failed');
        }
    }
    
    private function sanitize_record_data($table_type, $data) {
        $sanitized = array();
        
        switch ($table_type) {
            case 'imports':
                $sanitized['product'] = sanitize_text_field($data['product']);
                $sanitized['quantity'] = floatval($data['quantity']);
                $sanitized['staff_name'] = sanitize_text_field($data['staff_name']);
                break;
                
            case 'stock':
                $sanitized['product'] = sanitize_text_field($data['product']);
                $sanitized['opening_packs'] = floatval($data['opening_packs']);
                $sanitized['added_packs'] = floatval($data['added_packs']);
                $sanitized['used_packs'] = floatval($data['used_packs']);
                $sanitized['closing_packs'] = floatval($data['closing_packs']);
                $sanitized['staff_name'] = sanitize_text_field($data['staff_name']);
                $sanitized['remarks'] = sanitize_textarea_field($data['remarks']);
                break;
                
            case 'chopped':
                $sanitized['fruit'] = sanitize_text_field($data['fruit']);
                $sanitized['opening_whole'] = floatval($data['opening_whole']);
                $sanitized['import_whole'] = floatval($data['import_whole']);
                $sanitized['prepared_whole'] = floatval($data['prepared_whole']);
                $sanitized['closing_whole'] = floatval($data['closing_whole']);
                $sanitized['packs_gotten'] = floatval($data['packs_gotten']);
                $sanitized['staff_name'] = sanitize_text_field($data['staff_name']);
                $sanitized['remarks'] = sanitize_textarea_field($data['remarks']);
                break;
        }
        
        return $sanitized;
    }
    
    private function get_format_array($table_type) {
        switch ($table_type) {
            case 'imports':
                return array('%s', '%f', '%s');
            case 'stock':
                return array('%s', '%f', '%f', '%f', '%f', '%s', '%s');
            case 'chopped':
                return array('%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s');
            default:
                return array();
        }
    }
    
    private function handle_bulk_delete_imports() {
        if (!wp_verify_nonce($_POST['ims_bulk_nonce'], 'ims_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        if (empty($_POST['record_ids']) || !is_array($_POST['record_ids'])) {
            return;
        }
        
        global $wpdb;
        $record_ids = array_map('intval', $_POST['record_ids']);
        $placeholders = implode(',', array_fill(0, count($record_ids), '%d'));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ims_imports WHERE id IN ($placeholders)",
            $record_ids
        ));
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Selected records deleted successfully!</p></div>';
        });
    }
    
    private function handle_bulk_delete_records($type) {
        if (!wp_verify_nonce($_POST['ims_bulk_nonce'], 'ims_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        if (empty($_POST['record_ids']) || !is_array($_POST['record_ids'])) {
            return;
        }
        
        global $wpdb;
        $record_ids = array_map('intval', $_POST['record_ids']);
        $placeholders = implode(',', array_fill(0, count($record_ids), '%d'));
        $table_name = $wpdb->prefix . 'ims_' . $type;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE id IN ($placeholders)",
            $record_ids
        ));
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Selected records deleted successfully!</p></div>';
        });
    }
}
?>