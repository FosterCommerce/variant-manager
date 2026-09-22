(function ($) {
	Craft.VariantManager = Craft.VariantManager || {};

	Craft.VariantManager.VariantAttributeIndex = Craft.BaseElementIndex.extend({
		$newElementBtn: null,

		afterInit: function () {
			const parentAttributeId = this.parentAttributeId();

			if (parentAttributeId !== null) {
				this.$newElementBtn = Craft.ui
					.createButton({
						label: parentAttributeId === 0
							? Craft.t('variant-manager', 'variantMaker.newAttribute')
							: Craft.t('variant-manager', 'variantMaker.newOption'),
						spinner: true,
					})
					.addClass('submit add icon');

				this.addListener(this.$newElementBtn, 'activate', 'onNewElementBtn');
				this.addButton(this.newElementBtnGroup(parentAttributeId));
			}

			this.base();
		},

		/**
		 * The attribute to create under, or null where no button belongs.
		 */
		parentAttributeId: function () {
			if (!Craft.VariantManager.canManageAttributes) {
				return null;
			}

			if (this.settings.context === 'index') {
				return 0;
			}

			// An options picker queries for -1 until its row has an attribute
			const attributeId = Number(this.settings.criteria?.attributeId ?? -1);

			return attributeId < 0 ? null : attributeId;
		},

		/**
		 * The index holds attributes and options, so one create button can't cover both.
		 */
		newElementBtnGroup: function (parentAttributeId) {
			if (parentAttributeId !== 0) {
				return this.$newElementBtn;
			}

			const menuId = 'vm-new-record-menu-' + Craft.randomString(10);
			const $group = $('<div class="btngroup"/>');
			this.$newElementBtn.appendTo($group);

			const $menuBtn = $('<button/>', {
				type: 'button',
				class: 'btn submit menubtn btngroup-btn-last',
				'aria-controls': menuId,
				'data-disclosure-trigger': '',
				'aria-label': Craft.t('variant-manager', 'attributes.newRecordChoose'),
			}).appendTo($group);

			$('<div/>', {
				id: menuId,
				class: 'menu menu--disclosure',
			}).appendTo($group);

			$menuBtn.disclosureMenu();
			const menu = $menuBtn.data('disclosureMenu');

			menu.addItem({
				label: Craft.t('variant-manager', 'variantMaker.newAttribute'),
				onActivate: () => {
					this.createVariantAttribute(0);
				},
			});

			menu.addItem({
				label: Craft.t('variant-manager', 'variantMaker.newOption'),
				onActivate: () => {
					this.onNewOption();
				},
			});

			return $group;
		},

		onNewOption: function () {
			const firstAttributeId = this.firstAttributeId();

			if (firstAttributeId === null) {
				Craft.cp.displayError(Craft.t('variant-manager', 'attributes.noAttributeForOption'));
				return;
			}

			this.createVariantAttribute(firstAttributeId);
		},

		/**
		 * The attribute a new option opens under, until its sidebar select names a different attribute.
		 *
		 * Read data-level off the element row, which the index sets only while the listing is sorted by structure.
		 */
		firstAttributeId: function () {
			const attributeId = this.view.$elementContainer
				.find('.element[data-level="1"]')
				.first()
				.data('id');

			return attributeId === undefined ? null : Number(attributeId);
		},

		onNewElementBtn: function () {
			this.createVariantAttribute(this.parentAttributeId());
		},

		createVariantAttribute: function (attributeId) {
			// A second click before the slideout opens would create a second draft
			if (this.$newElementBtn.hasClass('loading')) {
				return;
			}

			this.$newElementBtn.addClass('loading');

			return Craft.sendActionRequest('POST', 'elements/create', {
				data: {
					elementType: this.elementType,
					attributeId: attributeId,
				},
			}).then(({ data }) => {
				const slideout = Craft.createElementEditor(this.elementType, {
					elementId: data.element.id,
					draftId: data.element.draftId,
					params: {
						fresh: 1,
						updateSearchIndexImmediately: 1,
					},
				});

				// Clear the search, since a term typed before the save excludes the new element
				slideout.on('submit', () => {
					this.clearSearch(false);
					this.selectElementAfterUpdate(data.element.id);
					this.updateElements();
				});
			}).catch((error) => {
				// Report here, since the slideout that shows field errors never opened
				Craft.cp.displayError(error?.response?.data?.message);
			}).finally(() => {
				this.$newElementBtn.removeClass('loading');
			});
		},
	});

	Craft.registerElementIndexClass(
		'fostercommerce\\variantmanager\\elements\\VariantAttribute',
		Craft.VariantManager.VariantAttributeIndex
	);
})(jQuery);
