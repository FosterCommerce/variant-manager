<?php

namespace fostercommerce\variantmanager\elements\conditions;

use Craft;
use craft\base\conditions\BaseSelectConditionRule;
use craft\base\ElementInterface;
use craft\commerce\elements\db\ProductQuery;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\helpers\ProductQuery as ProductQueryHelper;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ArrayHelper;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\fields\VariantAttributesField;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\services\VariantAttributes;

/**
 * Filters variants, or products through their variants, by one attribute's registered values.
 */
class VariantAttributeConditionRule extends BaseSelectConditionRule implements ElementConditionRuleInterface
{
	public ?int $attributeId = null;

	private VariantAttribute|false|null $selectedOption = null;

	public function getLabel(): string
	{
		return Craft::t('variant-manager', 'attributes.filterLabel', [
			'attribute' => $this->attribute()?->name,
		]);
	}

	/**
	 * Make the param unique per attribute, so Size and Color rules can both be added.
	 */
	public function getExclusiveQueryParams(): array
	{
		return ["variantAttribute:{$this->attributeId}"];
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function getConfig(): array
	{
		return parent::getConfig() + [
			'attributeId' => $this->attributeId,
		];
	}

	/**
	 * @param ProductQuery<int, Product>|VariantQuery<int, Variant> $query
	 */
	public function modifyQuery(ElementQueryInterface $query): void
	{
		$params = [];
		$condition = $this->fieldCondition($params);

		if ($condition === null) {
			return;
		}

		if ($query instanceof ProductQuery) {
			// Filter products through their variants, because the field is only on variant layouts
			$query->hasVariant($this->variantQuery($query)->andWhere($condition, $params));
			return;
		}

		$query->andWhere($condition, $params);
	}

	public function matchElement(ElementInterface $element): bool
	{
		if ($element instanceof Product) {
			foreach ($element->getVariants() as $variant) {
				if ($this->matchVariant($variant)) {
					return true;
				}
			}

			return false;
		}

		return $element instanceof Variant && $this->matchVariant($element);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		$rules = parent::defineRules();
		// Validate attributeId, or building the rule from config drops it
		$rules[] = [['attributeId'],
			'number',
			'integerOnly' => true];
		return $rules;
	}

	/**
	 * @return array<int, string>
	 */
	protected function options(): array
	{
		if ($this->attributeId === null) {
			return [];
		}

		return ArrayHelper::map(VariantAttribute::find()->attributeId($this->attributeId)->all(), 'id', 'name');
	}

	/**
	 * Reuse an existing hasVariant filter, given as a query or as criteria.
	 *
	 * @param ProductQuery<int, Product> $query
	 * @return VariantQuery<int, Variant>
	 */
	private function variantQuery(ProductQuery $query): VariantQuery
	{
		if ($query->hasVariant instanceof VariantQuery) {
			return $query->hasVariant;
		}

		if (is_array($query->hasVariant)) {
			/** @var VariantQuery<int, Variant> $configured */
			$configured = Craft::configure(Variant::find(), ProductQueryHelper::cleanseQueryCriteria($query->hasVariant));

			return $configured;
		}

		return Variant::find();
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function fieldCondition(array &$params): ?string
	{
		// Leave an incomplete rule out of the query, because it names no attribute or option
		if ($this->attributeId === null || $this->value === '') {
			return null;
		}

		$attribute = $this->attribute();
		$option = $this->selectedOption();
		$instances = Plugin::getInstance()->getVariantAttributes()->getVariantAttributesFields();

		// Match no element for a deleted attribute or option, or where no layout has the field, rather than stop filtering
		if (! $attribute instanceof VariantAttribute || ! $option instanceof VariantAttribute || $instances === []) {
			return '0=1';
		}

		return VariantAttributesField::pairConditionIgnoringCase($instances, $attribute->name, $option->name, $params);
	}

	private function matchVariant(Variant $variant): bool
	{
		$attribute = $this->attribute();
		$option = $this->selectedOption();

		if (! $attribute instanceof VariantAttribute || ! $option instanceof VariantAttribute) {
			return false;
		}

		$pairs = Plugin::getInstance()->getVariantAttributes()->attributePairs([$variant]);

		return isset($pairs[VariantAttributes::pairKey($attribute->name, $option->name)]);
	}

	private function attribute(): ?VariantAttribute
	{
		return $this->attributeId === null
			? null
			: Plugin::getInstance()->getVariantAttributes()->getAttributeById($this->attributeId);
	}

	private function selectedOption(): ?VariantAttribute
	{
		if ($this->selectedOption === null) {
			$option = $this->value === ''
				? null
				: VariantAttribute::find()->id((int) $this->value)->one();

			$this->selectedOption = $option instanceof VariantAttribute ? $option : false;
		}

		return $this->selectedOption === false ? null : $this->selectedOption;
	}
}
