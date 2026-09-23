/**
 * MandrakeCRM Admin Scripts
 *
 * @package    MandrakeCRM
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      2.0.0
 */

(function($) {
	'use strict';

	var MandrakeCRMAdmin = {

		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			$('#mandrakecrm-verify-token').on('click', this.verifyToken);
			$('#mandrakecrm-settings-form').on('submit', this.saveSettings);
			$('#disconnect-integration').on('click', function(e) {
				e.preventDefault();
				$('#mandrakecrm-disconnect-modal').fadeIn(200);
			});
		},

		verifyToken: function(e) {
			e.preventDefault();

			var $button = $(this);
			var $tokenInput = $('#mandrakecrm_token');
			var token = $tokenInput.val().trim();

			if (!token) {
				MandrakeCRMAdmin.showTokenStatus('error', mandrakecrm.i18n.error + ': Token is required');
				return;
			}

			$button.prop('disabled', true).text(mandrakecrm.i18n.connecting);

			$.ajax({
				url: mandrakecrm.ajax_url,
				type: 'POST',
				data: {
					action: 'mandrakecrm_verify_token',
					nonce: mandrakecrm.nonce,
					token: token
				},
				success: function(response) {
					if (response.success) {
						var message = response.data.message;
						if (response.data.store_name) {
							message += ' - ' + response.data.store_name;
						}
						MandrakeCRMAdmin.showTokenStatus('success', message);
						MandrakeCRMAdmin.updateConnectionStatus(true, response.data.store_name);

						// Token saved on server during verification - just reload
						setTimeout(function() {
							window.location.reload();
						}, 500);
					} else {
						MandrakeCRMAdmin.showTokenStatus('error', response.data.message || mandrakecrm.i18n.error);
						MandrakeCRMAdmin.updateConnectionStatus(false);
					}
				},
				error: function() {
					MandrakeCRMAdmin.showTokenStatus('error', mandrakecrm.i18n.error);
					MandrakeCRMAdmin.updateConnectionStatus(false);
				},
				complete: function() {
					$button.prop('disabled', false).text(mandrakecrm.i18n.connect_button);
				}
			});
		},

		showTokenStatus: function(type, message) {
			var $status = $('#mandrakecrm-token-status');
			$status
				.removeClass('mandrakecrm-token-status--success mandrakecrm-token-status--error')
				.addClass('mandrakecrm-token-status--' + type)
				.text(message)
				.show();
		},

		updateConnectionStatus: function(connected, storeName) {
			var $status = $('.mandrakecrm-status');
			var $text = $status.find('.mandrakecrm-status__text');

			$status
				.removeClass('mandrakecrm-status--connected mandrakecrm-status--disconnected')
				.addClass(connected ? 'mandrakecrm-status--connected' : 'mandrakecrm-status--disconnected');

			if (connected && storeName) {
				$text.text(mandrakecrm.i18n.connected_to.replace('%s', storeName));
			} else if (connected) {
				$text.text(mandrakecrm.i18n.connected);
			} else {
				$text.text(mandrakecrm.i18n.disconnected);
			}
		},

		saveSettings: function(e) {
			e.preventDefault();

			var $form = $(this);
			var $button = $form.find('button[type="submit"]');
			var $status = $('#mandrakecrm-save-status');

			$button.prop('disabled', true).text(mandrakecrm.i18n.saving);
			$status.text('');

			// Build data object - always send both checkbox states
			var data = {
				action: 'mandrakecrm_save_settings',
				nonce: mandrakecrm.nonce,
				mandrakecrm_transactional_emails: $('input[name="mandrakecrm_transactional_emails"]').is(':checked') ? '1' : '0',
				mandrakecrm_widget_option: $('input[name="mandrakecrm_widget_option"]').is(':checked') ? '1' : '0',
				mandrakecrm_abandoned_cart: $('input[name="mandrakecrm_abandoned_cart"]').is(':checked') ? '1' : '0'
			};

			$.ajax({
				url: mandrakecrm.ajax_url,
				type: 'POST',
				data: data,
				success: function(response) {
					if (response.success) {
						$status.css('color', 'var(--mcrm-success)').text(mandrakecrm.i18n.saved);

						// Reload page after successful save to ensure options persist
						setTimeout(function() {
							window.location.reload();
						}, 500);
					} else {
						$status.css('color', 'var(--mcrm-danger)').text(response.data.message || mandrakecrm.i18n.error);
					}
				},
				error: function() {
					$status.css('color', 'var(--mcrm-danger)').text(mandrakecrm.i18n.error);
				},
				complete: function() {
					$button.prop('disabled', false).text(mandrakecrm.i18n.save_changes_button);

					setTimeout(function() {
						$status.text('');
					}, 3000);
				}
			});
		}
	};

	// Modal event handlers
	$('.mandrakecrm-modal-close, .mandrakecrm-modal-cancel, .mandrakecrm-modal-overlay').on('click', function() {
		$('#mandrakecrm-disconnect-modal').fadeOut(200);
	});

	// Prevent modal from closing when clicking on the container itself
	$('.mandrakecrm-modal-container').on('click', function(e) {
		e.stopPropagation();
	});

	// Confirm disconnect
	$('#confirm-disconnect').on('click', function() {
		var $button = $(this);
		$button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> ' + mandrakecrm.i18n.disconnecting);

		$.ajax({
			url: mandrakecrm.ajax_url,
			type: 'POST',
			data: {
				action: 'mandrakecrm_disconnect_integration',
				nonce: mandrakecrm.nonce
			},
			success: function(response) {
				if (response.success) {
					$('#mandrakecrm-disconnect-modal').fadeOut(200);
					$('.mandrakecrm-notices-container').prepend(
						'<div class="notice notice-success is-dismissible"><p>' +
						mandrakecrm.i18n.disconnect_success +
						'</p></div>'
					);
					setTimeout(function() {
						window.location.reload();
					}, 1000);
				} else {
					$('#mandrakecrm-disconnect-modal').fadeOut(200);
					alert(response.data.message || mandrakecrm.i18n.error);
					$button.prop('disabled', false).html('<span class="dashicons dashicons-dismiss"></span> ' + mandrakecrm.i18n.yes_disconnect_button);
				}
			},
			error: function() {
				$('#mandrakecrm-disconnect-modal').fadeOut(200);
				alert(mandrakecrm.i18n.disconnect_error);
				$button.prop('disabled', false).html('<span class="dashicons dashicons-dismiss"></span> ' + mandrakecrm.i18n.yes_disconnect_button);
			}
		});
	});

	$(document).ready(function() {
		// Move WordPress notices from inside mandrakecrm-wrap to notices container
		// This ensures they appear above the card, not inside it
		if ($('.mandrakecrm-notices-container').length > 0) {
			var notices = $('.mandrakecrm-wrap').find('> .notice, > .updated, > .error, > .update-nag');
			if (notices.length > 0) {
				notices.detach().prependTo('.mandrakecrm-notices-container');
			}
		}

		MandrakeCRMAdmin.init();
	});

})(jQuery);
