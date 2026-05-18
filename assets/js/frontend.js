(function () {
	'use strict';

	var strings = window.ifthenpay_gf_frontend_strings || {};
	var ajaxUrl = strings.ajax_url || (window.ajaxurl || '');

	// -------------------------------------------------------------------------
	// IftpPblField — one instance per .iftp-pbl-gf-field on the page
	// -------------------------------------------------------------------------

	function IftpPblField(root) {
		this.root         = root;
		this.button       = root.querySelector('.iftp-pbl-pay-now-button');
		this.statusBox    = root.querySelector('.iftp-pbl-status');
		this.paidInput    = root.querySelector('.iftp-pbl-paid-input');
		this.payIdInput   = root.querySelector('.iftp-pbl-payment-id-input');
		this.sessionInput = root.querySelector('.iftp-pbl-session-input');
		this.nonceInput   = root.querySelector('.iftp-pbl-nonce-input');
		this.gatewayKey   = root.getAttribute('data-iftp-gateway-key') || '';
		this.formId       = root.getAttribute('data-form-id') || '';
		this.amountSel    = root.getAttribute('data-iftp-amount-selector') || '';
		this.popup        = null;
		this.pollTimer    = null;

		if (this.button) {
			this.button.addEventListener('click', this.onPayClick.bind(this));
		}

		var form = root.closest('form');
		if (form) {
			form.addEventListener('submit', this.onFormSubmit.bind(this));
		}
	}

	IftpPblField.prototype.getAmount = function () {
		if (!this.amountSel) return null;
		var el = document.querySelector(this.amountSel);
		if (!el) return null;
		// GF total fields may render "€ 1.499,00" — strip non-numeric except separators
		var raw = (el.value || el.textContent || '').replace(/[^\d,.\-]/g, '');
		// Handle European comma decimals: 1.499,00 → 1499.00
		if (/\d{1,3}(\.\d{3})*(,\d+)?$/.test(raw)) {
			raw = raw.replace(/\./g, '').replace(',', '.');
		}
		var num = parseFloat(raw);
		return isNaN(num) ? null : num;
	};

	IftpPblField.prototype.setStatus = function (kind, message) {
		if (!this.statusBox) return;
		this.statusBox.className = 'iftp-pbl-status is-visible is-' + kind;
		this.statusBox.textContent = message;
	};

	IftpPblField.prototype.clearStatus = function () {
		if (!this.statusBox) return;
		this.statusBox.className = 'iftp-pbl-status';
		this.statusBox.textContent = '';
	};

	IftpPblField.prototype.setLoading = function (on) {
		if (!this.button) return;
		this.button.classList.toggle('is-loading', !!on);
		this.button.disabled = !!on;
	};

	IftpPblField.prototype.markPaid = function (paymentId) {
		this.paidInput.value  = '1';
		this.payIdInput.value = paymentId || '';
		this.button.classList.remove('is-loading');
		this.button.classList.add('is-paid');
		var label = this.button.querySelector('.iftp-pbl-pay-now-label');
		if (label) label.textContent = strings.paid_label || 'Paid ✓';
		this.button.disabled = false;
		this.setStatus('success', strings.payment_success || 'Payment received. You can now submit the form.');
	};

	// -------------------------------------------------------------------------
	// Pay Now click
	// -------------------------------------------------------------------------

	IftpPblField.prototype.onPayClick = function (e) {
		e.preventDefault();
		this.clearStatus();

		var amount = this.getAmount();

		if (amount !== null && amount <= 0) {
			this.setStatus('error', strings.amount_zero || 'Please select a product before paying.');
			return;
		}

		if (!this.gatewayKey) {
			this.setStatus('error', strings.no_gateway || 'Payment gateway is not configured.');
			return;
		}

		this.setLoading(true);

		var self = this;
		fetch(ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    new URLSearchParams({
				action:      'iftp_gf_create_payment',
				nonce:       this.nonceInput ? this.nonceInput.value : '',
				form_id:     this.formId,
				gateway_key: this.gatewayKey,
				amount:      amount !== null ? String(amount) : '0',
				currency:    'EUR'
			})
		})
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (!res || !res.success || !res.data || !res.data.url) {
				throw new Error((res && res.data && res.data.message) || (strings.generic_error || 'Could not create payment.'));
			}
			self.sessionInput.value = res.data.sessionToken || '';
			self.payIdInput.value   = res.data.transactionId || '';
			self.openPopup(res.data.url, res.data.sessionToken);
		})
		.catch(function (err) {
			self.setLoading(false);
			self.setStatus('error', (err && err.message) || (strings.generic_error || 'Payment failed. Please try again.'));
		});
	};

	// -------------------------------------------------------------------------
	// Popup management
	// -------------------------------------------------------------------------

	IftpPblField.prototype.openPopup = function (redirectUrl, sessionToken) {
		var w    = 480;
		var h    = 640;
		var left = Math.round((screen.width  - w) / 2);
		var top  = Math.round((screen.height - h) / 2);

		this.popup = window.open(
			redirectUrl,
			'iftpPaymentWindow',
			'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top
				+ ',resizable=yes,scrollbars=yes,status=no,toolbar=no,menubar=no,location=no'
		);

		if (!this.popup) {
			this.setLoading(false);
			this.setStatus('error', strings.popup_blocked || 'Could not open the payment window. Please allow pop-ups for this site and try again.');
			return;
		}

		this.setStatus('info', strings.waiting || 'Waiting for payment confirmation…');
		this.watchPopup(sessionToken);
	};

	IftpPblField.prototype.watchPopup = function (sessionToken) {
		var self = this;

		function onMessage(ev) {
			if (!ev.data || ev.data.source !== 'ifthenpay') return;
			if (sessionToken && ev.data.sessionToken !== sessionToken) return;

			window.removeEventListener('message', onMessage);
			clearInterval(self.pollTimer);
			clearInterval(closedWatcher);

			if (self.popup && !self.popup.closed) self.popup.close();
			self.setLoading(false);

			if (ev.data.status === 'paid') {
				self.markPaid(ev.data.transactionId || self.payIdInput.value);
			} else {
				self.setStatus('error', strings.payment_cancelled || 'Payment was not completed.');
			}
		}

		window.addEventListener('message', onMessage);

		// Fallback: poll backend every 3 s
		self.pollTimer = setInterval(function () {
			if (!sessionToken) return;
			fetch(ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams({
					action:        'iftp_gf_check_payment_status',
					session_token: sessionToken
				})
			})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) return;
				var status = res.data && res.data.status;
				if (status === 'paid') {
					clearInterval(self.pollTimer);
					clearInterval(closedWatcher);
					window.removeEventListener('message', onMessage);
					if (self.popup && !self.popup.closed) self.popup.close();
					self.setLoading(false);
					self.markPaid(res.data.transactionId || self.payIdInput.value);
				} else if (status === 'failed' || status === 'cancelled' || status === 'error') {
					clearInterval(self.pollTimer);
					clearInterval(closedWatcher);
					window.removeEventListener('message', onMessage);
					if (self.popup && !self.popup.closed) self.popup.close();
					self.setLoading(false);
					self.setStatus('error', strings.payment_cancelled || 'Payment was not completed.');
				}
			})
			.catch(function () {});
		}, 3000);

		// Detect manual popup close
		var closedWatcher = setInterval(function () {
			if (self.popup && self.popup.closed) {
				clearInterval(closedWatcher);
				clearInterval(self.pollTimer);
				window.removeEventListener('message', onMessage);
				if (self.paidInput.value !== '1') {
					self.setLoading(false);
					self.setStatus('error', strings.popup_closed || 'Payment window was closed before completion.');
				}
			}
		}, 600);
	};

	// -------------------------------------------------------------------------
	// Block GF form submit until payment is complete
	// -------------------------------------------------------------------------

	IftpPblField.prototype.onFormSubmit = function (e) {
		if (this.paidInput && this.paidInput.value !== '1') {
			e.preventDefault();
			e.stopImmediatePropagation();
			this.setStatus('error', strings.pay_first || 'Please complete the payment before submitting the form.');
			if (this.button) this.button.focus();
		}
	};

	// -------------------------------------------------------------------------
	// Init on DOM ready; re-init after GF AJAX page turns
	// -------------------------------------------------------------------------

	function initAll() {
		var nodes = document.querySelectorAll('.iftp-pbl-gf-field');
		Array.prototype.forEach.call(nodes, function (n) {
			if (n.__iftpPblInited) return;
			n.__iftpPblInited = true;
			new IftpPblField(n);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}

	if (window.jQuery) {
		jQuery(document).on('gform_post_render', initAll);
	}
})();
