(function ($) {
	Craft.VariantManager = Craft.VariantManager || {};

	Craft.VariantManager.VariantMaker = Garnish.Base.extend({
		container: null,
		rows: null,
		preview: null,
		productId: null,
		nextRowId: 1,
		pending: null,
		requestId: 0,

		init: function (container) {
			this.container = container;
			this.rows = container.querySelector('[data-vm-rows]');
			this.preview = container.querySelector('[data-vm-preview]');
			this.productId = container.dataset.vmProductId;
			this.nextRowId = this.rows.querySelectorAll('[data-vm-row]').length + 1;

			this.addListener(container, 'click', 'onClick');
			this.addListener(container, 'input', 'scheduleRefresh');
			// Craft's lightswitch is a button and fires its change through jQuery, so bind with jQuery
			$(container).on('change', this.onChange.bind(this));

			this.watchElementSaves();
			this.syncInventoryRows();
			this.refresh();
		},

		/**
		 * A SKU partial or price modifier edited in a slideout changes what the plan would build.
		 */
		watchElementSaves: function () {
			// Craft only opens the channel where the browser supports BroadcastChannel
			if (!Craft.messageReceiver) {
				return;
			}

			Craft.messageReceiver.addEventListener('message', (event) => {
				if (event.data.event === 'saveElement') {
					this.scheduleRefresh();
				}
			});
		},

		onChange: function (event) {
			const row = event.target.closest('[data-vm-row]');

			if (row) {
				this.syncOptionsToAttribute(row);
			}

			this.syncInventoryRows();
			this.scheduleRefresh();
		},

		onClick: function (event) {
			if (event.target.closest('[data-vm-generate]')) {
				this.generate();
				return;
			}

			if (event.target.closest('[data-vm-add-row]')) {
				this.fetchRow(this.nextRowId++);
				return;
			}

			const deleteButton = event.target.closest('[data-vm-row-delete]');

			if (deleteButton) {
				this.deleteRow(deleteButton.closest('[data-vm-row]'));
			}
		},

		/**
		 * Stock and out of stock purchases only mean something while the maker is tracking inventory.
		 */
		syncInventoryRows: function () {
			const tracking = this.isPropertyOn('inventoryTracked');

			['stock', 'allowOutOfStockPurchases'].forEach((propertyName) => {
				this.container
					.querySelector('[data-vm-property="' + propertyName + '"]')
					.classList.toggle('hidden', !tracking);
			});
		},

		isPropertyOn: function (propertyName) {
			const row = this.container.querySelector('[data-vm-property="' + propertyName + '"]');

			return this.switchValue(row, '[data-vm-include]') && this.switchValue(row, '[data-vm-value]');
		},

		/**
		 * Read the hidden input rather than the button's class, since that is what a save receives.
		 */
		switchValue: function (row, cell) {
			return row.querySelector(cell + ' input').value !== '';
		},

		elementSelect: function (id) {
			return $('#' + id).data('elementSelect');
		},

		/**
		 * An option only belongs under one attribute, so a changed attribute invalidates the row's selections.
		 */
		syncOptionsToAttribute: function (row) {
			const attributeId = this.selectedId(row.dataset.vmAttributeSelectId);

			if (attributeId === row.dataset.vmAttributeId) {
				return;
			}

			row.dataset.vmAttributeId = attributeId;

			const optionSelect = this.elementSelect(row.dataset.vmOptionsSelectId);
			optionSelect.settings.criteria = {
				attributeId: attributeId === '' ? -1 : Number(attributeId),
			};

			optionSelect.$elements.each(function () {
				optionSelect.removeElement($(this));
			});

			this.destroyModal(optionSelect);
		},

		selectedId: function (selectId) {
			const chip = document.getElementById(selectId).querySelector('.chip.element');

			return chip ? chip.dataset.id : '';
		},

		/**
		 * A modal is appended to the body, so removing the row it belongs to would leave it behind.
		 */
		destroyModal: function (elementSelect) {
			if (elementSelect.modal) {
				elementSelect.modal.destroy();
				elementSelect.modal = null;
			}
		},

		deleteRow: function (row) {
			[row.dataset.vmAttributeSelectId, row.dataset.vmOptionsSelectId].forEach((selectId) => {
				this.destroyModal(this.elementSelect(selectId));
			});

			row.remove();
			this.scheduleRefresh();
		},

		fetchRow: function (rowId) {
			Craft.sendActionRequest('POST', 'variant-manager/variant-maker/row', {
				data: {
					productId: this.productId,
					rowId: rowId,
				},
			}).then((response) => {
				const wrapper = document.createElement('div');
				wrapper.innerHTML = response.data.html;
				const row = wrapper.firstElementChild;

				this.rows.appendChild(row);

				// Both return promises, and the element selects do not exist until the appended scripts have run
				return Promise.all([
					Craft.appendHeadHtml(response.data.headHtml),
					Craft.appendBodyHtml(response.data.bodyHtml),
				]).then(() => {
					Craft.initUiElements($(row));
					this.scheduleRefresh();
				});
			}).catch((error) => {
				// A row that fails to build leaves a button wired to nothing, with no sign of why
				Craft.cp.displayError(this.errorMessage(error));
			});
		},

		errorMessage: function (error) {
			return error.response && error.response.data ? error.response.data.message : error.message;
		},

		generate: function () {
			const data = new FormData();
			data.append('productId', this.productId);

			Craft.sendActionRequest('POST', 'variant-manager/variant-maker/generate', {
				data: data,
			}).then((response) => {
				Craft.cp.displayNotice(response.data.message);
			}).catch((error) => {
				Craft.cp.displayError(this.errorMessage(error));
			});
		},

		scheduleRefresh: function () {
			window.clearTimeout(this.pending);
			this.pending = window.setTimeout(this.refresh.bind(this), 300);
		},

		/**
		 * The preview reads the builder's own inputs, so it plans from exactly what a save would store.
		 */
		formData: function () {
			const data = new FormData();

			data.append('productId', this.productId);

			this.container.querySelectorAll('[name^="variantMaker["]').forEach((input) => {
				// A real submit skips a disabled input, and the preview has to match what a save receives
				if (!input.disabled) {
					data.append(input.name, input.value);
				}
			});

			return data;
		},

		refresh: function () {
			// A slower earlier response must not overwrite a newer one
			const requestId = ++this.requestId;

			this.preview.setAttribute('aria-busy', 'true');

			Craft.sendActionRequest('POST', 'variant-manager/variant-maker/preview', {
				data: this.formData(),
			}).then((response) => {
				if (requestId === this.requestId) {
					this.preview.innerHTML = response.data.html;
					this.preview.setAttribute('aria-busy', 'false');
				}
			}).catch((error) => {
				if (requestId === this.requestId) {
					this.preview.innerHTML = '';
					this.preview.setAttribute('aria-busy', 'false');
					Craft.cp.displayError(this.errorMessage(error));
				}
			});
		},
	});

	// jQuery ready fires immediately on an already loaded document, where a late DOMContentLoaded listener never runs
	$(function () {
		document.querySelectorAll('[data-vm-variant-maker]').forEach(function (container) {
			new Craft.VariantManager.VariantMaker(container);
		});
	});
})(jQuery);
