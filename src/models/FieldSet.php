<?php

namespace fostercommerce\variantmanager\models;

use Craft;
use craft\base\Model;
use craft\models\FieldLayout;
use craft\validators\HandleValidator;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\Plugin;

/**
 * A pair of field layouts, one for an attribute and one for its options, that any number of attributes share.
 */
class FieldSet extends Model
{
	public ?string $uid = null;

	public ?string $name = null;

	public ?string $handle = null;

	private ?FieldLayout $fieldLayout = null;

	private ?FieldLayout $optionFieldLayout = null;

	public function getFieldLayout(): FieldLayout
	{
		return $this->fieldLayout ??= $this->emptyLayout('attribute');
	}

	public function setFieldLayout(FieldLayout $fieldLayout): void
	{
		$this->fieldLayout = $fieldLayout;
	}

	public function getOptionFieldLayout(): FieldLayout
	{
		return $this->optionFieldLayout ??= $this->emptyLayout('option');
	}

	public function setOptionFieldLayout(FieldLayout $optionFieldLayout): void
	{
		$this->optionFieldLayout = $optionFieldLayout;
	}

	/**
	 * Check uniqueness here, since UniqueValidator needs a table and field sets are project config.
	 */
	public function validateHandleUnique(): void
	{
		foreach (Plugin::getInstance()->getFieldSets()->getAllFieldSets() as $fieldSet) {
			if ($fieldSet->uid !== $this->uid && $fieldSet->handle === $this->handle) {
				$this->addError('handle', Craft::t('variant-manager', 'fieldSets.handleTaken'));
				return;
			}
		}
	}

	public function validateFieldLayout(): void
	{
		if (! $this->getFieldLayout()->validate()) {
			$this->addModelErrors($this->getFieldLayout(), 'fieldLayout');
		}
	}

	public function validateOptionFieldLayout(): void
	{
		if (! $this->getOptionFieldLayout()->validate()) {
			$this->addModelErrors($this->getOptionFieldLayout(), 'optionFieldLayout');
		}
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			...parent::defineRules(),
			[['name', 'handle'], 'required'],
			[['name', 'handle'],
				'string',
				'max' => 255],
			[['handle'],
				HandleValidator::class,
				'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']],
			['handle', 'validateHandleUnique'],
			['fieldLayout', 'validateFieldLayout'],
			['optionFieldLayout', 'validateOptionFieldLayout'],
		];
	}

	/**
	 * Build through createFromConfig(), because a bare FieldLayout has no Display Name or System Name field.
	 *
	 * Derive the tab and field uids instead of minting them, because a fresh uid each request loses the rendered content.
	 * The attribute and option layouts render side by side, so their uids are derived from different keys.
	 */
	private function emptyLayout(string $layoutKey): FieldLayout
	{
		$fieldLayout = FieldLayout::createFromConfig([
			'type' => VariantAttribute::class,
			'tabs' => [
				[
					'name' => Craft::t('app', 'Content'),
					'uid' => $this->derivedUid($layoutKey . '.tab'),
				],
			],
		]);

		foreach ($fieldLayout->getTabs() as $tab) {
			foreach ($tab->getElements() as $layoutElement) {
				$layoutElement->uid = $this->derivedUid($layoutKey . '.' . $layoutElement::class);
			}
		}

		return $fieldLayout;
	}

	/**
	 * A uuid-shaped digest, since this layout is never stored and has no uid to read back.
	 */
	private function derivedUid(string $name): string
	{
		$digest = md5(self::class . $name);

		return implode('-', [
			substr($digest, 0, 8),
			substr($digest, 8, 4),
			substr($digest, 12, 4),
			substr($digest, 16, 4),
			substr($digest, 20, 12),
		]);
	}
}
