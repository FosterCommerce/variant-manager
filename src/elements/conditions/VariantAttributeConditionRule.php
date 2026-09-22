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
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\fields\VariantAttributesField;
use fostercommerce\variantmanager\Plugin;
use yii\db\ExpressionInterface;

/**
 * Filters variants, or products through their variants, by one attribute's registered values.
 */
class VariantAttributeConditionRule extends BaseSelectConditionRule implements ElementConditionRuleInterface
{
	public ?int $attributeId = null;

	private VariantAttribute|false|null $selectedOption = null;

	/**
	 * @var list<VariantAttributesField>|null
	 */
	private ?array $fieldInstances = null;

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

		return $this->matchVariant($element);
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
	 * @return list<array<string, string>>
	 */
	protected function options(): array
	{
		if ($this->attributeId === null) {
			return [];
		}

		$options = [];

		foreach (VariantAttribute::find()->attributeId($this->attributeId)->all() as $option) {
			$options[] = [
				'label' => $option->name,
				'value' => (string) $option->id,
			];
		}

		return $options;
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
	 * @return array<array-key, mixed>|string|ExpressionInterface|null
	 */
	private function fieldCondition(array &$params): array|string|ExpressionInterface|null
	{
		$attribute = $this->attribute();
		$option = $this->selectedOption();
		$instances = $this->fieldInstances();

		if (! $attribute instanceof VariantAttribute || ! $option instanceof VariantAttribute || $instances === []) {
			return null;
		}

		$condition = VariantAttributesField::queryCondition($instances, [
			$attribute->name => $option->name,
		], $params);

		return $condition === false ? '0=1' : $condition;
	}

	private function matchVariant(ElementInterface $variant): bool
	{
		$attribute = $this->attribute();
		$option = $this->selectedOption();

		if (! $attribute instanceof VariantAttribute || ! $option instanceof VariantAttribute) {
			return false;
		}

		foreach ($this->fieldInstances() as $field) {
			$value = $variant->getFieldValue((string) $field->handle);

			// An unparseable JSON field value is the raw string
			if (! is_array($value)) {
				continue;
			}

			foreach ($value as $pair) {
				if (($pair['attributeName'] ?? null) === $attribute->name && ($pair['attributeValue'] ?? null) === $option->name) {
					return true;
				}
			}
		}

		return false;
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

	/**
	 * Collect the field instances from variant layouts.
	 *
	 * @return list<VariantAttributesField>
	 */
	private function fieldInstances(): array
	{
		if ($this->fieldInstances === null) {
			$this->fieldInstances = [];

			foreach (Craft::$app->getFields()->getLayoutsByType(Variant::class) as $fieldLayout) {
				foreach ($fieldLayout->getCustomFields() as $field) {
					if ($field instanceof VariantAttributesField) {
						$this->fieldInstances[] = $field;
					}
				}
			}
		}

		return $this->fieldInstances;
	}
}
