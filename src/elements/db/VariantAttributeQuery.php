<?php

namespace fostercommerce\variantmanager\elements\db;

use craft\db\Query;
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
	/**
	 * @var int|string|list<int|string>|null
	 */
	public int|string|array|null $nameKey = null;

	/**
	 * @var int|string|list<int|string>|null
	 */
	public int|string|array|null $attributeId = null;

	/**
	 * @var int|string|list<int|string>|null
	 */
	public int|string|array|null $fieldSetUid = null;

	/**
	 * @var array<string, int>
	 */
	protected array $defaultOrderBy = [
		'variant_manager_attributes.name' => SORT_ASC,
	];

	public function init(): void
	{
		if ($this->withStructure === null) {
			$this->withStructure = true;
		}

		parent::init();
	}

	/**
	 * Narrows the query results to records with the given normalized name.
	 *
	 * @param int|string|list<int|string>|null $value
	 */
	public function nameKey(int|string|array|null $value): static
	{
		$this->nameKey = $value;
		return $this;
	}

	/**
	 * Narrows the query results to the options of the given attributes, or to attributes with 0.
	 *
	 * @param int|string|list<int|string>|null $value
	 */
	public function attributeId(int|string|array|null $value): static
	{
		$this->attributeId = $value;
		return $this;
	}

	/**
	 * Narrows the query results to attributes assigned the given field set.
	 *
	 * @param int|string|list<int|string>|null $value
	 */
	public function fieldSetUid(int|string|array|null $value): static
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
		if ($this->structureId === null) {
			$this->structureId = Plugin::getInstance()->getVariantAttributes()->getStructureId();
		}

		if (! parent::beforePrepare()) {
			return false;
		}

		$this->joinElementTable(Table::ATTRIBUTES);

		/** @var Query<int|string, mixed> $query */
		$query = $this->query;
		/** @var Query<int|string, mixed> $subQuery */
		$subQuery = $this->subQuery;

		$query->addSelect([
			'variant_manager_attributes.attributeId',
			'variant_manager_attributes.name',
			'variant_manager_attributes.nameKey',
			'variant_manager_attributes.displayType',
			'variant_manager_attributes.skuPartial',
			'variant_manager_attributes.priceModifier',
			'variant_manager_attributes.fieldSetUid',
		]);

		$condition = $this->attributeId === null ? null : Db::parseNumericParam('variant_manager_attributes.attributeId', $this->stringParam($this->attributeId));

		if ($condition !== null) {
			$subQuery->andWhere($condition);
		}

		$condition = $this->nameKey === null ? null : Db::parseParam('variant_manager_attributes.nameKey', $this->stringParam($this->nameKey));

		if ($condition !== null) {
			$subQuery->andWhere($condition);
		}

		$condition = $this->fieldSetUid === null ? null : Db::parseParam('variant_manager_attributes.fieldSetUid', $this->stringParam($this->fieldSetUid));

		if ($condition !== null) {
			$subQuery->andWhere($condition);
		}

		return true;
	}

	/**
	 * Cast to string, because parseNumericParam() is documented as taking string|string[] and these params accept int.
	 *
	 * @param int|string|list<int|string> $value
	 * @return string|list<string>
	 */
	private function stringParam(int|string|array $value): string|array
	{
		return is_array($value)
			? array_map(static fn (int|string $one): string => (string) $one, array_values($value))
			: (string) $value;
	}
}
