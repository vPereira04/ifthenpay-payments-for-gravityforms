(function () {
	'use strict';

	/**
	 * Returns the perceived brightness (0–255) of a CSS colour string.
	 * Returns -1 for unparsable values or fully-transparent colours.
	 */
	function brightness(cssColor) {
		var m = cssColor.match(/[\d.]+/g);
		if (!m || m.length < 3) return -1;
		if (m.length >= 4 && parseFloat(m[3]) < 0.05) return -1;
		return 0.299 * +m[0] + 0.587 * +m[1] + 0.114 * +m[2];
	}

	/**
	 * Returns true when the given field container is in dark mode.
	 *
	 * Reads the computed background colour of .iftp-gf-box, which resolves
	 * GF's --gf-ctrl-bg-color CSS variable. This reflects GF's actual rendered
	 * colour scheme regardless of *why* it went dark (OS preference, body class,
	 * theme toggle, etc.) without reading prefers-color-scheme directly.
	 *
	 * Falls back to the body background when the box isn't found.
	 */
	function fieldIsDark(field) {
		var box = field.querySelector('.iftp-gf-box');
		if (box) {
			var b = brightness(getComputedStyle(box).backgroundColor);
			if (b !== -1) return b < 128;
		}
		var bb = brightness(getComputedStyle(document.body).backgroundColor);
		if (bb > 0) return bb < 128;
		return false;
	}

	function applyTheme() {
		document.querySelectorAll('.iftp-gf-field').forEach(function (field) {
			var dark = fieldIsDark(field);

			field.classList.toggle('iftp-gf-field--dark', dark);

			field.querySelectorAll('.iftp-gf-box__method img[data-src-dark]').forEach(function (img) {
				if (!img.hasAttribute('data-src-light')) {
					img.setAttribute('data-src-light', img.getAttribute('src'));
				}
				img.src = dark
					? img.getAttribute('data-src-dark')
					: img.getAttribute('data-src-light');
			});
		});
	}


	var rafPending = false;
	function scheduleApply() {
		if (!rafPending) {
			rafPending = true;
			requestAnimationFrame(function () {
				rafPending = false;
				applyTheme();
			});
		}
	}

	function init() {
		applyTheme();

		if (window.MutationObserver) {

			var attrWatch = { attributes: true, attributeFilter: ['class', 'style', 'data-theme', 'data-color-scheme'] };
			new MutationObserver(scheduleApply).observe(document.documentElement, attrWatch);
			new MutationObserver(scheduleApply).observe(document.body, attrWatch);


			new MutationObserver(scheduleApply).observe(document.body, {
				childList: true,
				subtree: true,
			});
		}


		if (window.matchMedia) {
			window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', scheduleApply);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
