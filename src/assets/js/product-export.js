(function () {
	'use strict';

	function bindExportButton(button) {
		if (button.dataset.variantManagerBound === 'true') {
			return;
		}
		button.dataset.variantManagerBound = 'true';

		button.addEventListener('click', function (event) {
			event.preventDefault();

			const productId = button.getAttribute('data-product-id');
			if (! productId) {
				return;
			}

			const exportUrl = button.getAttribute('data-export-url');
			if (! exportUrl) {
				return;
			}

			fetch(exportUrl + '?ids=' + encodeURIComponent(productId) + '&download=true', {
				headers: {
					'X-CSRF-Token': Craft.csrfTokenValue,
					'X-Requested-With': 'XMLHttpRequest',
				},
			}).then(function (response) {
				if (! response.ok) {
					throw new Error(
						Craft.t('variant-manager', 'Export request failed with status {status}', {
							status: response.status,
						})
					);
				}

				return response.blob().then(function (blob) {
					const disposition = response.headers.get('content-disposition') || '';
					// Capture only the filename value so surrounding quotes are not included.
					// Browsers replace literal quote characters in the download filename with underscores.
					const match = disposition.match(/filename="?([^";]+)"?/i);
					const filename = match ? match[1] : 'export.csv';

					const link = document.createElement('a');
					link.href = window.URL.createObjectURL(blob);
					link.download = filename;
					document.body.appendChild(link);
					link.click();
					document.body.removeChild(link);
					window.URL.revokeObjectURL(link.href);
				});
			}).catch(function (error) {
				if (typeof Craft !== 'undefined' && Craft.cp && typeof Craft.cp.displayError === 'function') {
					Craft.cp.displayError(error.message);
				} else {
					console.error(error);
				}
			});
		});
	}

	function init() {
		const buttons = document.querySelectorAll('.js-variant-manager-export');
		for (let index = 0; index < buttons.length; index++) {
			bindExportButton(buttons[index]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
