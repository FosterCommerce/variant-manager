<?php

namespace fostercommerce\variantmanager\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\base\FieldInterface;
use craft\commerce\elements\Variant;
use craft\elements\db\ElementQueryInterface;
use craft\fields\Date;
use craft\helpers\Json;
use fostercommerce\variantmanager\Plugin;

class BulkEditField extends ElementAction
{
	/**
	 * The handle of the custom field to set on every selected variant.
	 */
	public ?string $fieldHandle = null;

	public function getTriggerLabel(): string
	{
		return Craft::t('variant-manager', 'Bulk edit field');
	}

	public function getTriggerHtml(): ?string
	{
		$view = Craft::$app->getView();

		$fieldOptions = [];
		foreach (Plugin::getInstance()->getSettings()->bulkEditableVariantFields as $fieldHandle) {
			$field = $this->resolveField($fieldHandle);
			if ($field === null) {
				continue;
			}

			// Render each field's own input so the value editor matches its type (text box, date picker, ...).
			$fieldOptions[] = [
				'label' => $field->layoutElement?->label() ?? $field->name,
				'value' => $fieldHandle,
				'input' => $field->getInputHtml(null, null),
			];
		}

		if ($fieldOptions === []) {
			return null;
		}

		$type = Json::encode(static::class);
		$js = <<<EOT
(function() {
    new Craft.ElementActionTrigger({
        type: {$type},
        batch: true,
    });

    // Garnish's disclosure menu calls preventDefault() on mousedown, which blocks native <select>
    // popups and input focus; the date picker's calendar also renders outside the menu, so an outside
    // click would close it. Stop those mousedowns from reaching the menu so the inputs stay usable.
    document.addEventListener('mousedown', function(event) {
        if (event.target.closest('[data-vm-bulk-edit-meta]') || event.target.closest('.ui-datepicker')) {
            event.stopPropagation();
        }
    }, true);

    const fieldSelect = document.getElementById('vm-bulk-edit-field');
    fieldSelect.addEventListener('change', function() {
        document.querySelectorAll('[data-vm-bulk-edit-value-for]').forEach(function(container) {
            container.classList.toggle('hidden', container.getAttribute('data-vm-bulk-edit-value-for') !== fieldSelect.value);
        });
    });

    document.addEventListener('click', function(event) {
        if (! event.target.closest('[data-vm-bulk-edit-submit]')) {
            return;
        }

        event.preventDefault();

        const fieldHandle = document.getElementById('vm-bulk-edit-field').value;
        const container = document.querySelector('[data-vm-bulk-edit-value-for="' + fieldHandle + '"]');
        const input = container.querySelector('textarea, select, input:not([type=hidden])');

        Craft.elementIndex.submitAction({$type}, {
            fieldHandle: fieldHandle,
            value: input ? input.value : '',
        });
    });
})();
EOT;

		$view->registerJs($js);

		return $view->renderTemplate(
			'variant-manager/_components/elementactions/BulkEditField/trigger',
			[
				'fieldOptions' => $fieldOptions,
			]
		);
	}

	public function performAction(ElementQueryInterface $query): bool
	{
		if (! Craft::$app->getUser()->checkPermission('variant-manager:manage')) {
			$this->setMessage(Craft::t('variant-manager', 'You do not have permission to bulk edit variants.'));
			return false;
		}

		$allowedFields = Plugin::getInstance()->getSettings()->bulkEditableVariantFields;
		if (! in_array($this->fieldHandle, $allowedFields, true)) {
			$this->setMessage(Craft::t('variant-manager', 'That field cannot be bulk edited.'));
			return false;
		}

		$field = $this->resolveField($this->fieldHandle);
		// Read raw, not as an action property: a Date value arrives as an array, which won't fit ?string.
		$value = Craft::$app->getRequest()->getBodyParam('value');

		// The index menu submits only the date field's visible input, dropping its hidden timezone, so a
		// bare string parses in the wrong zone. Reattach zone + locale. Other composite fields (Money,
		// Time) likewise submit only their first input and are not reassembled here.
		if ($field instanceof Date) {
			$value = [
				'date' => $value,
				'locale' => Craft::$app->getFormattingLocale()->id,
				'timezone' => Craft::$app->getTimeZone(),
			];
		}

		$elementsService = Craft::$app->getElements();
		$failureCount = 0;
		foreach ($query->status(null)->all() as $variant) {
			if (! $variant instanceof Variant) {
				++$failureCount;
				continue;
			}

			$variant->setFieldValue($this->fieldHandle, $value);
			if (! $elementsService->saveElement($variant, false, true, true)) {
				++$failureCount;
			}
		}

		if ($failureCount > 0) {
			$this->setMessage(Craft::t('variant-manager', 'Could not update one or more variants.'));
			return false;
		}

		$this->setMessage(Craft::t('variant-manager', 'Variants updated.'));

		return true;
	}

	/**
	 * Field layouts/elements are stored under the base Variant class, and a layout may override a
	 * field's handle, so resolve against the layout's fields.
	 */
	private function resolveField(string $handle): ?FieldInterface
	{
		foreach (Craft::$app->getFields()->getLayoutsByType(Variant::class) as $fieldLayout) {
			$field = $fieldLayout->getFieldByHandle($handle);
			if ($field !== null) {
				return $field;
			}
		}

		return null;
	}
}
