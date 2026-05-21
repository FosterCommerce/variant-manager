(function () {
	'use strict';

	function bindExportButton(button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();

			const productId = button.dataset.productId;
			const exportUrl = button.dataset.exportUrl;
			const params = new URLSearchParams({
				ids: productId,
				download: 'true',
			});

			fetch(exportUrl + '?' + params.toString(), {
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
				Craft.cp.displayError(error.message);
			});
		});
	}

	function init() {
		const buttons = document.querySelectorAll('.js-variant-manager-export');
		buttons.forEach(bindExportButton);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
