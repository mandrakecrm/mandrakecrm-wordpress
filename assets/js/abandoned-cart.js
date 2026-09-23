/**
 * MandrakeCRM Abandoned Cart - Checkout Field Capture
 *
 * Captures checkout fields in real-time via blur events and sends to backend.
 * Supports both Classic Checkout and Checkout Blocks.
 * Fields: billing_email, billing_phone, billing_cellphone (Brazilian Market),
 *         billing_first_name, billing_last_name
 *
 * @version 1.1.0
 * @author JJ Nieva
 */

(function($) {
    'use strict';

    // =============================================================================
    // Configuration
    // =============================================================================

    const DEBOUNCE_DELAY = 300; // milliseconds - industry best practice
    const BLOCKS_INIT_DELAY = 1000; // Wait for Checkout Blocks to render

    // Fields to monitor with blur event (Classic Checkout)
    const CAPTURE_FIELDS = [
        { id: 'billing_email', type: 'email' },
        { id: 'billing_phone', type: 'phone' },
        { id: 'billing_cellphone', type: 'phone' }, // Brazilian Market
        { id: 'billing_first_name', type: 'text' },
        { id: 'billing_last_name', type: 'text' }
    ];

    // Fields for Checkout Blocks (different selectors)
    const BLOCKS_CAPTURE_FIELDS = [
        { selector: '#email', key: 'billing_email', type: 'email' },
        { selector: '#billing-phone, #phone', key: 'billing_phone', type: 'phone' },
        { selector: '#billing-cellphone', key: 'billing_cellphone', type: 'phone' },
        { selector: '#billing-first_name', key: 'billing_first_name', type: 'text' },
        { selector: '#billing-last_name', key: 'billing_last_name', type: 'text' }
    ];

    // =============================================================================
    // State
    // =============================================================================

    let capturedData = {};
    let debounceTimers = {};
    let lastSavedData = {};
    let isSubmitting = false;

    // =============================================================================
    // Initialization
    // =============================================================================

    $(document).ready(function() {
        // Check if configuration object exists
        if (typeof mandrakeCRMAbandonedCart === 'undefined') {
            console.warn('MandrakeCRM: Abandoned cart configuration not found');
            return;
        }

        // Detect checkout type and initialize accordingly
        if (mandrakeCRMAbandonedCart.isCheckoutBlocks) {
            initCheckoutBlocks();
        } else {
            initClassicCheckout();
        }
    });

    /**
     * Initialize for Classic Checkout
     */
    function initClassicCheckout() {
        // Initial attach of blur listeners
        attachBlurListeners();

        // Capture pre-populated fields on page load
        capturePrePopulatedFields();

        // Re-attach after WooCommerce AJAX checkout update
        $(document.body).on('updated_checkout', function() {
            attachBlurListeners();
            capturePrePopulatedFields();
        });

        // Re-attach after checkout fragments update
        $(document.body).on('wc_fragments_refreshed', function() {
            attachBlurListeners();
        });
    }

    /**
     * Initialize for Checkout Blocks
     * Uses MutationObserver to detect when fields are rendered
     */
    function initCheckoutBlocks() {
        // Wait for Checkout Blocks to render
        setTimeout(function() {
            attachBlocksListeners();
            captureBlocksPrePopulatedFields();
        }, BLOCKS_INIT_DELAY);

        // Use MutationObserver to re-attach when DOM changes
        var observer = new MutationObserver(function(mutations) {
            var shouldReattach = false;

            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    shouldReattach = true;
                }
            });

            if (shouldReattach) {
                clearTimeout(debounceTimers['observer']);
                debounceTimers['observer'] = setTimeout(function() {
                    attachBlocksListeners();
                }, DEBOUNCE_DELAY);
            }
        });

        // Observe the checkout form container
        var checkoutContainer = document.querySelector('.wc-block-checkout, .wp-block-woocommerce-checkout');
        if (checkoutContainer) {
            observer.observe(checkoutContainer, {
                childList: true,
                subtree: true
            });
        }
    }

    // =============================================================================
    // Pre-populated Field Capture
    // =============================================================================

    /**
     * Capture pre-populated field values (Classic Checkout)
     */
    function capturePrePopulatedFields() {
        var hasData = false;

        CAPTURE_FIELDS.forEach(function(field) {
            var $field = $('#' + field.id);

            if ($field.length === 0) {
                return;
            }

            var value = $field.val();

            if (typeof value === 'string') {
                value = value.trim();
            }

            if (value && basicValidate(value, field.type)) {
                if (capturedData[field.id] !== value) {
                    capturedData[field.id] = value;
                    hasData = true;
                }
            }
        });

        if (hasData) {
            saveToLocal();
        }
    }

    /**
     * Capture pre-populated field values (Checkout Blocks)
     */
    function captureBlocksPrePopulatedFields() {
        var hasData = false;

        BLOCKS_CAPTURE_FIELDS.forEach(function(field) {
            var $field = $(field.selector);

            if ($field.length === 0) {
                return;
            }

            var value = $field.val();

            if (typeof value === 'string') {
                value = value.trim();
            }

            if (value && basicValidate(value, field.type)) {
                if (capturedData[field.key] !== value) {
                    capturedData[field.key] = value;
                    hasData = true;
                }
            }
        });

        if (hasData) {
            saveToLocal();
        }
    }

    // =============================================================================
    // Event Listeners
    // =============================================================================

    /**
     * Attach blur listeners to capture fields (Classic Checkout)
     * Uses namespace to prevent duplicate listeners
     */
    function attachBlurListeners() {
        CAPTURE_FIELDS.forEach(function(field) {
            var $field = $('#' + field.id);

            if ($field.length === 0) {
                return;
            }

            // Remove previous listener to avoid duplicates
            $field.off('blur.mandrakecrm');

            // Add namespaced listener
            $field.on('blur.mandrakecrm', function() {
                var value = $(this).val();

                if (typeof value === 'string') {
                    value = value.trim();
                }

                // Debounce to avoid excessive AJAX calls
                clearTimeout(debounceTimers[field.id]);

                debounceTimers[field.id] = setTimeout(function() {
                    // Basic non-restrictive validation (real validation happens in backend)
                    if (basicValidate(value, field.type)) {
                        // Only save if value changed
                        if (capturedData[field.id] !== value) {
                            capturedData[field.id] = value;
                            saveToLocal();
                        }
                    }
                }, DEBOUNCE_DELAY);
            });
        });
    }

    /**
     * Attach blur listeners for Checkout Blocks
     * Blocks use different field IDs and React-managed inputs
     */
    function attachBlocksListeners() {
        BLOCKS_CAPTURE_FIELDS.forEach(function(field) {
            var $field = $(field.selector);

            if ($field.length === 0) {
                return;
            }

            // Remove previous listeners
            $field.off('blur.mandrakecrm change.mandrakecrm');

            // Add listeners for both blur and change (Blocks may not fire blur consistently)
            $field.on('blur.mandrakecrm change.mandrakecrm', function() {
                var value = $(this).val();

                if (typeof value === 'string') {
                    value = value.trim();
                }

                clearTimeout(debounceTimers[field.key]);

                debounceTimers[field.key] = setTimeout(function() {
                    if (basicValidate(value, field.type)) {
                        if (capturedData[field.key] !== value) {
                            capturedData[field.key] = value;
                            saveToLocal();
                        }
                    }
                }, DEBOUNCE_DELAY);
            });
        });
    }

    // =============================================================================
    // Validation
    // =============================================================================

    /**
     * Basic non-restrictive validation
     * Real validation happens in the backend
     *
     * @param {string} value - The value to validate
     * @param {string} type - The field type (email, phone, text)
     * @returns {boolean} - Whether value passes basic validation
     */
    function basicValidate(value, type) {
        // Empty values are not valid
        if (!value || value.length === 0) {
            return false;
        }

        if (type === 'email') {
            // Only check that it has @ and something after it
            // Backend will do proper email validation
            return value.indexOf('@') > 0 && value.indexOf('@') < value.length - 1;
        }

        if (type === 'phone') {
            // Only check minimum digits (6 is minimum for any country)
            // Backend will normalize to E.164 format
            var digits = value.replace(/\D/g, '');
            return digits.length >= 6;
        }

        // Text fields just need to have content
        return value.length > 0;
    }

    // =============================================================================
    // AJAX Save
    // =============================================================================

    /**
     * Save captured data to WordPress via AJAX
     * Backend stores in local table and handles sync
     */
    function saveToLocal() {
        // Prevent concurrent submissions
        if (isSubmitting) {
            return;
        }

        // Check if data actually changed from last save
        var dataChanged = false;
        for (var key in capturedData) {
            if (capturedData.hasOwnProperty(key) && capturedData[key] !== lastSavedData[key]) {
                dataChanged = true;
                break;
            }
        }

        if (!dataChanged) {
            return;
        }

        isSubmitting = true;

        $.ajax({
            url: mandrakeCRMAbandonedCart.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mandrakecrm_save_abandoned_cart_field',
                nonce: mandrakeCRMAbandonedCart.nonce,
                data: capturedData
            },
            success: function(response) {
                if (response && response.success) {
                    // Update last saved data
                    lastSavedData = $.extend({}, capturedData);
                }
            },
            error: function(xhr, status, error) {
                // Silent error - don't interrupt user experience
                // Log for debugging purposes
                if (window.console && console.error) {
                    console.error('MandrakeCRM: Error saving cart field', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                }
            },
            complete: function() {
                isSubmitting = false;
            }
        });
    }

})(jQuery);
