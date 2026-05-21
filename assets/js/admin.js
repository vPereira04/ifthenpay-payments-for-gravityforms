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
		$('#iftp-gf-methods-table-wrapper .iftp-gf-method-toggle').each(
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
		'#iftp-gf-methods-table-wrapper .iftp-gf-method-toggle',
		syncDefaultMethodDropdown
	);



	function escapeHtml(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function renderMethodsListHtml(gatewayKey, feedData) {
		if (!feedData || !Array.isArray(feedData.methods)) {
			return '';
		}
		const accounts = feedData.gatewayAccounts[gatewayKey] || {};

		const items = feedData.methods
			.map(function (m) {
				const account = accounts[m.entity] || '';
				const isProvisioned = account !== '';
				const isWideLogo = m.entity === 'CCARD';
				const itemClasses =
					'iftp-gf-method-item' +
					(isProvisioned ? '' : ' is-unactivated');

				const checkbox =
					'<input type="checkbox" ' +
					'name="_gform_setting_methods_config[' +
					escapeHtml(m.entity) +
					'][enabled]" value="1" class="iftp-gf-method-toggle" ' +
					'data-entity="' +
					escapeHtml(m.entity) +
					'"' +
					(isProvisioned ? '' : ' disabled') +
					' />';

				const hidden =
					'<input type="hidden" ' +
					'name="_gform_setting_methods_config[' +
					escapeHtml(m.entity) +
					'][account]" value="' +
					escapeHtml(account) +
					'" />';

				const right = isProvisioned
					? '<code class="iftp-gf-account-code">' +
						escapeHtml(account) +
						'</code><span class="iftp-gf-active-badge" title="' +
						escapeHtml(feedData.strings.provisioned) +
						'">&#10003;</span>'
					: '<em class="iftp-gf-no-account">' +
						escapeHtml(feedData.strings.notActivated) +
						'</em><button type="button" class="button button-small iftp-gf-activate-method" data-entity="' +
						escapeHtml(m.entity) +
						'" data-gateway-key="' +
						escapeHtml(gatewayKey) +
						'">' +
						escapeHtml(feedData.strings.requestActivation) +
						'</button>';

				return (
					'<div class="' +
					itemClasses +
					'" data-entity="' +
					escapeHtml(m.entity) +
					'">' +
					'<label class="iftp-gf-method-item-label">' +
					checkbox +
					hidden +
					'<span class="iftp-gf-method-icon-wrap' +
					(isWideLogo ? ' is-wide' : '') +
					'"><img src="' +
					escapeHtml(m.img_url) +
					'" alt="' +
					escapeHtml(m.label) +
					'" loading="lazy" /></span>' +
					'<span class="iftp-gf-method-name">' +
					escapeHtml(m.label) +
					'</span>' +
					'</label>' +
					'<div class="iftp-gf-method-right">' +
					right +
					'</div>' +
					'</div>'
				);
			})
			.join('');

		return '<div class="iftp-gf-methods-list">' + items + '</div>';
	}

	function rebuildDefaultMethodDropdown(gatewayKey, feedData) {
		const $select = $('[name="_gform_setting_default_method"]');
		if (!$select.length || !feedData) {
			return;
		}
		const accounts = feedData.gatewayAccounts[gatewayKey] || {};
		const currentVal = $select.val();

		let html =
			'<option value="">' +
			escapeHtml(feedData.strings.autoMethod) +
			'</option>';
		feedData.methods.forEach(function (m) {

			if (!accounts[m.entity]) {
				return;
			}
			html +=
				'<option value="' +
				escapeHtml(m.entity) +
				'">' +
				escapeHtml(m.label) +
				'</option>';
		});
		$select.html(html);


		if (
			currentVal &&
			$select.find('option[value="' + currentVal + '"]').length
		) {
			$select.val(currentVal);
		}
	}

	$(document).on(
		'change',
		'select[name="_gform_setting_gateway_key"]',
		function () {
			const feedData = window.iftpGfFeedData;
			if (!feedData) {
				return;
			}
			const newKey = $(this).val() || '';
			const $wrapper = $('#iftp-gf-methods-table-wrapper');
			if (!$wrapper.length) {
				return;
			}

			if (!newKey) {
				$wrapper.html(
					'<p class="iftp-gf-no-methods">' +
						escapeHtml(feedData.strings.selectGateway) +
						'</p>'
				);
			} else {
				const listHtml = renderMethodsListHtml(newKey, feedData);
				$wrapper.html(
					listHtml ||
						'<p class="iftp-gf-no-methods">' +
							escapeHtml(feedData.strings.noMethods) +
							'</p>'
				);
			}

			rebuildDefaultMethodDropdown(newKey, feedData);
			syncDefaultMethodDropdown();
		}
	);



	$(document).on('click', '.iftp-gf-activate-method', function (e) {
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
					$btn.closest('.iftp-gf-method-right').html(
						'<em class="iftp-gf-activation-sent">' +
							(strings.activation_sent || 'Request sent.') +
							'</em>'
					);
				} else {
					const msg =
						(res && res.data && res.data.message) ||
						strings.activation_cooldown ||
						'Request already sent.';
					$btn.closest('.iftp-gf-method-right').html(
						'<em class="iftp-gf-activation-cooldown">' +
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



	$(function () {
		syncKeyFieldVisibility();
		syncDefaultMethodDropdown();
	});
})(jQuery);
