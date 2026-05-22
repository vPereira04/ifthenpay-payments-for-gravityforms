/**
 * ifthenpay | Payments for Gravity Forms — Admin JS
 *
 * Two responsibilities only:
 *   1. Connect / Disconnect the Backoffice Key on the Plugin Settings page.
 *   2. Keep the Default Method dropdown in sync with the methods-table
 *      checkboxes on the Feed Settings page (purely client-side; the methods
 *      table is server-rendered on every page load — no AJAX hops).
 * @param $
 */
(function ($) {
	'use strict';

	const strings =
		typeof ifthenpay_gf_admin_strings !== 'undefined'
			? ifthenpay_gf_admin_strings
			: {};
	const ajaxUrl = strings.ajax_url || ajaxurl;
	const nonce = strings.nonce || '';



	function getKeyInput() {
		return $('#iftp-gf-backoffice-key-input');
	}

	function setMessage(msg, type) {
		const $el = $('.iftp-gf-message').first();
		$el.removeClass('iftp-gf-message--success iftp-gf-message--error').text(msg || '');
		if (type) {
			$el.addClass('iftp-gf-message--' + type);
		}
	}

	function refreshConnectionCard(html) {
		if (!html) {
			return;
		}
		$('#iftp-gf-connection-status-card').replaceWith(html);
		syncKeyFieldVisibility();
	}

	function syncKeyFieldVisibility() {
		const isConnected = !!$('#iftp-gf-disconnect-backoffice').length;
		$('.iftp-gf-key-row').toggleClass(
			'iftp-gf-key-row--hidden',
			isConnected
		);
	}

	function connectionNonce() {
		return $('#iftp-gf-nonce').val() || nonce;
	}

	$(document).on('click', '#iftp-gf-connect-backoffice', function (e) {
		e.preventDefault();
		e.stopPropagation();

		const $btn = $(this);
		const key = $.trim(getKeyInput().val() || '');

		if (!key || /^\*+$/.test(key)) {
			setMessage(strings.generic_error || 'Enter a valid key.', 'error');
			getKeyInput().trigger('focus');
			return;
		}

		$btn.prop('disabled', true).text(strings.connecting || 'Connecting...');
		setMessage('', '');

		$.post(
			ajaxUrl,
			{
				action: 'iftp_gf_connect_backoffice',
				nonce: connectionNonce(),
				backoffice_key: key,
			},
			null,
			'json'
		)
			.done(function (res) {
				if (res && res.success) {
					getKeyInput().val(
						res.data.masked_key || '******************'
					);
					if (res.data.status_html) {
						refreshConnectionCard(res.data.status_html);
					}
					setMessage(res.data.message || '', 'success');
					return;
				}
				setMessage(
					(res && res.data && res.data.message) ||
						strings.generic_error,
					'error'
				);
				$btn.prop('disabled', false).text(strings.connect || 'Connect');
			})
			.fail(function (xhr) {
				const msg =
					xhr &&
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message;
				setMessage(msg || strings.generic_error, 'error');
				$btn.prop('disabled', false).text(strings.connect || 'Connect');
			});
	});

	$(document).on('click', '#iftp-gf-disconnect-backoffice', function (e) {
		e.preventDefault();
		e.stopPropagation();

		const $btn = $(this);
		$btn.prop('disabled', true).text(
			strings.disconnecting || 'Disconnecting...'
		);
		setMessage('', '');

		$.post(
			ajaxUrl,
			{
				action: 'iftp_gf_disconnect_backoffice',
				nonce: connectionNonce(),
			},
			null,
			'json'
		)
			.done(function (res) {
				if (res && res.success) {
					getKeyInput().val('');
					$('#iftp-gf-connect-backoffice')
						.prop('disabled', false)
						.text(strings.connect || 'Connect');
					if (res.data.status_html) {
						refreshConnectionCard(res.data.status_html);
					}
					setMessage(res.data.message || '', 'success');
					return;
				}
				setMessage(
					(res && res.data && res.data.message) ||
						strings.generic_error,
					'error'
				);
				$btn.prop('disabled', false).text(
					strings.disconnect || 'Disconnect'
				);
			})
			.fail(function (xhr) {
				const msg =
					xhr &&
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message;
				setMessage(msg || strings.generic_error, 'error');
				$btn.prop('disabled', false).text(
					strings.disconnect || 'Disconnect'
				);
			});
	});

	$(document).on('focus', '#iftp-gf-backoffice-key-input', function () {
		if (/^\*+$/.test($.trim($(this).val()))) {
			$(this).val('');
		}
	});



	function syncDefaultMethodDropdown() {
		const $select = $('[name="_gform_setting_default_method"]');
		if (!$select.length) {
			return;
		}


		const enabled = {};
		$('#iftp-gf-methods-table-wrapper .iftp-gf-method-item__toggle').each(
			function () {
				const entity = ($(this).data('entity') || '').toUpperCase();
				enabled[entity] = $(this).is(':checked');
			}
		);

		$select.find('option').each(function () {
			const val = $(this).val();
			if (val === '') {
				return;
			}
			const entity = val.toUpperCase();
			if (Object.prototype.hasOwnProperty.call(enabled, entity)) {
				$(this).prop('disabled', !enabled[entity]);
			}
		});


		const current = $select.val();
		if (
			current !== '' &&
			$select.find('option[value="' + current + '"]').prop('disabled')
		) {
			$select.val('');
		}
	}

	$(document).on(
		'change',
		'#iftp-gf-methods-table-wrapper .iftp-gf-method-item__toggle',
		syncDefaultMethodDropdown
	);



	$(document).on('click', '.iftp-gf-method-item__activate-btn', function (e) {
		e.preventDefault();

		const $btn = $(this);
		const entity = $btn.data('entity');
		const gatewayKey = $btn.data('gateway-key');

		$btn.prop('disabled', true).text(
			strings.activation_sending || 'Sending...'
		);

		$.post(
			ajaxUrl,
			{
				action: 'iftp_gf_activate_method',
				nonce: connectionNonce(),
				entity,
				gateway_key: gatewayKey,
			},
			null,
			'json'
		)
			.done(function (res) {
				if (res && res.success) {
					$btn.closest('.iftp-gf-method-item__right').html(
						'<em class="iftp-gf-method-item__activation-sent">' +
							(strings.activation_sent || 'Request sent.') +
							'</em>'
					);
				} else {
					const msg =
						(res && res.data && res.data.message) ||
						strings.activation_cooldown ||
						'Request already sent.';
					$btn.closest('.iftp-gf-method-item__right').html(
						'<em class="iftp-gf-method-item__activation-cooldown">' +
							msg +
							'</em>'
					);
				}
			})
			.fail(function () {
				$btn.prop('disabled', false).text(
					strings.activation_button || 'Request Activation'
				);
			});
	});



	function loadMethodsTable(gatewayKey) {
		const $wrapper = $('#iftp-gf-methods-table-wrapper');
		const $defaultSelect = $('[name="_gform_setting_default_method"]');

		$wrapper.html(
			'<p class="iftp-gf-loading">' +
				(strings.methods_loading || 'Loading payment methods…') +
				'</p>'
		);

		$.post(
			ajaxUrl,
			{
				action: 'iftp_gf_get_methods_table',
				nonce: connectionNonce(),
				gateway_key: gatewayKey,
			},
			null,
			'json'
		)
			.done(function (res) {
				if (res && res.success) {
					$wrapper.html(res.data.table_html || '');
					if ($defaultSelect.length && res.data.default_options) {
						const current = $defaultSelect.val();
						$defaultSelect.html(res.data.default_options);
						if (
							$defaultSelect
								.find('option[value="' + current + '"]')
								.length
						) {
							$defaultSelect.val(current);
						}
					}
					syncDefaultMethodDropdown();
				} else {
					$wrapper.html(
						'<p class="iftp-gf-error">' +
							((res && res.data && res.data.message) ||
								(strings.generic_error || 'Request failed.')) +
							'</p>'
					);
				}
			})
			.fail(function () {
				$wrapper.html(
					'<p class="iftp-gf-error">' +
						(strings.generic_error || 'Request failed.') +
						'</p>'
				);
			});
	}

	$(document).on(
		'change',
		'select[name="_gform_setting_gateway_key"]',
		function () {
			loadMethodsTable($(this).val() || '');
		}
	);



	$(function () {
		syncKeyFieldVisibility();
		syncDefaultMethodDropdown();
	});
})(jQuery);
