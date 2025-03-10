<?php

namespace fostercommerce\variantmanager\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use fostercommerce\variantmanager\helpers\FieldHelper;
use yii\db\ExpressionInterface;
use yii\db\Schema;

/**
 * @property-read string $contentColumnType
 */
class VariantAttributesField extends Field
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
		// Don't serialize the value, this is a JSON column.
		return $value;
	}

	public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
	{
		$name = $this->handle;
		$id = Html::id($name);
		$namespacedId = Craft::$app->view->namespaceInputId($id);
		$uniquePrefix = explode('-', $namespacedId)[0];

		return Craft::$app->getView()->renderTemplate('variant-manager/fields/variant_attributes', [
			'id' => $id,
			'namespacedId' => $namespacedId,
			'name' => $name,
			'attributes' => $value,
			'multipleFieldsExist' => ! FieldHelper::isFirstVariantAttributesField($this, $element),
			'variant' => $element,
			'uniquePrefix' => $uniquePrefix,
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
					// If the value is an associative array, then we need to filter out variants that don't have the combination
					// of key/value pairs in their field.
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
				// If the value is a string, then we filter out variants that don't have that value in their fields attributeValue property.
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
				$whereParts['conditions'][] = <<<EOQ
"{$contentColumn}" @> {$valueParam}
EOQ;
				$whereParts['params'][$valueParam] = "[{\"attributeName\": \"{$key}\", \"attributeValue\": \"{$value}\"}]";
			}
		}
	}

	private function generateStringFilter(string $contentColumn, string $value, array &$whereParts): void
	{
		$fieldUid = $this->layoutElement->uid;
		$paramKey = StringHelper::randomString(4);
		$valueParam = ":av{$paramKey}";

		if (Craft::$app->getDb()->getIsMysql()) {
			// This query checks that the path returned by json_search on each side is the same path.
			$whereParts['conditions'][] = <<<EOQ
json_search({$contentColumn}->>"$.\"{$fieldUid}\"[*].attributeValue", 'one', {$valueParam}) is not null
EOQ;
			$whereParts['params'][$valueParam] = $value;
		} else {
			$whereParts['conditions'][] = <<<EOQ
"{$contentColumn}" @> {$valueParam}
EOQ;
			$whereParts['params'][$valueParam] = "[{\"attributeValue\": \"{$value}\"}]";
		}
	}
}
