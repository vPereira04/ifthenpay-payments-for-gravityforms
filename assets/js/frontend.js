(function () {
	'use strict';

	/**
	 * Returns the perceived brightness (0–255) of a CSS colour string.
	 * Returns -1 if the string can't be parsed.
	 */
	function brightness(cssColor) {
		const m = cssColor.match(/\d+/g);
		if (!m || m.length < 3) {
			return -1;
		}
		return 0.299 * +m[0] + 0.587 * +m[1] + 0.114 * +m[2];
	}

	/**
	 * Picks the best reference element for colour detection.
	 *
	 * Priority:
	 *  1. .iftp-gf-box__methods-title  — uses --gf-ctrl-label-color-quaternary
	 *  2. .iftp-gf-box__header-subtitle — uses --gf-color-out-ctrl-dark-darker
	 *  3. .iftp-gf-box__header-title    — uses --gf-ctrl-label-color-quaternary
	 *  4. document.body                 — last-resort fallback
	 *
	 * Checking one of our own text elements means the detection is scoped to
	 * the GF theme CSS variables rather than whatever the page body happens to
	 * use. getComputedStyle resolves the variable to a real rgb() value, so
	 * brightness() works correctly even without knowing the variable name.
	 */
	function refElement(field) {
		return (
			field.querySelector('.iftp-gf-box__methods-title') ||
			field.querySelector('.iftp-gf-box__header-subtitle') ||
			field.querySelector('.iftp-gf-box__header-title') ||
			document.body
		);
	}

	function applyLogos() {
		const imgs = document.querySelectorAll(
			'.iftp-gf-box__method img[data-src-dark]'
		);
		if (!imgs.length) {
			return;
		}


		imgs.forEach(function (img) {
			if (!img.hasAttribute('data-src-light')) {
				img.setAttribute('data-src-light', img.getAttribute('src'));
			}
		});

		imgs.forEach(function (img) {
			const field =
				img.closest('.iftp-gf-field') || document.body;

			const ref = refElement(field);
			const b = brightness(window.getComputedStyle(ref).color);

			const useDark = b > 127;


			const source =
				img.closest('picture') &&
				img.closest('picture').querySelector('source');
			if (source) {
				source.setAttribute('media', 'not all');
			}

			img.src = useDark
				? img.getAttribute('data-src-dark')
				: img.getAttribute('data-src-light');
		});
	}

	function init() {
		applyLogos();


		if (window.MutationObserver) {
			new MutationObserver(applyLogos).observe(document.body, {
				attributes: true,
				attributeFilter: ['style', 'class'],
			});
		}


		if (window.matchMedia) {
			window
				.matchMedia('(prefers-color-scheme: dark)')
				.addEventListener('change', applyLogos);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
