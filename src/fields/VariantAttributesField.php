<?php

namespace fostercommerce\variantmanager\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\elements\VariantAttributeOption;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;
use yii\db\ExpressionInterface;
use yii\db\Schema;

/**
 * @property-read string $contentColumnType
 */
class VariantAttributesField extends Field implements PreviewableFieldInterface
{
	public static function displayName(): string
	{
		return Craft::t('variant-manager', 'Variant Attributes');
	}

	public static function valueType(): string
	{
		return 'array|null';
	}

	public static function dbType(): array|string|null
	{
		return Schema::TYPE_JSON;
	}

	public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
	{
		if (is_string($value) && $value !== '') {
			return Json::decodeIfJson($value);
		}

		return $value;
	}

	public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
	{
		// Store as-is, since the column is JSON
		return $value;
	}

	public function getPreviewHtml(mixed $value, ElementInterface $element): string
	{
		// An unparseable JSON field value is the raw string
		if (! is_array($value)) {
			return '';
		}

		return Html::encode(implode(', ', array_column($value, 'attributeValue')));
	}

	public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
	{
		$namespacedId = Craft::$app->view->namespaceInputId(Html::id($this->handle));

		return Craft::$app->getView()->renderTemplate('variant-manager/fields/variant_attributes', [
			'namespacedId' => $namespacedId,
			'rows' => $this->registryRows($value),
			'multipleFieldsExist' => ! FieldHelper::isFirstVariantAttributesField($this, $element),
		]);
	}

	public static function queryCondition(
		array $instances,
		mixed $value,
		array &$params,
	): array|string|ExpressionInterface|false|null {
		$db = Craft::$app->getDb();
		$qb = $db->getQueryBuilder();
		$contentColumn = $qb->db->quoteColumnName('elements_sites.content');

		$conditions = [];
		$params = [];

		foreach ($instances as $instance) {
			$jsonPath = $instance->layoutElement->uid;

			if (! isset($value)) {
				return null;
			}

			$whereParts = [
				'type' => 'AND',
				'conditions' => [],
				'params' => [],
			];
			if (is_array($value)) {
				if (! array_is_list($value)) {
					// Match variants storing every name/value pair in the filter
					$instance->generateAssociativeFilter($contentColumn, $value, $whereParts);
				} else {
					$whereParts = [
						'type' => 'OR',
						'conditions' => [],
						'params' => [],
					];
					foreach ($value as $filter) {
						if (is_array($filter) && ! array_is_list($filter)) {
							$instance->generateAssociativeFilter($contentColumn, $filter, $whereParts);
						} elseif (is_string($filter)) {
							$instance->generateStringFilter($contentColumn, $filter, $whereParts);
						} else {
							throw new \RuntimeException('$value items must be associative arrays or strings');
						}
					}
				}
			} elseif (is_string($value)) {
				// Match variants storing this value under any attribute name
				$instance->generateStringFilter($contentColumn, $value, $whereParts);
			} else {
				throw new \RuntimeException('$value must be either an array or a string');
			}

			if ($whereParts['conditions'] !== []) {
				$conditions[] = '(' . implode(" {$whereParts['type']} ", $whereParts['conditions']) . ')';
				$params = [
					...$params,
					...$whereParts['params'],
				];
			}
		}

		return $qb->buildCondition(implode(' OR ', $conditions), $params);
	}

	/**
	 * Pair each stored attribute with its registry elements, or null where the pair is unregistered.
	 *
	 * @return list<array{attributeName: string, attributeValue: string, attribute: ?VariantAttribute, option: ?VariantAttributeOption}>
	 */
	private function registryRows(mixed $fieldValue): array
	{
		// An unparseable JSON field value is the raw string
		if (! is_array($fieldValue)) {
			return [];
		}

		$attributes = Plugin::getInstance()->getVariantAttributes()->getAttributesByNames(array_column($fieldValue, 'attributeName'));
		$attributeIds = array_map(static fn (VariantAttribute $attribute): int => (int) $attribute->id, $attributes);
		$options = [];

		if ($attributeIds !== []) {
			foreach (VariantAttributeOption::find()->attributeId(array_values($attributeIds))->all() as $option) {
				$options["{$option->attributeId}\0{$option->valueKey}"] = $option;
			}
		}

		$rows = [];

		foreach ($fieldValue as $pair) {
			$attribute = $attributes[VariantAttribute::normalizeName($pair['attributeName'])] ?? null;
			$optionKey = $attribute?->id . "\0" . VariantAttributeOption::normalizeValue($pair['attributeValue']);

			$rows[] = [
				'attributeName' => $pair['attributeName'],
				'attributeValue' => $pair['attributeValue'],
				'attribute' => $attribute,
				'option' => $options[$optionKey] ?? null,
			];
		}

		return $rows;
	}

	private function generateAssociativeFilter(string $contentColumn, array $filter, array &$whereParts): void
	{
		if (
			array_filter(
				$filter,
				static fn ($value, $key): bool => ! is_string($value),
				ARRAY_FILTER_USE_BOTH
			) !== []
		) {
			throw new \RuntimeException('filter values must be strings');
		}

		$fieldUid = $this->layoutElement->uid;

		foreach ($filter as $key => $value) {
			$paramKey = StringHelper::randomString(4);
			$keyParam = ":an{$paramKey}";
			$valueParam = ":av{$paramKey}";
			if (Craft::$app->getDb()->getIsMysql()) {
				// This query checks that the path returned by json_search on each side is the same path.
				$whereParts['conditions'][] = <<<EOQ
json_search({$contentColumn}->>"$.\"{$fieldUid}\"[*].attributeName", 'one', {$keyParam})
= json_search({$contentColumn}->>"$.\"{$fieldUid}\"[*].attributeValue", 'one', {$valueParam})
EOQ;
				$whereParts['params'][$keyParam] = $key;
				$whereParts['params'][$valueParam] = $value;
			} else {
				$fieldParam = ":af{$paramKey}";
				// Content is keyed by field uid, so containment is checked against that key's array
				$whereParts['conditions'][] = "{$contentColumn} -> {$fieldParam} @> {$valueParam}::jsonb";
				$whereParts['params'][$fieldParam] = $fieldUid;
				$whereParts['params'][$valueParam] = Json::encode([[
					'attributeName' => $key,
					'attributeValue' => $value,
				]]);
			}
		}
	}

	private function generateStringFilter(string $contentColumn, string $value, array &$whereParts): void
	{
		$fieldUid = $this->layoutElement->uid;
		$paramKey = StringHelper::randomString(4);
		$valueParam = ":av{$paramKey}";

		if (Craft::$app->getDb()->getIsMysql()) {
			// Match any attributeValue in the field's JSON array
			$whereParts['conditions'][] = <<<EOQ
json_search({$contentColumn}->>"$.\"{$fieldUid}\"[*].attributeValue", 'one', {$valueParam}) is not null
EOQ;
			$whereParts['params'][$valueParam] = $value;
		} else {
			$fieldParam = ":af{$paramKey}";
			// Content is keyed by field uid, so containment is checked against that key's array
			$whereParts['conditions'][] = "{$contentColumn} -> {$fieldParam} @> {$valueParam}::jsonb";
			$whereParts['params'][$fieldParam] = $fieldUid;
			$whereParts['params'][$valueParam] = Json::encode([[
				'attributeValue' => $value,
			]]);
		}
	}
}
