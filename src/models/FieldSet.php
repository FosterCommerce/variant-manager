<?php

namespace fostercommerce\variantmanager\models;

use craft\base\Model;
use craft\models\FieldLayout;
use fostercommerce\variantmanager\elements\VariantAttribute;

/**
 * A pair of field layouts, one for an attribute and one for its options, that any number of attributes share.
 */
class FieldSet extends Model
{
	public ?string $uid = null;

	public ?string $name = null;

	private ?FieldLayout $fieldLayout = null;

	private ?FieldLayout $optionFieldLayout = null;

	public function getFieldLayout(): FieldLayout
	{
		return $this->fieldLayout ??= new FieldLayout([
			'type' => VariantAttribute::class,
		]);
	}

	public function setFieldLayout(FieldLayout $fieldLayout): void
	{
		$this->fieldLayout = $fieldLayout;
	}

	public function getOptionFieldLayout(): FieldLayout
	{
		return $this->optionFieldLayout ??= new FieldLayout([
			'type' => VariantAttribute::class,
		]);
	}

	public function setOptionFieldLayout(FieldLayout $optionFieldLayout): void
	{
		$this->optionFieldLayout = $optionFieldLayout;
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

	protected function defineRules(): array
	{
		return [
			...parent::defineRules(),
			[['name'], 'required'],
			[['name'],
				'string',
				'max' => 255],
			['fieldLayout', 'validateFieldLayout'],
			['optionFieldLayout', 'validateOptionFieldLayout'],
		];
	}
}
