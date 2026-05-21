(function () {
	'use strict';

	function applyDarkLogos() {
		const imgs = document.querySelectorAll(
			'.iftp-gf-method-item img[data-src-dark]'
		);
		if (!imgs.length) {
			return;
		}


		const prefersDark =
			window.matchMedia &&
			window.matchMedia('(prefers-color-scheme: dark)').matches;


		let bodyTextIsBright = false;
		if (!prefersDark) {
			const c = window.getComputedStyle(document.body).color;
			const m = c.match(/\d+/g);
			if (m && m.length >= 3) {
				bodyTextIsBright =
					0.299 * +m[0] + 0.587 * +m[1] + 0.114 * +m[2] > 127;
			}
		}

		if (prefersDark || bodyTextIsBright) {
			imgs.forEach(function (img) {
				img.src = img.getAttribute('data-src-dark');
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', applyDarkLogos);
	} else {
		applyDarkLogos();
	}
})();
