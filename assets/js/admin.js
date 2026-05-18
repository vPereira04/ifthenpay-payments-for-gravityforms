(function ($) {
	'use strict';

	const strings =
		typeof ifthenpay_gf_admin_strings !== 'undefined'
			? ifthenpay_gf_admin_strings
			: {};
	const ajaxUrl = strings.ajax_url || ajaxurl;
	const nonce = strings.nonce || '';

	// -------------------------------------------------------------------------
	// Plugin settings page — backoffice key connect / disconnect
	// -------------------------------------------------------------------------

	function getKeyInput() {
		return $('#iftp-gf-backoffice-key-input');
	}

	function setMessage(msg, type) {
		const $el = $('.iftp-gf-message').first();
		$el.removeClass('is-success is-error').text(msg || '');
		if (type) {
			$el.addClass('is-' + type);
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
			'iftp-gf-key-field-hidden',
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

	$(document).on('change', '#iftp-gf-settings-gateway', function () {
		const gatewayKey = $(this).val();

		if (!gatewayKey) {
			$('#iftp-gf-settings-methods-wrapper').html('');
			return;
		}

		$.post(
			ajaxurl,
			{
				action: 'iftp_gf_get_gateway_methods',
				gateway_key: gatewayKey,
				nonce: $('#iftp-gf-nonce').val(),
			},
			function (response) {
				if (response.success) {
					$('#iftp-gf-settings-methods-wrapper').html(
						response.data.html
					);
				}
			}
		);
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

	// -------------------------------------------------------------------------
	// Feed settings page — gateway methods table
	// -------------------------------------------------------------------------

	const iftpGfFeedSettings = {
		onGatewayKeyChange(selectEl) {
			const gatewayKey = $(selectEl).val();
			const $wrapper = $('#iftp-gf-methods-table-wrapper');
			const feedNonce = $wrapper.data('nonce') || nonce;

			if (!gatewayKey) {
				$wrapper.html(
					'<p class="iftp-gf-no-methods">' +
						(strings.loading_methods ||
							'Select a gateway key to load methods.') +
						'</p>'
				);
				return;
			}

			$wrapper.html(
				'<p class="iftp-gf-loading">' +
					(strings.loading_methods || 'Loading methods...') +
					'</p>'
			);

			$.post(
				ajaxUrl,
				{
					action: 'iftp_gf_load_gateway_methods',
					nonce: feedNonce,
					gateway_key: gatewayKey,
				},
				null,
				'json'
			)
				.done(function (res) {
					if (res && res.success && res.data && res.data.html) {
						$wrapper.html(res.data.html);
					} else {
						$wrapper.html(
							'<p class="iftp-gf-no-methods">No methods found for this gateway key.</p>'
						);
					}
				})
				.fail(function () {
					$wrapper.html(
						'<p class="iftp-gf-error">Failed to load methods. Please try again.</p>'
					);
				});
		},
	};

	window.iftpGfFeedSettings = iftpGfFeedSettings;

	// -------------------------------------------------------------------------
	// Feed settings page — activate payment method
	// -------------------------------------------------------------------------

	$(document).on('click', '.iftp-gf-activate-method', function (e) {
		e.preventDefault();

		const $btn = $(this);
		const entity = $btn.data('entity');
		const gatewayKey = $btn.data('gateway-key');
		const $wrapper = $('#iftp-gf-methods-table-wrapper');
		const feedNonce = $wrapper.data('nonce') || nonce;

		$btn.prop('disabled', true).text('Sending...');

		$.post(
			ajaxUrl,
			{
				action: 'iftp_gf_activate_method',
				nonce: feedNonce,
				entity,
				gateway_key: gatewayKey,
			},
			null,
			'json'
		)
			.done(function (res) {
				if (res && res.success) {
					$btn.closest('td').html(
						'<em class="iftp-gf-activation-sent">' +
							(strings.activation_sent || 'Request sent.') +
							'</em>'
					);
				} else {
					const msg =
						(res && res.data && res.data.message) ||
						strings.activation_cooldown ||
						'Request already sent.';
					$btn.closest('td').html(
						'<em class="iftp-gf-activation-cooldown">' +
							msg +
							'</em>'
					);
				}
			})
			.fail(function () {
				$btn.prop('disabled', false).text('Request Activation');
			});
	});

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	$(function () {
		syncKeyFieldVisibility();
	});
})(jQuery);
