/**
 * Frontend JavaScript for Inventory Management System
 * Handles form submissions, real-time calculations, and AJAX interactions
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        IMS.init();
    });

    // Main IMS object
    window.IMS = {
        
        // Initialize all functionality
        init: function() {
            this.bindEvents();
            this.initRealTimeUpdates();
            this.initFormValidation();
            this.updateCurrentTime();
            this.initAnalytics();
            console.log('IMS Frontend initialized');
        },

        // Bind all event handlers
        bindEvents: function() {
            // Form submissions
            $('#ims-import-form').on('submit', this.handleImportSubmit);
            $('#ims-stock-form').on('submit', this.handleStockSubmit);
            $('#ims-chopped-form').on('submit', this.handleChoppedSubmit);
            
            // Real-time calculations
            $('.ims-stock-table').on('input', '.opening-packs, .added-packs, .used-packs', this.calculateStockClosing);
            $('.ims-chopped-table').on('input', '.opening-whole, .import-whole, .prepared-whole', this.calculateChoppedClosing);
            
            // Analytics refresh
            $('#ims-refresh-analytics').on('click', this.refreshAnalytics);
            
            // Form field changes
            $('.ims-form input, .ims-form select').on('change', this.validateField);
        },

        // Initialize real-time updates
        initRealTimeUpdates: function() {
            // Update time every second
            setInterval(this.updateCurrentTime, 1000);
            
            // Auto-save form data every 30 seconds
            setInterval(this.autoSaveFormData, 30000);
            
            // Refresh analytics every 5 minutes
            setInterval(this.refreshAnalytics, 300000);
        },

        // Initialize form validation
        initFormValidation: function() {
            // Real-time validation
            $('.ims-form input[type="number"]').on('input', function() {
                var value = parseFloat($(this).val());
                var min = parseFloat($(this).attr('min')) || 0;
                var max = parseFloat($(this).attr('max'));
                
                $(this).removeClass('error');
                
                if (isNaN(value) || value < min || (max && value > max)) {
                    $(this).addClass('error');
                }
            });
            
            // Prevent negative values
            $('.ims-form input[type="number"]').on('keydown', function(e) {
                if (e.key === '-' || e.key === 'e' || e.key === 'E') {
                    e.preventDefault();
                }
            });
        },

        // Build FormData from a jQuery form, including readonly fields
        buildFormData: function(form, action) {
            var fd = new FormData(form[0]);
            // FormData from a native form element automatically includes ALL
            // non-disabled inputs (including readonly) with their exact names.
            // Append AJAX-specific fields:
            fd.append('action', action);
            fd.append('nonce', ims_ajax.nonce);
            return fd;
        },

        // Generic AJAX submit using native FormData (avoids jQuery serialization bugs)
        ajaxSubmitForm: function(form, action, successMsg) {
            var submitBtn = form.find('button[type="submit"]');
            var originalText = submitBtn.html();
            submitBtn.prop('disabled', true).html('<span class="ims-loading"></span> Submitting...');

            var fd = IMS.buildFormData(form, action);

            $.ajax({
                url: ims_ajax.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: fd,
                processData: false,   // tell jQuery NOT to serialize FormData
                contentType: false,   // tell jQuery NOT to set Content-Type (browser sets multipart boundary)
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || successMsg);
                        window.location.reload();
                    } else {
                        alert('Error: ' + (response.data || 'Submission failed. Please try again.'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error, xhr.responseText);
                    alert('Network error: ' + error + '. Please check your connection and try again.');
                },
                complete: function() {
                    submitBtn.prop('disabled', false).html(originalText);
                }
            });
        },

        // Handle import form submission
        handleImportSubmit: function(e) {
            e.preventDefault();
            var form = $(this);
            if (!IMS.validateImportForm(form)) return false;
            IMS.ajaxSubmitForm(form, 'ims_submit_import', 'Import submitted successfully!');
        },

        // Handle stock form submission
        handleStockSubmit: function(e) {
            e.preventDefault();
            var form = $(this);
            if (!IMS.validateStockForm(form)) return false;
            IMS.ajaxSubmitForm(form, 'ims_submit_stock', 'Stock records saved successfully!');
        },

        // Handle chopped form submission
        handleChoppedSubmit: function(e) {
            e.preventDefault();
            var form = $(this);
            if (!IMS.validateChoppedForm(form)) return false;
            IMS.ajaxSubmitForm(form, 'ims_submit_chopped', 'Chopped records saved successfully!');
        },

        // Validate import form — checks both scalar and array quantity inputs
        validateImportForm: function(form) {
            var hasQuantity = false;

            // Check scalar quantity field (single-product import form)
            var scalarQty = form.find('input[name="quantity"]');
            if (scalarQty.length) {
                var val = parseFloat(scalarQty.val());
                if (val > 0) {
                    hasQuantity = true;
                }
                if (val < 0) {
                    scalarQty.addClass('error');
                }
            }

            // Also check array-style quantity[] fields (batch import form)
            form.find('input[name^="quantity["]').each(function() {
                var val = parseFloat($(this).val());
                if (val > 0) {
                    hasQuantity = true;
                }
                if (val < 0) {
                    $(this).addClass('error');
                }
            });

            // For the single-product form, also check that a product is selected
            var productSelect = form.find('select[name="product"]');
            if (productSelect.length && !productSelect.val()) {
                alert('Please select a product.');
                productSelect.addClass('error');
                return false;
            }

            if (!hasQuantity) {
                alert('Please enter a quantity greater than 0.');
                return false;
            }
            
            return true;
        },

        // Validate stock form — checks used[] inputs in the table rows
        validateStockForm: function(form) {
            var isValid = true;
            
            form.find('tr[data-product]').each(function() {
                var row = $(this);
                var used = parseFloat(row.find('.used-packs').val()) || 0;
                
                if (used < 0) {
                    row.find('.used-packs').addClass('error');
                    isValid = false;
                }
            });
            
            if (!isValid) {
                alert('Negative values are not allowed. Please correct the highlighted fields.');
            }
            
            return isValid;
        },

        // Validate chopped form — checks prepared/packs inputs in the table rows
        validateChoppedForm: function(form) {
            var isValid = true;
            
            form.find('tr[data-fruit]').each(function() {
                var row = $(this);
                var prepared = parseFloat(row.find('.prepared-whole').val()) || 0;
                var packs = parseFloat(row.find('.packs-gotten').val()) || 0;
                
                if (prepared < 0) {
                    row.find('.prepared-whole').addClass('error');
                    isValid = false;
                }
                if (packs < 0) {
                    row.find('.packs-gotten').addClass('error');
                    isValid = false;
                }
            });
            
            if (!isValid) {
                alert('Negative values are not allowed. Please correct the highlighted fields.');
            }
            
            return isValid;
        },

        // Calculate stock closing values
        calculateStockClosing: function() {
            var row = $(this).closest('tr');
            var opening = parseFloat(row.find('.opening-packs').val()) || 0;
            var added = parseFloat(row.find('.added-packs').val()) || 0;
            var used = parseFloat(row.find('.used-packs').val()) || 0;
            
            var closing = opening + added - used;
            row.find('.closing-packs').val(closing.toFixed(2));
            
            // Highlight the change
            row.addClass('ims-updated');
            setTimeout(function() {
                row.removeClass('ims-updated');
            }, 1000);
        },

        // Calculate chopped closing values
        calculateChoppedClosing: function() {
            var row = $(this).closest('tr');
            var opening = parseFloat(row.find('.opening-whole').val()) || 0;
            var imported = parseFloat(row.find('.import-whole').val()) || 0;
            var prepared = parseFloat(row.find('.prepared-whole').val()) || 0;
            
            var closing = opening + imported - prepared;
            row.find('.closing-whole').val(closing.toFixed(2));
            
            // Highlight the change
            row.addClass('ims-updated');
            setTimeout(function() {
                row.removeClass('ims-updated');
            }, 1000);
        },

        // Update current time display
        updateCurrentTime: function() {
            $.ajax({
                url: ims_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ims_get_current_time',
                    nonce: ims_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#ims-current-time, #ims-stock-current-time, #ims-chopped-current-time').val(response.data.time);
                    }
                }
            });
        },

        // Show message to user
        showMessage: function(element, type, message) {
            element.removeClass('success error info')
                   .addClass(type)
                   .html(message)
                   .show();
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                element.fadeOut();
            }, 5000);
        },

        // Update integrated forms after import
        updateIntegratedForms: function(data) {
            var product = data.product;
            var quantity = data.quantity;
            
            // Check if this product affects other forms
            if (this.isFruit(product)) {
                // Update chopped form import whole
                var choppedRow = $(`.ims-chopped-table tr[data-fruit="${product}"]`);
                if (choppedRow.length) {
                    var currentImport = parseFloat(choppedRow.find('.import-whole').val()) || 0;
                    choppedRow.find('.import-whole').val((currentImport + quantity).toFixed(2));
                    choppedRow.find('.import-whole').trigger('input');
                }
            } else {
                // Update stock form added packs
                var stockRow = $(`.ims-stock-table tr[data-product="${product}"]`);
                if (stockRow.length) {
                    var currentAdded = parseFloat(stockRow.find('.added-packs').val()) || 0;
                    stockRow.find('.added-packs').val((currentAdded + quantity).toFixed(2));
                    stockRow.find('.added-packs').trigger('input');
                }
            }
        },

        // Update stock form from chopped packs gotten changes
        updateStockFromChopped: function(data) {
            if (data.records) {
                data.records.forEach(function(record) {
                    var stockRow = $(`.ims-stock-table tr[data-product="${record.fruit}"]`);
                    if (stockRow.length && record.packs_gotten > 0) {
                        var currentAdded = parseFloat(stockRow.find('.added-packs').val()) || 0;
                        stockRow.find('.added-packs').val((currentAdded + record.packs_gotten).toFixed(2));
                        stockRow.find('.added-packs').trigger('input');
                    }
                });
            }
        },

        // Check if product is a fruit
        isFruit: function(product) {
            var fruits = [
                'Almond', 'Cucumber', 'Dates', 'Fresh Coconut', 'Ginger', 'Grape',
                'Ice Cream', 'Kiwi', 'Lemon', 'Lime', 'Paw Paw', 'Pineapple',
                'Tiger Nut', 'Watermelon'
            ];
            return fruits.includes(product);
        },

        // Initialize analytics
        initAnalytics: function() {
            this.loadAnalyticsData();
        },

        // Load analytics data
        loadAnalyticsData: function() {
            var container = $('.ims-analytics-cards');
            if (container.length === 0) return;
            
            container.addClass('ims-updating');
            
            $.ajax({
                url: ims_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ims_refresh_analytics',
                    nonce: ims_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        IMS.updateAnalyticsDisplay(response.data);
                    }
                },
                complete: function() {
                    container.removeClass('ims-updating');
                }
            });
        },

        // Update analytics display
        updateAnalyticsDisplay: function(data) {
            $('.ims-analytics-cards .ims-analytics-card').each(function() {
                var card = $(this);
                var iconEl = card.find('.ims-card-icon iconify-icon');
                var icon = iconEl.length ? iconEl.attr('icon') : '';
                var numberElement = card.find('.ims-card-number');
                
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
                        card.addClass('ims-updated');
                        setTimeout(function() {
                            card.removeClass('ims-updated');
                        }, 1000);
                    }
                }
            });
        },

        // Refresh analytics
        refreshAnalytics: function() {
            IMS.loadAnalyticsData();
        },

        // Validate individual field
        validateField: function() {
            var field = $(this);
            var value = field.val();
            var type = field.attr('type');
            
            field.removeClass('error');
            
            if (type === 'number') {
                var numValue = parseFloat(value);
                var min = parseFloat(field.attr('min')) || 0;
                var max = parseFloat(field.attr('max'));
                
                if (isNaN(numValue) || numValue < min || (max && numValue > max)) {
                    field.addClass('error');
                }
            }
            
            if (field.prop('required') && !value) {
                field.addClass('error');
            }
        },

        // Auto-save form data
        autoSaveFormData: function() {
            // Save form data to localStorage
            $('.ims-form').each(function() {
                var form = $(this);
                var formId = form.attr('id');
                if (formId) {
                    var formData = form.serializeArray();
                    localStorage.setItem('ims_' + formId, JSON.stringify(formData));
                }
            });
        },

        // Restore form data
        restoreFormData: function() {
            $('.ims-form').each(function() {
                var form = $(this);
                var formId = form.attr('id');
                if (formId) {
                    var savedData = localStorage.getItem('ims_' + formId);
                    if (savedData) {
                        try {
                            var formData = JSON.parse(savedData);
                            formData.forEach(function(field) {
                                form.find('[name="' + field.name + '"]').val(field.value);
                            });
                        } catch (e) {
                            console.log('Error restoring form data:', e);
                        }
                    }
                }
            });
        },

        // Log activity
        logActivity: function(action, data) {
            console.log('IMS Activity:', action, data);
            
            // Store in localStorage for debugging
            var activities = JSON.parse(localStorage.getItem('ims_activities') || '[]');
            activities.push({
                timestamp: new Date().toISOString(),
                action: action,
                data: data
            });
            
            // Keep only last 50 activities
            if (activities.length > 50) {
                activities = activities.slice(-50);
            }
            
            localStorage.setItem('ims_activities', JSON.stringify(activities));
        }
    };

    // Utility functions
    $.fn.serializeObject = function() {
        var o = {};
        var a = this.serializeArray();
        $.each(a, function() {
            if (o[this.name]) {
                if (!o[this.name].push) {
                    o[this.name] = [o[this.name]];
                }
                o[this.name].push(this.value || '');
            } else {
                o[this.name] = this.value || '';
            }
        });
        return o;
    };

})(jQuery);