<?php

namespace fostercommerce\variantmanager\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\VariantAttributeOption;

/**
 * @template TKey of array-key
 * @template TElement of VariantAttributeOption
 *
 * @extends ElementQuery<TKey,TElement>
 */
class VariantAttributeOptionQuery extends ElementQuery
{
	public mixed $attributeId = null;

	public mixed $valueKey = null;

	protected array $defaultOrderBy = [
		'variant_manager_attribute_options.value' => SORT_ASC,
	];

	public function attributeId(mixed $value): static
	{
		$this->attributeId = $value;
		return $this;
	}

	public function valueKey(mixed $value): static
	{
		$this->valueKey = $value;
		return $this;
	}

	protected function fieldLayouts(): array
	{
		// These layouts are in project config, not the fieldlayouts table
		return VariantAttributeOption::fieldLayouts(null);
	}

	protected function beforePrepare(): bool
	{
		if (! parent::beforePrepare()) {
			return false;
		}

		$this->joinElementTable(Table::ATTRIBUTE_OPTIONS);

		$this->query->addSelect([
			'variant_manager_attribute_options.attributeId',
			'variant_manager_attribute_options.value',
			'variant_manager_attribute_options.valueKey',
		]);

		if (isset($this->attributeId)) {
			$this->subQuery->andWhere(Db::parseNumericParam('variant_manager_attribute_options.attributeId', $this->attributeId));
		}

		if (isset($this->valueKey)) {
			$this->subQuery->andWhere(Db::parseParam('variant_manager_attribute_options.valueKey', $this->valueKey));
		}

		return true;
	}
}
