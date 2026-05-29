<?php

namespace fostercommerce\variantmanager\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\commerce\elements\Variant;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Json;
use fostercommerce\variantmanager\Plugin;

class BulkEditField extends ElementAction
{
	/**
	 * The handle of the custom field to set on every selected variant.
	 */
	public ?string $fieldHandle = null;

	/**
	 * The value to write to the field.
	 */
	public ?string $value = null;

	public function getTriggerLabel(): string
	{
		return Craft::t('variant-manager', 'Bulk edit field');
	}

	public function getTriggerHtml(): ?string
	{
		$fieldOptions = [];
		foreach (Plugin::getInstance()->getSettings()->bulkEditableVariantFields as $fieldHandle) {
			$field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
			$fieldOptions[] = [
				'label' => $field?->name ?? $fieldHandle,
				'value' => $fieldHandle,
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

    document.addEventListener('click', function(event) {
        var submit = event.target.closest('[data-vm-bulk-edit-submit]');
        if (! submit) {
            return;
        }

        event.preventDefault();

        var fieldHandle = document.getElementById('vm-bulk-edit-field').value;
        var value = document.getElementById('vm-bulk-edit-value').value;

        Craft.elementIndex.submitAction({$type}, {
            fieldHandle: fieldHandle,
            value: value,
        });
    });
})();
EOT;

		Craft::$app->getView()->registerJs($js);

		return Craft::$app->getView()->renderTemplate(
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

		$elementsService = Craft::$app->getElements();
		$failureCount = 0;
		foreach ($query->status(null)->all() as $variant) {
			if (! $variant instanceof Variant) {
				++$failureCount;
				continue;
			}

			$variant->setFieldValue($this->fieldHandle, $this->value);
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
}
