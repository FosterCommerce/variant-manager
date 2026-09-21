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

				this.addListener(this.$newElementBtn, 'activate', 'createVariantAttribute');
				this.addButton(this.$newElementBtn);
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

		createVariantAttribute: function () {
			if (this.$newElementBtn.hasClass('loading')) {
				return;
			}

			this.$newElementBtn.addClass('loading');

			Craft.sendActionRequest('POST', 'elements/create', {
				data: {
					elementType: this.elementType,
					attributeId: this.parentAttributeId(),
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
