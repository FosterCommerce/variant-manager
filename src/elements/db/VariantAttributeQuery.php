<?php

namespace fostercommerce\variantmanager\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\Plugin;

/**
 * @template TKey of array-key
 * @template TElement of VariantAttribute
 *
 * @extends ElementQuery<TKey,TElement>
 */
class VariantAttributeQuery extends ElementQuery
{
	public mixed $nameKey = null;

	public mixed $attributeId = null;

	public mixed $fieldSetUid = null;

	protected array $defaultOrderBy = [
		'variant_manager_attributes.name' => SORT_ASC,
	];

	public function init(): void
	{
		if (! isset($this->withStructure)) {
			$this->withStructure = true;
		}

		parent::init();
	}

	public function nameKey(mixed $value): static
	{
		$this->nameKey = $value;
		return $this;
	}

	/**
	 * Narrows the query results to the options of the given attributes, or to attributes with 0.
	 */
	public function attributeId(mixed $value): static
	{
		$this->attributeId = $value;
		return $this;
	}

	/**
	 * Narrows the query results to attributes assigned the given field set.
	 */
	public function fieldSetUid(mixed $value): static
	{
		$this->fieldSetUid = $value;
		return $this;
	}

	protected function fieldLayouts(): array
	{
		// These layouts are in project config, not the fieldlayouts table
		return VariantAttribute::fieldLayouts(null);
	}

	protected function beforePrepare(): bool
	{
		if (! isset($this->structureId)) {
			$this->structureId = Plugin::getInstance()->getVariantAttributes()->getStructureId();
		}

		if (! parent::beforePrepare()) {
			return false;
		}

		$this->joinElementTable(Table::ATTRIBUTES);

		$this->query->addSelect([
			'variant_manager_attributes.attributeId',
			'variant_manager_attributes.name',
			'variant_manager_attributes.nameKey',
			'variant_manager_attributes.displayType',
			'variant_manager_attributes.skuPartial',
			'variant_manager_attributes.priceModifier',
			'variant_manager_attributes.fieldSetUid',
		]);

		if (isset($this->attributeId)) {
			$this->subQuery->andWhere(Db::parseNumericParam('variant_manager_attributes.attributeId', $this->attributeId));
		}

		if (isset($this->nameKey)) {
			$this->subQuery->andWhere(Db::parseParam('variant_manager_attributes.nameKey', $this->nameKey));
		}

		if (isset($this->fieldSetUid)) {
			$this->subQuery->andWhere(Db::parseParam('variant_manager_attributes.fieldSetUid', $this->fieldSetUid));
		}

		return true;
	}
}
