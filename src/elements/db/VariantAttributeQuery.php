<?php

namespace fostercommerce\variantmanager\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\VariantAttribute;

/**
 * @template TKey of array-key
 * @template TElement of VariantAttribute
 *
 * @extends ElementQuery<TKey,TElement>
 */
class VariantAttributeQuery extends ElementQuery
{
	public mixed $nameKey = null;

	protected array $defaultOrderBy = [
		'variant_manager_attributes.name' => SORT_ASC,
	];

	public function nameKey(mixed $value): static
	{
		$this->nameKey = $value;
		return $this;
	}

	protected function fieldLayouts(): array
	{
		// These layouts are in project config, not the fieldlayouts table
		return VariantAttribute::fieldLayouts(null);
	}

	protected function beforePrepare(): bool
	{
		if (! parent::beforePrepare()) {
			return false;
		}

		$this->joinElementTable(Table::ATTRIBUTES);

		$this->query->addSelect([
			'variant_manager_attributes.name',
			'variant_manager_attributes.nameKey',
			'variant_manager_attributes.displayType',
		]);

		if (isset($this->nameKey)) {
			$this->subQuery->andWhere(Db::parseParam('variant_manager_attributes.nameKey', $this->nameKey));
		}

		return true;
	}
}
