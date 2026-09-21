(function ($) {
	Craft.VariantManager = Craft.VariantManager || {};

	Craft.VariantManager.VariantMaker = Garnish.Base.extend({
		container: null,
		rows: null,
		preview: null,
		tokens: null,
		productId: null,
		nextRowId: 1,
		pending: null,
		requestId: 0,

		init: function (container) {
			this.container = container;
			this.rows = container.querySelector('[data-vm-rows]');
			this.preview = container.querySelector('[data-vm-preview]');
			this.tokens = container.querySelector('[data-vm-tokens]');
			this.productId = container.dataset.vmProductId;
			this.nextRowId = this.rows.querySelectorAll('[data-vm-row]').length + 1;

			this.addListener(container, 'click', 'onClick');
			this.addListener(container, 'input', 'scheduleRefresh');
			// Craft's lightswitch is a button and fires its change through jQuery, so bind with jQuery
			$(container).on('change', this.onChange.bind(this));

			this.watchElementSaves();
			this.watchUnsavedChanges();
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
				this.fetchRow(this.nextRowId);
				return;
			}

			if (event.target.closest('[data-vm-autofill-rows]')) {
				this.autofillRows();
				return;
			}

			const deleteButton = event.target.closest('[data-vm-row-delete]');

			if (deleteButton) {
				this.deleteRow(deleteButton.closest('[data-vm-row]'));
			}
		},

		/**
		 * Generating reads the saved settings, not the rows on screen.
		 */
		watchUnsavedChanges: function () {
			// Garnish matches the class, so this needs no reference to an editor the tab cannot reach
			Garnish.on(Craft.ElementEditor, 'createProvisionalDraft', this.disableGenerate.bind(this));
		},

		disableGenerate: function () {
			const generate = this.container.querySelector('[data-vm-generate]');
			generate.classList.add('disabled');
			generate.disabled = true;

			this.container.querySelector('[data-vm-generate-instructions]').classList.add('hidden');
			this.container.querySelector('[data-vm-generate-save-first]').classList.remove('hidden');
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

		/**
		 * The element selects do not exist until the appended scripts have run, and both appends return promises.
		 */
		appendRows: function (response) {
			const wrapper = document.createElement('div');
			wrapper.innerHTML = response.data.html;
			const rows = Array.from(wrapper.children);

			rows.forEach((row) => this.rows.appendChild(row));
			this.nextRowId += rows.length;

			return Promise.all([
				Craft.appendHeadHtml(response.data.headHtml),
				Craft.appendBodyHtml(response.data.bodyHtml),
			]).then(() => {
				rows.forEach((row) => Craft.initUiElements($(row)));
				this.scheduleRefresh();
			});
		},

		fetchRow: function (rowId) {
			Craft.sendActionRequest('POST', 'variant-manager/variant-maker/row', {
				data: {
					productId: this.productId,
					rowId: rowId,
				},
			}).then((response) => this.appendRows(response)).catch((error) => {
				// A row that fails to build leaves a button wired to nothing, with no sign of why
				Craft.cp.displayError(error?.response?.data?.message);
			});
		},

		autofillRows: function () {
			const autofill = this.container.querySelector('[data-vm-autofill-rows]');

			if (autofill.classList.contains('loading')) {
				return;
			}

			autofill.classList.add('loading');

			Craft.sendActionRequest('POST', 'variant-manager/variant-maker/autofill-rows', {
				data: {
					productId: this.productId,
					nextRowId: this.nextRowId,
					attributeIds: this.filledAttributeIds(),
				},
			}).then((response) => {
				if (response.data.html === '') {
					Craft.cp.displayNotice(response.data.message);
					return;
				}

				return this.appendRows(response);
			}).catch((error) => {
				Craft.cp.displayError(error?.response?.data?.message);
			}).finally(() => {
				autofill.classList.remove('loading');
			});
		},

		/**
		 * The dataset holds the attribute a row has now, not the one it rendered with.
		 */
		filledAttributeIds: function () {
			return Array.from(this.rows.querySelectorAll('[data-vm-row]'))
				.map((row) => row.dataset.vmAttributeId)
				.filter((attributeId) => attributeId !== '');
		},

		showDefaultFormats: function (defaults) {
			['title', 'sku'].forEach((propertyName) => {
				const input = this.container.querySelector('[data-vm-property="' + propertyName + '"] [data-vm-value] input');

				if (input) {
					input.placeholder = (defaults && defaults[propertyName]) || '';
				}
			});
		},

		generate: function () {
			const data = new FormData();
			data.append('productId', this.productId);

			Craft.sendActionRequest('POST', 'variant-manager/variant-maker/generate', {
				data: data,
			}).then((response) => {
				Craft.cp.displayNotice(response.data.message);
			}).catch((error) => {
				Craft.cp.displayError(error?.response?.data?.message);
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
					this.tokens.textContent = response.data.tokens || '';
					this.showDefaultFormats(response.data.placeholders);
					this.preview.setAttribute('aria-busy', 'false');
				}
			}).catch((error) => {
				if (requestId === this.requestId) {
					this.preview.innerHTML = '';
					this.preview.setAttribute('aria-busy', 'false');
					Craft.cp.displayError(error?.response?.data?.message);
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
