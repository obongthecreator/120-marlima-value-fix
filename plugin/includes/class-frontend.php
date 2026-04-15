<?php
/**
 * Frontend class for handling frontend functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMS_Frontend {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_shortcode('ims_import_form', array($this, 'import_form_shortcode'));
        add_shortcode('ims_import_history', array($this, 'import_history_shortcode'));
        add_shortcode('ims_stock_form', array($this, 'stock_form_shortcode'));
        add_shortcode('ims_stock_history', array($this, 'stock_history_shortcode'));
        add_shortcode('ims_chopped_form', array($this, 'chopped_form_shortcode'));
        add_shortcode('ims_chopped_history', array($this, 'chopped_history_shortcode'));
        add_shortcode('ims_analytics', array($this, 'analytics_shortcode'));
    }
    
    public function init() {
        // Add any initialization code here
    }
    
    public function import_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'class' => 'ims-import-form'
        ), $atts);
        
        ob_start();
        $this->render_import_form($atts);
        return ob_get_clean();
    }
    
    public function import_history_shortcode($atts) {
        $atts = shortcode_atts(array(
            'per_page' => 20,
            'class' => 'ims-import-history'
        ), $atts);
        
        ob_start();
        $this->render_import_history($atts);
        return ob_get_clean();
    }
    
    public function stock_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'class' => 'ims-stock-form'
        ), $atts);
        
        ob_start();
        $this->render_stock_form($atts);
        return ob_get_clean();
    }
    
    public function stock_history_shortcode($atts) {
        $atts = shortcode_atts(array(
            'per_page' => 20,
            'class' => 'ims-stock-history'
        ), $atts);
        
        ob_start();
        $this->render_stock_history($atts);
        return ob_get_clean();
    }
    
    public function chopped_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'class' => 'ims-chopped-form'
        ), $atts);
        
        ob_start();
        $this->render_chopped_form($atts);
        return ob_get_clean();
    }
    
    public function chopped_history_shortcode($atts) {
        $atts = shortcode_atts(array(
            'per_page' => 20,
            'class' => 'ims-chopped-history'
        ), $atts);
        
        ob_start();
        $this->render_chopped_history($atts);
        return ob_get_clean();
    }
    
    public function analytics_shortcode($atts) {
        $atts = shortcode_atts(array(
            'class' => 'ims-analytics'
        ), $atts);
        
        ob_start();
        $this->render_analytics($atts);
        return ob_get_clean();
    }
    
    private function render_import_form($atts) {
        $products = ims_get_products('all');
        $current_user = wp_get_current_user();
        $lagos_time = ims_get_lagos_time();
        ?>
        <div class="ims-container <?php echo esc_attr($atts['class']); ?>">
            <div class="ims-form-header">
                <h2 class="ims-form-title">Import Form</h2>
            </div>
            
            <form id="ims-import-form" class="ims-form" method="post">
                <?php wp_nonce_field('ims_import_form', 'ims_import_nonce'); ?>
                
                <div class="ims-form-grid">
                    <div class="ims-form-group">
                        <label for="ims-product">Product Name <span class="required">*</span></label>
                        <select id="ims-product" name="product" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo esc_attr($product); ?>"><?php echo esc_html($product); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="ims-form-group">
                        <label for="ims-quantity">Quantity <span class="required">*</span></label>
                        <input type="number" id="ims-quantity" name="quantity" step="0.01" min="0" required>
                    </div>
                </div>
                
                <div class="ims-readonly-fields">
                    <div class="ims-readonly-group">
                        <label>Staff Name</label>
                        <input type="text" value="<?php echo esc_attr($current_user->display_name); ?>" readonly>
                    </div>
                    
                    <div class="ims-readonly-group">
                        <label>Date</label>
                        <input type="text" value="<?php echo esc_attr(date('Y-m-d', strtotime($lagos_time))); ?>" readonly>
                    </div>
                    
                    <div class="ims-readonly-group">
                        <label>Time</label>
                        <input type="text" id="ims-current-time" value="<?php echo esc_attr(date('H:i:s', strtotime($lagos_time))); ?>" readonly>
                    </div>
                </div>
                
                <div class="ims-form-actions">
                    <button type="submit" class="ims-btn ims-btn-primary">Submit Import</button>
                </div>
                
                <div id="ims-form-message" class="ims-message" style="display: none;"></div>
            </form>
        </div>
        <?php
    }
    
    private function render_stock_form($atts) {
        global $wpdb;
        $products = ims_get_products('all');
        $current_user = wp_get_current_user();
        $lagos_time = ims_get_lagos_time();
        $today = date('Y-m-d', strtotime($lagos_time));
        $is_admin = current_user_can('manage_options');
        
        // Get today's stock data for each product
        $stock_data = array();
        foreach ($products as $product) {
            $data = IMS_Database::get_today_stock_data($product);
            if ($data) {
                $stock_data[$product] = $data;
            } else {
                // Get the most recent closing value before today as opening (not just yesterday)
                $previous_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT closing_packs FROM {$wpdb->prefix}ims_stock 
                     WHERE product = %s AND date_created < %s 
                     ORDER BY date_created DESC, id DESC LIMIT 1",
                    $product, $today . ' 00:00:00'
                ));
                
                $stock_data[$product] = (object) array(
                    'opening_packs' => $previous_data ? floatval($previous_data->closing_packs) : 0,
                    'added_packs' => IMS_Database::get_today_import_value($product),
                    'used_packs' => 0,
                    'closing_packs' => 0,
                    'remarks' => ''
                );
            }
        }
        ?>
        <div class="ims-container <?php echo esc_attr($atts['class']); ?>">
            <div class="ims-form-header">
                <h2 class="ims-form-title">Stock Form</h2>
            </div>
            
            <form id="ims-stock-form" class="ims-form" method="post">
                <?php wp_nonce_field('ims_stock_form', 'ims_stock_nonce'); ?>
                
                <div class="ims-readonly-fields">
                    <div class="ims-readonly-group">
                        <label>Staff Name</label>
                        <input type="text" value="<?php echo esc_attr($current_user->display_name); ?>" readonly>
                    </div>
                    
                    <div class="ims-readonly-group">
                        <label>Date</label>
                        <input type="text" value="<?php echo esc_attr($today); ?>" readonly>
                    </div>
                    
                    <div class="ims-readonly-group">
                        <label>Time</label>
                        <input type="text" id="ims-stock-current-time" value="<?php echo esc_attr(date('H:i:s', strtotime($lagos_time))); ?>" readonly>
                    </div>
                </div>
                
                <div class="ims-table-container">
                    <table class="ims-table ims-stock-table">
                        <thead>
                            <tr>
                                <th>Products</th>
                                <th>Opening Packs</th>
                                <th>Added Packs from chopped/imports</th>
                                <th>Used Packs</th>
                                <th>Closing Packs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): 
                                $data = $stock_data[$product];
                                $closing_packs = $data->opening_packs + $data->added_packs - $data->used_packs;
                            ?>
                                <tr data-product="<?php echo esc_attr($product); ?>">
                                    <td class="product-name"><?php echo esc_html($product); ?></td>
                                    <td>
                                        <input type="number" 
                                               name="opening_packs[<?php echo esc_attr($product); ?>]" 
                                               value="<?php echo esc_attr($data->opening_packs); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="opening-packs"
                                               readonly>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="added_packs[<?php echo esc_attr($product); ?>]" 
                                               value="<?php echo esc_attr($data->added_packs); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="added-packs"
                                               readonly>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="used_packs[<?php echo esc_attr($product); ?>]" 
                                               value="<?php echo esc_attr($data->used_packs); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="used-packs">
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="closing_packs[<?php echo esc_attr($product); ?>]" 
                                               value="<?php echo esc_attr($closing_packs); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="closing-packs"
                                               readonly>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="ims-form-group">
                    <label for="ims-stock-remarks">Remarks</label>
                    <textarea id="ims-stock-remarks" name="remarks" rows="3"></textarea>
                </div>
                
                <div class="ims-form-actions">
                    <button type="submit" class="ims-btn ims-btn-primary">Submit Stock</button>
                </div>
                
                <div id="ims-stock-form-message" class="ims-message" style="display: none;"></div>
            </form>
        </div>
        <?php
    }
    
    private function render_chopped_form($atts) {
        global $wpdb;
        $fruits = ims_get_products('chopped');
        $current_user = wp_get_current_user();
        $lagos_time = ims_get_lagos_time();
        $today = date('Y-m-d', strtotime($lagos_time));
        $is_admin = current_user_can('manage_options');
        
        // Get today's chopped data for each fruit
        $chopped_data = array();
        foreach ($fruits as $fruit) {
            $data = IMS_Database::get_today_chopped_data($fruit);
            if ($data) {
                $chopped_data[$fruit] = $data;
            } else {
                // Get the most recent closing value before today as opening (not just yesterday)
                $previous_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT closing_whole FROM {$wpdb->prefix}ims_chopped 
                     WHERE fruit = %s AND date_created < %s 
                     ORDER BY date_created DESC, id DESC LIMIT 1",
                    $fruit, $today . ' 00:00:00'
                ));
                
                $chopped_data[$fruit] = (object) array(
                    'opening_whole' => $previous_data ? floatval($previous_data->closing_whole) : 0,
                    'import_whole' => IMS_Database::get_today_import_value($fruit),
                    'prepared_whole' => 0,
                    'closing_whole' => 0,
                    'packs_gotten' => 0,
                    'remarks' => ''
                );
            }
        }
        ?>
        <div class="ims-container <?php echo esc_attr($atts['class']); ?>">
            <div class="ims-form-header">
                <h2 class="ims-form-title">Chopped Form</h2>
            </div>
            
            <form id="ims-chopped-form" class="ims-form" method="post">
                <?php wp_nonce_field('ims_chopped_form', 'ims_chopped_nonce'); ?>
                
                <div class="ims-readonly-fields">
                    <div class="ims-readonly-group">
                        <label>Staff Name</label>
                        <input type="text" value="<?php echo esc_attr($current_user->display_name); ?>" readonly>
                    </div>
                    
                    <div class="ims-readonly-group">
                        <label>Date</label>
                        <input type="text" value="<?php echo esc_attr($today); ?>" readonly>
                    </div>
                    
                    <div class="ims-readonly-group">
                        <label>Time</label>
                        <input type="text" id="ims-chopped-current-time" value="<?php echo esc_attr(date('H:i:s', strtotime($lagos_time))); ?>" readonly>
                    </div>
                </div>
                
                <div class="ims-table-container">
                    <table class="ims-table ims-chopped-table">
                        <thead>
                            <tr>
                                <th>Fruit</th>
                                <th>Opening (Whole)</th>
                                <th>Import (Whole)</th>
                                <th>Prepared (Whole)</th>
                                <th>Closing (Whole)</th>
                                <th>Pack(s) Gotten</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fruits as $fruit): 
                                $data = $chopped_data[$fruit];
                                $closing_whole = $data->opening_whole + $data->import_whole - $data->prepared_whole;
                            ?>
                                <tr data-fruit="<?php echo esc_attr($fruit); ?>">
                                    <td class="fruit-name"><?php echo esc_html($fruit); ?></td>
                                    <td>
                                        <input type="number" 
                                               name="opening_whole[<?php echo esc_attr($fruit); ?>]" 
                                               value="<?php echo esc_attr($data->opening_whole); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="opening-whole"
                                               readonly>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="import_whole[<?php echo esc_attr($fruit); ?>]" 
                                               value="<?php echo esc_attr($data->import_whole); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="import-whole"
                                               readonly>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="prepared_whole[<?php echo esc_attr($fruit); ?>]" 
                                               value="<?php echo esc_attr($data->prepared_whole); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="prepared-whole">
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="closing_whole[<?php echo esc_attr($fruit); ?>]" 
                                               value="<?php echo esc_attr($closing_whole); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="closing-whole"
                                               readonly>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="packs_gotten[<?php echo esc_attr($fruit); ?>]" 
                                               value="<?php echo esc_attr($data->packs_gotten); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               class="packs-gotten">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="ims-form-group">
                    <label for="ims-chopped-remarks">Remarks</label>
                    <textarea id="ims-chopped-remarks" name="remarks" rows="3"></textarea>
                </div>
                
                <div class="ims-form-actions">
                    <button type="submit" class="ims-btn ims-btn-primary">Submit Chopped</button>
                </div>
                
                <div id="ims-chopped-form-message" class="ims-message" style="display: none;"></div>
            </form>
        </div>
        <?php
    }
    
    private function render_analytics($atts) {
        $analytics = IMS_Database::get_analytics_data();
        ?>
        <div class="ims-analytics-container <?php echo esc_attr($atts['class']); ?>">
            <div class="ims-analytics-header">
                <h2 class="ims-analytics-title">Analytics Dashboard</h2>
            </div>
            
            <div class="ims-analytics-cards">
                <div class="ims-analytics-card">
                    <div class="ims-card-icon"><iconify-icon icon="solar:box-linear"></iconify-icon></div>
                    <div class="ims-card-content">
                        <h3 class="ims-card-number"><?php echo number_format($analytics['imports']); ?></h3>
                        <p class="ims-card-label">Total Imports</p>
                    </div>
                </div>
                
                <div class="ims-analytics-card">
                    <div class="ims-card-icon"><iconify-icon icon="solar:chart-2-linear"></iconify-icon></div>
                    <div class="ims-card-content">
                        <h3 class="ims-card-number"><?php echo number_format($analytics['stock']); ?></h3>
                        <p class="ims-card-label">Stock Records</p>
                    </div>
                </div>
                
                <div class="ims-analytics-card">
                    <div class="ims-card-icon"><iconify-icon icon="solar:scissors-linear"></iconify-icon></div>
                    <div class="ims-card-content">
                        <h3 class="ims-card-number"><?php echo number_format($analytics['chopped']); ?></h3>
                        <p class="ims-card-label">Chopped Records</p>
                    </div>
                </div>
                
                <div class="ims-analytics-card ims-warning-card">
                    <div class="ims-card-icon"><iconify-icon icon="solar:danger-triangle-linear"></iconify-icon></div>
                    <div class="ims-card-content">
                        <h3 class="ims-card-number"><?php echo number_format($analytics['low_stock']); ?></h3>
                        <p class="ims-card-label">Low Stock Items</p>
                    </div>
                </div>
            </div>
            
            <div class="ims-analytics-refresh">
                <button type="button" id="ims-refresh-analytics" class="ims-btn ims-btn-secondary">
                    <iconify-icon icon="solar:refresh-linear" style="font-size:1.2em;vertical-align:middle;"></iconify-icon> Refresh Data
                </button>
            </div>
        </div>
        <?php
    }
    
    private function render_import_history($atts) {
        global $wpdb;
        
        $per_page = intval($atts['per_page']);
        $page = isset($_GET['ims_page']) ? max(1, intval($_GET['ims_page'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        $table = $wpdb->prefix . 'ims_imports';
        
        // Handle filters
        $where_clause = "WHERE 1=1";
        $where_values = array();
        
        if (!empty($_GET['ims_product'])) {
            $where_clause .= " AND product = %s";
            $where_values[] = sanitize_text_field($_GET['ims_product']);
        }
        
        if (!empty($_GET['ims_date_from'])) {
            $where_clause .= " AND DATE(date_created) >= %s";
            $where_values[] = sanitize_text_field($_GET['ims_date_from']);
        }
        
        if (!empty($_GET['ims_date_to'])) {
            $where_clause .= " AND DATE(date_created) <= %s";
            $where_values[] = sanitize_text_field($_GET['ims_date_to']);
        }
        
        // Get total count
        $total_query = "SELECT COUNT(*) FROM $table $where_clause";
        if (!empty($where_values)) {
            $total = $wpdb->get_var($wpdb->prepare($total_query, $where_values));
        } else {
            $total = $wpdb->get_var($total_query);
        }
        
        // Get records
        $records_query = "SELECT * FROM $table $where_clause ORDER BY date_created DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, array($per_page, $offset));
        $records = $wpdb->get_results($wpdb->prepare($records_query, $query_values));
        
        $total_pages = ceil($total / $per_page);
        $products = ims_get_products('all');
        ?>
        <div class="ims-container <?php echo esc_attr($atts['class']); ?>">
            <div class="ims-history-header">
                <h2 class="ims-history-title">Import History</h2>
                
                <div class="ims-history-filters">
                    <form method="get" class="ims-filter-form">
                        <div class="ims-filter-group">
                            <select name="ims_product">
                                <option value="">All Products</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo esc_attr($product); ?>" 
                                            <?php selected($_GET['ims_product'] ?? '', $product); ?>>
                                        <?php echo esc_html($product); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ims-filter-group">
                            <input type="date" name="ims_date_from" 
                                   value="<?php echo esc_attr($_GET['ims_date_from'] ?? ''); ?>" 
                                   placeholder="From Date">
                        </div>
                        
                        <div class="ims-filter-group">
                            <input type="date" name="ims_date_to" 
                                   value="<?php echo esc_attr($_GET['ims_date_to'] ?? ''); ?>" 
                                   placeholder="To Date">
                        </div>
                        
                        <div class="ims-filter-actions">
                            <button type="submit" class="ims-btn ims-btn-primary">Filter</button>
                            <a href="<?php echo esc_url(remove_query_arg(array('ims_product', 'ims_date_from', 'ims_date_to', 'ims_page'))); ?>" 
                               class="ims-btn ims-btn-secondary">Clear</a>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="ims-table-container">
                <table class="ims-table ims-history-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Staff Name</th>
                            <th>Date</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($records)): ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td><?php echo esc_html($record->id); ?></td>
                                    <td><?php echo esc_html($record->product); ?></td>
                                    <td><?php echo esc_html(number_format($record->quantity, 2)); ?></td>
                                    <td><?php echo esc_html($record->staff_name); ?></td>
                                    <td><?php echo esc_html(date('Y-m-d', strtotime($record->date_created))); ?></td>
                                    <td><?php echo esc_html(date('H:i:s', strtotime($record->timestamp_created))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="ims-no-data">No import records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="ims-pagination">
                    <?php
                    $base_url = add_query_arg(array_filter(array(
                        'ims_product' => $_GET['ims_product'] ?? '',
                        'ims_date_from' => $_GET['ims_date_from'] ?? '',
                        'ims_date_to' => $_GET['ims_date_to'] ?? ''
                    )));
                    
                    for ($i = 1; $i <= $total_pages; $i++):
                        $page_url = add_query_arg('ims_page', $i, $base_url);
                        $active_class = ($i === $page) ? 'active' : '';
                    ?>
                        <a href="<?php echo esc_url($page_url); ?>" 
                           class="ims-page-link <?php echo $active_class; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_stock_history($atts) {
        // Similar structure to import_history but for stock table
        global $wpdb;
        
        $per_page = intval($atts['per_page']);
        $page = isset($_GET['ims_page']) ? max(1, intval($_GET['ims_page'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        $table = $wpdb->prefix . 'ims_stock';
        
        // Handle filters
        $where_clause = "WHERE 1=1";
        $where_values = array();
        
        if (!empty($_GET['ims_product'])) {
            $where_clause .= " AND product = %s";
            $where_values[] = sanitize_text_field($_GET['ims_product']);
        }
        
        if (!empty($_GET['ims_date_from'])) {
            $where_clause .= " AND DATE(date_created) >= %s";
            $where_values[] = sanitize_text_field($_GET['ims_date_from']);
        }
        
        if (!empty($_GET['ims_date_to'])) {
            $where_clause .= " AND DATE(date_created) <= %s";
            $where_values[] = sanitize_text_field($_GET['ims_date_to']);
        }
        
        // Get total count
        $total_query = "SELECT COUNT(*) FROM $table $where_clause";
        if (!empty($where_values)) {
            $total = $wpdb->get_var($wpdb->prepare($total_query, $where_values));
        } else {
            $total = $wpdb->get_var($total_query);
        }
        
        // Get records
        $records_query = "SELECT * FROM $table $where_clause ORDER BY date_created DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, array($per_page, $offset));
        $records = $wpdb->get_results($wpdb->prepare($records_query, $query_values));
        
        $total_pages = ceil($total / $per_page);
        $products = ims_get_products('all');
        ?>
        <div class="ims-container <?php echo esc_attr($atts['class']); ?>">
            <div class="ims-history-header">
                <h2 class="ims-history-title">Stock Record History</h2>
                
                <div class="ims-history-filters">
                    <form method="get" class="ims-filter-form">
                        <div class="ims-filter-group">
                            <select name="ims_product">
                                <option value="">All Products</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo esc_attr($product); ?>" 
                                            <?php selected($_GET['ims_product'] ?? '', $product); ?>>
                                        <?php echo esc_html($product); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ims-filter-group">
                            <input type="date" name="ims_date_from" 
                                   value="<?php echo esc_attr($_GET['ims_date_from'] ?? ''); ?>" 
                                   placeholder="From Date">
                        </div>
                        
                        <div class="ims-filter-group">
                            <input type="date" name="ims_date_to" 
                                   value="<?php echo esc_attr($_GET['ims_date_to'] ?? ''); ?>" 
                                   placeholder="To Date">
                        </div>
                        
                        <div class="ims-filter-actions">
                            <button type="submit" class="ims-btn ims-btn-primary">Filter</button>
                            <a href="<?php echo esc_url(remove_query_arg(array('ims_product', 'ims_date_from', 'ims_date_to', 'ims_page'))); ?>" 
                               class="ims-btn ims-btn-secondary">Clear</a>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="ims-table-container">
                <table class="ims-table ims-history-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Opening Packs</th>
                            <th>Added Packs</th>
                            <th>Used Packs</th>
                            <th>Closing Packs</th>
                            <th>Staff Name</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($records)): ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
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
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="ims-no-data">No stock records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="ims-pagination">
                    <?php
                    $base_url = add_query_arg(array_filter(array(
                        'ims_product' => $_GET['ims_product'] ?? '',
                        'ims_date_from' => $_GET['ims_date_from'] ?? '',
                        'ims_date_to' => $_GET['ims_date_to'] ?? ''
                    )));
                    
                    for ($i = 1; $i <= $total_pages; $i++):
                        $page_url = add_query_arg('ims_page', $i, $base_url);
                        $active_class = ($i === $page) ? 'active' : '';
                    ?>
                        <a href="<?php echo esc_url($page_url); ?>" 
                           class="ims-page-link <?php echo $active_class; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_chopped_history($atts) {
        // Similar structure to import_history but for chopped table
        global $wpdb;
        
        $per_page = intval($atts['per_page']);
        $page = isset($_GET['ims_page']) ? max(1, intval($_GET['ims_page'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        $table = $wpdb->prefix . 'ims_chopped';
        
        // Handle filters
        $where_clause = "WHERE 1=1";
        $where_values = array();
        
        if (!empty($_GET['ims_fruit'])) {
            $where_clause .= " AND fruit = %s";
            $where_values[] = sanitize_text_field($_GET['ims_fruit']);
        }
        
        if (!empty($_GET['ims_date_from'])) {
            $where_clause .= " AND DATE(date_created) >= %s";
            $where_values[] = sanitize_text_field($_GET['ims_date_from']);
        }
        
        if (!empty($_GET['ims_date_to'])) {
            $where_clause .= " AND DATE(date_created) <= %s";
            $where_values[] = sanitize_text_field($_GET['ims_date_to']);
        }
        
        // Get total count
        $total_query = "SELECT COUNT(*) FROM $table $where_clause";
        if (!empty($where_values)) {
            $total = $wpdb->get_var($wpdb->prepare($total_query, $where_values));
        } else {
            $total = $wpdb->get_var($total_query);
        }
        
        // Get records
        $records_query = "SELECT * FROM $table $where_clause ORDER BY date_created DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, array($per_page, $offset));
        $records = $wpdb->get_results($wpdb->prepare($records_query, $query_values));
        
        $total_pages = ceil($total / $per_page);
        $fruits = ims_get_products('chopped');
        ?>
        <div class="ims-container <?php echo esc_attr($atts['class']); ?>">
            <div class="ims-history-header">
                <h2 class="ims-history-title">Chopped Record History</h2>
                
                <div class="ims-history-filters">
                    <form method="get" class="ims-filter-form">
                        <div class="ims-filter-group">
                            <select name="ims_fruit">
                                <option value="">All Fruits</option>
                                <?php foreach ($fruits as $fruit): ?>
                                    <option value="<?php echo esc_attr($fruit); ?>" 
                                            <?php selected($_GET['ims_fruit'] ?? '', $fruit); ?>>
                                        <?php echo esc_html($fruit); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ims-filter-group">
                            <input type="date" name="ims_date_from" 
                                   value="<?php echo esc_attr($_GET['ims_date_from'] ?? ''); ?>" 
                                   placeholder="From Date">
                        </div>
                        
                        <div class="ims-filter-group">
                            <input type="date" name="ims_date_to" 
                                   value="<?php echo esc_attr($_GET['ims_date_to'] ?? ''); ?>" 
                                   placeholder="To Date">
                        </div>
                        
                        <div class="ims-filter-actions">
                            <button type="submit" class="ims-btn ims-btn-primary">Filter</button>
                            <a href="<?php echo esc_url(remove_query_arg(array('ims_fruit', 'ims_date_from', 'ims_date_to', 'ims_page'))); ?>" 
                               class="ims-btn ims-btn-secondary">Clear</a>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="ims-table-container">
                <table class="ims-table ims-history-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fruit</th>
                            <th>Opening (Whole)</th>
                            <th>Import (Whole)</th>
                            <th>Prepared (Whole)</th>
                            <th>Closing (Whole)</th>
                            <th>Pack(s) Gotten</th>
                            <th>Staff Name</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($records)): ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
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
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="ims-no-data">No chopped records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="ims-pagination">
                    <?php
                    $base_url = add_query_arg(array_filter(array(
                        'ims_fruit' => $_GET['ims_fruit'] ?? '',
                        'ims_date_from' => $_GET['ims_date_from'] ?? '',
                        'ims_date_to' => $_GET['ims_date_to'] ?? ''
                    )));
                    
                    for ($i = 1; $i <= $total_pages; $i++):
                        $page_url = add_query_arg('ims_page', $i, $base_url);
                        $active_class = ($i === $page) ? 'active' : '';
                    ?>
                        <a href="<?php echo esc_url($page_url); ?>" 
                           class="ims-page-link <?php echo $active_class; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
?>