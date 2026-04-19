/**
 * Admin JavaScript for Inventory Management System
 * Handles admin functionality, modals, and AJAX operations
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        IMSAdmin.init();
    });

    // Main IMS Admin object
    window.IMSAdmin = {
        
        // Initialize all functionality
        init: function() {
            this.bindEvents();
            this.initModals();
            this.initBulkActions();
            this.initExportButtons();
            console.log('IMS Admin initialized');
        },

        // Bind all event handlers
        bindEvents: function() {
            // Product management
            $('.ims-edit-product').on('click', this.openEditProductModal);
            $('.ims-delete-product').on('click', this.deleteProduct);
            $('#ims-edit-product-form').on('submit', this.submitEditProduct);
            
            // Record management
            $('.ims-edit-import, .ims-edit-stock, .ims-edit-chopped').on('click', this.editRecord);
            $('.ims-delete-import, .ims-delete-stock, .ims-delete-chopped').on('click', this.deleteRecord);
            
            // Bulk actions
            $('#cb-select-all').on('change', this.toggleSelectAll);
            $('.check-column input[type="checkbox"]').on('change', this.updateBulkActions);
            
            // Export buttons
            $('#ims-export-all-data').on('click', this.exportAllData);
            $('#ims-export-imports').on('click', function() { IMSAdmin.exportData('imports'); });
            $('#ims-export-stock').on('click', function() { IMSAdmin.exportData('stock'); });
            $('#ims-export-chopped').on('click', function() { IMSAdmin.exportData('chopped'); });
            
            // System actions
            $('#ims-reset-daily-values').on('click', this.resetDailyValues);
            $('#ims-rebuild-database').on('click', this.rebuildDatabase);
            
            // Modal events
            $('.ims-modal-close').on('click', this.closeModal);
            
            // Form validation
            $('.ims-admin-dashboard input[type="number"]').on('input', this.validateNumberInput);
        },

        // Initialize modals
        initModals: function() {
            // Close modal when clicking outside
            $(document).on('click', '.ims-modal', function(e) {
                if (e.target === this) {
                    IMSAdmin.closeModal();
                }
            });
            
            // Close modal on escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    IMSAdmin.closeModal();
                }
            });
        },

        // Initialize bulk actions
        initBulkActions: function() {
            this.updateBulkActions();
        },

        // Initialize export buttons
        initExportButtons: function() {
            // Add tooltips or additional functionality if needed
        },

        // Open edit product modal
        openEditProductModal: function() {
            var button = $(this);
            var modal = $('#ims-edit-product-modal');
            
            // Populate form with current values
            $('#edit_product_id').val(button.data('id'));
            $('#edit_product_name').val(button.data('name'));
            $('#edit_product_type').val(button.data('type'));
            $('#edit_sort_order').val(button.data('order'));
            $('#edit_is_active').prop('checked', button.data('active') == 1);
            
            // Show modal
            modal.show();
        },

        // Submit edit product form
        submitEditProduct: function(e) {
            e.preventDefault();
            
            var form = $(this);
            var submitBtn = form.find('button[type="submit"]');
            
            // Show loading state
            submitBtn.prop('disabled', true).text('Updating...');
            
            // Prepare data
            var formData = {
                action: 'ims_admin_edit_record',
                nonce: ims_admin_ajax.nonce,
                product_action: 'edit',
                product_id: $('#edit_product_id').val(),
                product_name: $('#edit_product_name').val(),
                product_type: $('#edit_product_type').val(),
                sort_order: $('#edit_sort_order').val(),
                is_active: $('#edit_is_active').is(':checked') ? 1 : 0
            };
            
            // Submit via AJAX
            $.ajax({
                url: ims_admin_ajax.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        IMSAdmin.showNotification('success', response.data);
                        IMSAdmin.closeModal();
                        location.reload(); // Refresh to show changes
                    } else {
                        IMSAdmin.showNotification('error', response.data || 'Update failed');
                    }
                },
                error: function(xhr, status, error) {
                    IMSAdmin.showNotification('error', 'Network error: ' + error);
                },
                complete: function() {
                    submitBtn.prop('disabled', false).text('Update Product');
                }
            });
        },

        // Delete product
        deleteProduct: function() {
            var button = $(this);
            var productId = button.data('id');
            var productName = button.data('name');
            
            if (!confirm(`Are you sure you want to delete the product "${productName}"? This action cannot be undone.`)) {
                return;
            }
            
            // Show loading state
            button.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: ims_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ims_admin_delete_record',
                    nonce: ims_admin_ajax.nonce,
                    table_type: 'products',
                    record_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        IMSAdmin.showNotification('success', response.data);
                        button.closest('tr').fadeOut(function() {
                            $(this).remove();
                        });
                    } else {
                        IMSAdmin.showNotification('error', response.data || 'Delete failed');
                        button.prop('disabled', false).text('Delete');
                    }
                },
                error: function(xhr, status, error) {
                    IMSAdmin.showNotification('error', 'Network error: ' + error);
                    button.prop('disabled', false).text('Delete');
                }
            });
        },

        // Edit record (generic)
        editRecord: function() {
            var button = $(this);
            var recordId = button.data('id');
            var tableType = button.hasClass('ims-edit-import') ? 'imports' : 
                           button.hasClass('ims-edit-stock') ? 'stock' : 'chopped';
            
            // For now, just show an alert - could be expanded to show edit modal
            alert('Edit functionality for ' + tableType + ' record ' + recordId + ' - Coming soon!');
        },

        // Delete record (generic)
        deleteRecord: function() {
            var button = $(this);
            var recordId = button.data('id');
            var tableType = button.hasClass('ims-delete-import') ? 'imports' : 
                           button.hasClass('ims-delete-stock') ? 'stock' : 'chopped';
            
            if (!confirm(`Are you sure you want to delete this ${tableType} record? This action cannot be undone.`)) {
                return;
            }
            
            // Show loading state
            button.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: ims_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ims_admin_delete_record',
                    nonce: ims_admin_ajax.nonce,
                    table_type: tableType,
                    record_id: recordId
                },
                success: function(response) {
                    if (response.success) {
                        IMSAdmin.showNotification('success', response.data);
                        button.closest('tr').fadeOut(function() {
                            $(this).remove();
                        });
                    } else {
                        IMSAdmin.showNotification('error', response.data || 'Delete failed');
                        button.prop('disabled', false).text('Delete');
                    }
                },
                error: function(xhr, status, error) {
                    IMSAdmin.showNotification('error', 'Network error: ' + error);
                    button.prop('disabled', false).text('Delete');
                }
            });
        },

        // Toggle select all checkboxes
        toggleSelectAll: function() {
            var isChecked = $(this).is(':checked');
            $('.check-column input[type="checkbox"]').prop('checked', isChecked);
            IMSAdmin.updateBulkActions();
        },

        // Update bulk actions based on selections
        updateBulkActions: function() {
            var selectedCount = $('.check-column input[type="checkbox"]:checked').length;
            var bulkActions = $('.bulkactions');
            
            if (selectedCount > 0) {
                bulkActions.find('.button').prop('disabled', false);
                bulkActions.find('select option[value="delete"]').text(`Delete Selected (${selectedCount})`);
            } else {
                bulkActions.find('.button').prop('disabled', true);
                bulkActions.find('select option[value="delete"]').text('Delete Selected');
            }
        },

        // Export all data
        exportAllData: function() {
            IMSAdmin.exportData('all');
        },

        // Export data (generic)
        exportData: function(type) {
            var button = $(this);
            if (!button.length) {
                button = $('#ims-export-' + type);
            }
            
            // Show loading state
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Exporting...');
            
            $.ajax({
                url: ims_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ims_admin_export_data',
                    nonce: ims_admin_ajax.nonce,
                    export_type: type
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download
                        var downloadLink = document.createElement('a');
                        downloadLink.href = response.data.download_url;
                        downloadLink.download = '';
                        document.body.appendChild(downloadLink);
                        downloadLink.click();
                        document.body.removeChild(downloadLink);
                        
                        IMSAdmin.showNotification('success', response.data.message);
                    } else {
                        IMSAdmin.showNotification('error', response.data || 'Export failed');
                    }
                },
                error: function(xhr, status, error) {
                    IMSAdmin.showNotification('error', 'Network error: ' + error);
                },
                complete: function() {
                    button.prop('disabled', false).html(button.data('original-text') || 'Export');
                }
            });
        },

        // Reset daily values
        resetDailyValues: function() {
            if (!confirm('Are you sure you want to reset daily values? This will carry forward closing values as opening values for today.')) {
                return;
            }
            
            var button = $(this);
            button.prop('disabled', true).text('Resetting...');
            
            $.ajax({
                url: ims_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ims_admin_reset_daily',
                    nonce: ims_admin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        IMSAdmin.showNotification('success', 'Daily values reset successfully');
                    } else {
                        IMSAdmin.showNotification('error', response.data || 'Reset failed');
                    }
                },
                error: function(xhr, status, error) {
                    IMSAdmin.showNotification('error', 'Network error: ' + error);
                },
                complete: function() {
                    button.prop('disabled', false).text('Reset Daily Values');
                }
            });
        },

        // Rebuild database
        rebuildDatabase: function() {
            if (!confirm('Are you sure you want to rebuild the database tables? This is a advanced operation and should only be done if instructed by support.')) {
                return;
            }
            
            var button = $(this);
            button.prop('disabled', true).text('Rebuilding...');
            
            $.ajax({
                url: ims_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ims_admin_rebuild_db',
                    nonce: ims_admin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        IMSAdmin.showNotification('success', 'Database rebuilt successfully');
                    } else {
                        IMSAdmin.showNotification('error', response.data || 'Rebuild failed');
                    }
                },
                error: function(xhr, status, error) {
                    IMSAdmin.showNotification('error', 'Network error: ' + error);
                },
                complete: function() {
                    button.prop('disabled', false).text('Rebuild Database Tables');
                }
            });
        },

        // Close modal
        closeModal: function() {
            $('.ims-modal').hide();
        },

        // Show notification
        showNotification: function(type, message) {
            // Create notification element
            var notification = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Add to page
            $('.wrap').prepend(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(function() {
                notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Add dismiss functionality
            notification.on('click', '.notice-dismiss', function() {
                notification.fadeOut(function() {
                    $(this).remove();
                });
            });
        },

        // Validate number input
            validateNumberInput: function() {
            var input = $(this);
            var value = parseFloat(input.val());
            var min = parseFloat(input.attr('min')) || 0;
            var max = parseFloat(input.attr('max'));
            
            input.removeClass('error');
            
            if (isNaN(value) || value < min || (max && value > max)) {
                input.addClass('error');
            }
        },

        // Initialize dashboard charts (if needed)
        initDashboardCharts: function() {
            // This could be expanded to include Chart.js or similar
            console.log('Dashboard charts initialized');
        },

        // Handle real-time updates
        setupRealTimeUpdates: function() {
            // Update admin dashboard every 30 seconds
            setInterval(function() {
                IMSAdmin.refreshDashboardData();
            }, 30000);
        },

        // Refresh dashboard data
        refreshDashboardData: function() {
            $.ajax({
                url: ims_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ims_refresh_analytics',
                    nonce: ims_admin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        IMSAdmin.updateDashboardCards(response.data);
                    }
                }
            });
        },

        // Update dashboard cards
        updateDashboardCards: function(data) {
            $('.ims-admin-card').each(function() {
                var card = $(this);
                var iconEl = card.find('.ims-card-icon iconify-icon');
                var icon = iconEl.length ? iconEl.attr('icon') : '';
                var numberElement = card.find('h3');
                
                var newValue;
                switch (icon) {
                    case 'solar:box-linear':
                        newValue = data.imports;
                        break;
                    case 'solar:chart-2-linear':
                        newValue = data.stock;
                        break;
                    case 'solar:scissors-linear':
                        newValue = data.chopped;
                        break;
                    case 'solar:danger-triangle-linear':
                        newValue = data.low_stock;
                        break;
                }
                
                if (newValue !== undefined) {
                    var currentValue = parseInt(numberElement.text().replace(/,/g, ''));
                    if (currentValue !== newValue) {
                        numberElement.text(newValue.toLocaleString());
                        card.addClass('updated');
                        setTimeout(function() {
                            card.removeClass('updated');
                        }, 1000);
                    }
                }
            });
        }
    };

    // Utility functions for admin
    window.IMSAdminUtils = {
        
        // Format number for display
        formatNumber: function(num, decimals) {
            decimals = decimals || 2;
            return parseFloat(num).toFixed(decimals);
        },
        
        // Confirm action with custom message
        confirmAction: function(message, callback) {
            if (confirm(message)) {
                callback();
            }
        },
        
        // Show loading overlay
        showLoadingOverlay: function(element) {
            var overlay = $('<div class="ims-loading-overlay"><div class="ims-loading-spinner"></div></div>');
            element.css('position', 'relative').append(overlay);
        },
        
        // Hide loading overlay
        hideLoadingOverlay: function(element) {
            element.find('.ims-loading-overlay').remove();
        }
    };

    // CSS for admin updates
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .error { border-color: #dc3545 !important; }
            .updated { animation: adminHighlight 1s ease-in-out; }
            @keyframes adminHighlight {
                0% { background-color: rgba(255, 0, 0, 0.1); }
                100% { background-color: transparent; }
            }
            .dashicons.spin { animation: spin 1s linear infinite; }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `)
        .appendTo('head');

})(jQuery);