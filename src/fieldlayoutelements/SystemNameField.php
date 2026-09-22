<?php

namespace fostercommerce\variantmanager\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TextField;

class SystemNameField extends TextField
{
	public bool $mandatory = true;

	public string $attribute = 'name';

	public ?int $maxlength = 255;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct($config = [])
	{
		// Let a stored layout pin none of these three, because this class owns them
		unset($config['mandatory'], $config['attribute'], $config['maxlength']);

		parent::__construct($config);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function fields(): array
	{
		$fields = parent::fields();
		unset($fields['mandatory'], $fields['attribute'], $fields['maxlength']);

		return $fields;
	}

	public function formHtml(?ElementInterface $element = null, bool $static = false): ?string
	{
		if ($this->isUnpublishedDraft($element) && ! $static) {
			$view = Craft::$app->getView();

			$view->registerJsWithVars(fn (string $titleId, string $nameId): string => <<<JS
(() => {
  const nameInput = $('#' + {$nameId});
  if (!nameInput.val().length) {
    new Craft.BaseInputGenerator($('#' + {$titleId}), nameInput);
  }
})();
JS, [
				$view->namespaceInputId('title'),
				$view->namespaceInputId($this->id()),
			]);
		}

		return parent::formHtml($element, $static);
	}

	protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
	{
		// Offer the field only before the record exists, because variants match on the system name
		// VariantAttribute::systemNameFieldHtml() renders the value read-only after that
		return $this->isUnpublishedDraft($element) ? parent::inputHtml($element, $static) : null;
	}

	protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
	{
		return Craft::t('variant-manager', 'attributes.name');
	}

	protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
	{
		return $this->isUnpublishedDraft($element)
			? Craft::t('variant-manager', 'attributes.nameInstructions')
			: null;
	}

	private function isUnpublishedDraft(?ElementInterface $element): bool
	{
		return $element?->getIsUnpublishedDraft() === true;
	}
}
