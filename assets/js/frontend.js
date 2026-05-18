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
		this.overlay      = null;
		this.pollTimer    = null;
		this._onMessage   = null;
		this.activeSession = '';

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
		if (this.paidInput)  this.paidInput.value  = '1';
		if (this.payIdInput) this.payIdInput.value = paymentId || '';
		if (this.button) {
			this.button.classList.remove('is-loading');
			this.button.classList.add('is-paid');
			var label = this.button.querySelector('.iftp-pbl-pay-now-label');
			if (label) label.textContent = strings.paid_label || 'Paid ✓';
			this.button.disabled = false;
		}
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
			self.activeSession = res.data.sessionToken || '';
			if (self.sessionInput) self.sessionInput.value = self.activeSession;
			if (self.payIdInput)   self.payIdInput.value   = res.data.transactionId || '';
			self.openModal(res.data.url, self.activeSession);
		})
		.catch(function (err) {
			self.setLoading(false);
			self.setStatus('error', (err && err.message) || (strings.generic_error || 'Payment failed. Please try again.'));
		});
	};

	// -------------------------------------------------------------------------
	// Modal (full-screen iframe overlay) — avoids popup blockers
	// -------------------------------------------------------------------------

	IftpPblField.prototype.openModal = function (paymentUrl, sessionToken) {
		this.closeModal();

		var self = this;

		// Build overlay
		var overlay = document.createElement('div');
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.style.cssText = [
			'position:fixed;inset:0;',
			'background:rgba(15,23,42,.55);',
			'display:flex;flex-direction:column;',
			'z-index:2147483647;',
		].join('');

		var container = document.createElement('div');
		container.style.cssText = [
			'position:relative;width:100vw;height:100vh;',
			'background:#fff;overflow:hidden;',
			'display:flex;flex-direction:column;',
		].join('');

		// Header bar
		var header = document.createElement('div');
		header.style.cssText = [
			'display:flex;align-items:center;gap:14px;',
			'padding:16px 24px;',
			'background:#fef3c7;border-bottom:1px solid #fcd34d;',
			'flex-shrink:0;',
		].join('');
		header.innerHTML = [
			'<div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.6);',
			'display:flex;align-items:center;justify-content:center;flex-shrink:0;">',
			'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#92400e"',
			' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">',
			'<rect x="3" y="11" width="18" height="11" rx="2"/>',
			'<path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>',
			'<div style="flex:1;min-width:0;">',
			'<p style="margin:0;font-size:20px;font-weight:600;color:#92400e;line-height:1.4;">',
			(strings.modal_title || 'Payment in progress') + '</p>',
			'<p style="margin:2px 0 0;font-size:15px;font-weight:500;color:#92400e;opacity:.85;line-height:1.4;">',
			(strings.modal_subtitle || 'Closing this window will cancel the transaction.') + '</p>',
			'</div>',
			'<button type="button" id="iftp-gf-modal-close"',
			' style="width:36px;height:36px;border:none;border-radius:50%;',
			'background:rgba(255,255,255,.5);cursor:pointer;display:flex;',
			'align-items:center;justify-content:center;color:#92400e;flex-shrink:0;"',
			' aria-label="Close payment">',
			'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"',
			' stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">',
			'<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
			'</button>',
		].join('');

		header.querySelector('#iftp-gf-modal-close').addEventListener('click', function () {
			self.closeModal();
			self.setLoading(false);
			self.setStatus('error', strings.payment_cancelled || 'Payment was not completed.');
		});

		// Payment iframe
		var iframe = document.createElement('iframe');
		iframe.src = paymentUrl;
		iframe.allow = 'payment *';
		iframe.setAttribute('frameborder', '0');
		iframe.style.cssText = 'flex:1;border:none;width:100%;';

		container.appendChild(header);
		container.appendChild(iframe);
		overlay.appendChild(container);
		document.body.appendChild(overlay);
		this.overlay = overlay;

		// postMessage listener — the return page sends { source:'ifthenpay', status, sessionToken, transactionId }
		function onMessage(ev) {
			if (!ev.data || ev.data.source !== 'ifthenpay') return;
			if (sessionToken && ev.data.sessionToken !== sessionToken) return;

			window.removeEventListener('message', onMessage);
			self._onMessage = null;
			self.clearPoll();
			self.closeModal();
			self.setLoading(false);

			if (ev.data.status === 'paid') {
				self.markPaid(ev.data.transactionId || (self.payIdInput ? self.payIdInput.value : ''));
				self.autoSubmitForm();
			} else {
				self.setStatus('error', strings.payment_cancelled || 'Payment was not completed.');
			}
		}

		window.addEventListener('message', onMessage);
		this._onMessage = onMessage;

		// Backend polling fallback (every 3 s)
		this.startPoll(sessionToken);
	};

	IftpPblField.prototype.closeModal = function () {
		if (this.overlay && this.overlay.parentNode) {
			this.overlay.parentNode.removeChild(this.overlay);
		}
		this.overlay = null;

		if (this._onMessage) {
			window.removeEventListener('message', this._onMessage);
			this._onMessage = null;
		}

		this.clearPoll();
	};

	// -------------------------------------------------------------------------
	// Backend polling fallback
	// -------------------------------------------------------------------------

	IftpPblField.prototype.startPoll = function (sessionToken) {
		if (!sessionToken) return;
		var self = this;
		this.clearPoll();

		this.pollTimer = setInterval(function () {
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
					self.clearPoll();
					if (self._onMessage) {
						window.removeEventListener('message', self._onMessage);
						self._onMessage = null;
					}
					self.closeModal();
					self.setLoading(false);
					self.markPaid(res.data.transactionId || (self.payIdInput ? self.payIdInput.value : ''));
					self.autoSubmitForm();
				} else if (status === 'failed' || status === 'cancelled' || status === 'error') {
					self.clearPoll();
					if (self._onMessage) {
						window.removeEventListener('message', self._onMessage);
						self._onMessage = null;
					}
					self.closeModal();
					self.setLoading(false);
					self.setStatus('error', strings.payment_cancelled || 'Payment was not completed.');
				}
			})
			.catch(function () {});
		}, 3000);
	};

	IftpPblField.prototype.clearPoll = function () {
		if (this.pollTimer) {
			clearInterval(this.pollTimer);
			this.pollTimer = null;
		}
	};

	// -------------------------------------------------------------------------
	// Auto-submit the GF form after successful payment
	// -------------------------------------------------------------------------

	IftpPblField.prototype.autoSubmitForm = function () {
		var form = this.root.closest('form');
		if (!form) return;
		// Click the submit button so GF's own JS handles AJAX forms correctly
		setTimeout(function () {
			var btn = form.querySelector('input[type="submit"], button[type="submit"]');
			if (btn) {
				btn.click();
			} else if (typeof form.requestSubmit === 'function') {
				form.requestSubmit();
			} else {
				form.submit();
			}
		}, 300);
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
