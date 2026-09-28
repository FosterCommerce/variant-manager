<?php

namespace fostercommerce\variantmanager\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\helpers\Html;
use craft\helpers\Json;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\VariantAttributesFieldAssetBundle;
use yii\db\ExpressionInterface;
use yii\db\Schema;

/**
 * @property-read string $contentColumnType
 */
class VariantAttributesField extends Field implements PreviewableFieldInterface
{
	/**
	 * Numbers each query param, since several conditions from this field can share one query.
	 */
	private static int $paramCount = 0;

	public static function displayName(): string
	{
		return Craft::t('variant-manager', 'attributes.attributes');
	}

	public static function phpType(): string
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
		if (! is_array($value)) {
			return $value;
		}

		// Clean every stored name and value the way the registry cleans system names, whichever code wrote them
		return array_map(static fn (mixed $pair): mixed => is_array($pair) ? array_map(
			static fn (mixed $part): mixed => is_string($part) ? VariantAttribute::cleanName($part) : $part,
			$pair,
		) : $pair, $value);
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
		$view = Craft::$app->getView();
		$view->registerAssetBundle(VariantAttributesFieldAssetBundle::class);

		$namespacedId = $view->namespaceInputId(Html::id((string) $this->handle));

		return $view->renderTemplate('variant-manager/fields/variant_attributes', [
			'namespacedId' => $namespacedId,
			'rows' => $this->registryRows($value),
			'multipleFieldsExist' => ! FieldHelper::isFirstVariantAttributesField($this, $element),
			'variantMaker' => $this->offersVariantMaker($element) ? 'enabled' : 'disabled',
			// Name the Variant Maker only on a variant that isn't a revision, because revisions can't be edited
			'showManageNote' => $element instanceof Variant && ! $element->getIsRevision(),
			'elementType' => $element instanceof ElementInterface ? $element::lowerDisplayName() : Variant::lowerDisplayName(),
		]);
	}

	/**
	 * @param array<int, self> $instances
	 * @param array<string, mixed> $params
	 * @return array<array-key, mixed>|string|ExpressionInterface|false|null
	 */
	public static function queryCondition(
		array $instances,
		mixed $value,
		array &$params,
	): array|string|ExpressionInterface|false|null {
		$db = Craft::$app->getDb();
		$qb = $db->getQueryBuilder();
		$contentColumn = $qb->db->quoteColumnName('elements_sites.content');

		$conditions = [];

		foreach ($instances as $instance) {
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
	 * A condition matching elements that store the pair in any of these fields, ignoring letter case and surrounding whitespace.
	 *
	 * The SQL lowercasing can differ from the registry's for letters outside ASCII, so the in-use checks scan variants for those names.
	 *
	 * @param list<self> $instances
	 * @param array<string, mixed> $params
	 */
	public static function pairConditionIgnoringCase(array $instances, string $attributeName, string $attributeValue, array &$params): string
	{
		if ($instances === []) {
			return '0=1';
		}

		$contentColumn = Craft::$app->getDb()->quoteColumnName('elements_sites.content');
		$pair = [
			'attributeName' => $attributeName,
			'attributeValue' => $attributeValue,
		];
		$whereParts = [
			'conditions' => [],
			'params' => [],
		];

		foreach ($instances as $instance) {
			$instance->addContainsCondition($contentColumn, $pair, $whereParts, true);
		}

		$params = [...$params, ...$whereParts['params']];

		return '(' . implode(' OR ', $whereParts['conditions']) . ')';
	}

	private function offersVariantMaker(?ElementInterface $element): bool
	{
		$productType = $element instanceof Variant ? $element->getFieldLayout()?->provider : null;

		return $productType instanceof ProductType && Plugin::getInstance()->getSettings()->offersVariantMaker((string) $productType->handle);
	}

	/**
	 * Pair each stored attribute with its registry elements, or null where the pair is unregistered.
	 *
	 * @return list<array{attributeName: string, attributeValue: string, attribute: ?VariantAttribute, option: ?VariantAttribute}>
	 */
	private function registryRows(mixed $fieldValue): array
	{
		// An unparseable JSON field value is the raw string
		if (! is_array($fieldValue)) {
			return [];
		}

		// Skip a malformed pair rather than fail the variant's edit screen
		$pairs = array_filter($fieldValue, static fn (mixed $pair): bool => is_array($pair) && is_string($pair['attributeName'] ?? null) && is_string($pair['attributeValue'] ?? null));
		$attributes = Plugin::getInstance()->getVariantAttributes()->getAttributesByNames(array_column($pairs, 'attributeName'));
		$attributeIds = array_map(static fn (VariantAttribute $attribute): int => (int) $attribute->id, $attributes);
		$options = $attributeIds === []
			? []
			: collect(VariantAttribute::find()->attributeId(array_values($attributeIds))->all())
				->keyBy(static fn (VariantAttribute $option): string => "{$option->attributeId}\0{$option->nameKey}")
				->all();

		$rows = [];

		foreach ($pairs as $pair) {
			$attribute = $attributes[VariantAttribute::normalizeName($pair['attributeName'])] ?? null;
			$optionKey = $attribute?->id . "\0" . VariantAttribute::normalizeName($pair['attributeValue']);

			$rows[] = [
				'attributeName' => $pair['attributeName'],
				'attributeValue' => $pair['attributeValue'],
				'attribute' => $attribute,
				'option' => $options[$optionKey] ?? null,
			];
		}

		return $rows;
	}

	/**
	 * @param array<array-key, mixed> $filter
	 * @param array<array-key, mixed> $whereParts
	 */
	private function generateAssociativeFilter(string $contentColumn, array $filter, array &$whereParts): void
	{
		foreach ($filter as $key => $value) {
			if (! is_string($value)) {
				throw new \RuntimeException('filter values must be strings');
			}

			$this->addContainsCondition($contentColumn, [
				'attributeName' => (string) $key,
				'attributeValue' => $value,
			], $whereParts);
		}
	}

	/**
	 * @param array{conditions: list<string>, params: array<string, mixed>} $whereParts
	 */
	private function generateStringFilter(string $contentColumn, string $value, array &$whereParts): void
	{
		$this->addContainsCondition($contentColumn, [
			'attributeValue' => $value,
		], $whereParts);
	}

	/**
	 * Match variants whose field stores a pair containing every key in $pair, compared exactly unless $ignoreCase.
	 *
	 * @param array<string, string> $pair
	 * @param array{conditions: list<string>, params: array<string, mixed>} $whereParts
	 */
	private function addContainsCondition(string $contentColumn, array $pair, array &$whereParts, bool $ignoreCase = false): void
	{
		$paramKey = self::$paramCount++;
		$fieldParam = ":vmField{$paramKey}";
		$pairParam = ":vmPair{$paramKey}";
		$fieldUid = (string) $this->layoutElement?->uid;

		// Compare with JSON containment on both databases, so `%` and `_` match literally
		if (Craft::$app->getDb()->getIsMysql()) {
			$storedPairs = "JSON_EXTRACT({$contentColumn}, {$fieldParam})";
			// Convert both sides to one collation, so LOWER() applies one case table
			$whereParts['conditions'][] = $ignoreCase
				? 'JSON_CONTAINS(' . $this->foldedJson("CONVERT({$storedPairs} USING utf8mb4)", false) . ', ' . $this->foldedJson("CONVERT({$pairParam} USING utf8mb4)", false) . ')'
				: "JSON_CONTAINS({$storedPairs}, {$pairParam})";
			$whereParts['params'][$fieldParam] = '$."' . $fieldUid . '"';
		} else {
			$storedPairs = "{$contentColumn} -> {$fieldParam}";
			$whereParts['conditions'][] = $ignoreCase
				? $this->foldedJson("({$storedPairs})::text", true) . '::jsonb @> ' . $this->foldedJson($pairParam, true) . '::jsonb'
				: "{$storedPairs} @> {$pairParam}::jsonb";
			$whereParts['params'][$fieldParam] = $fieldUid;
		}

		$whereParts['params'][$pairParam] = Json::encode([$pair]);
	}

	/**
	 * SQL that lowercases JSON text and trims spaces inside each string's quotes, close to how the registry keys names.
	 *
	 * Fold final sigma to sigma, because PHP's lowercasing picks between them by position.
	 */
	private function foldedJson(string $jsonSql, bool $isPgsql): string
	{
		$lowered = "REPLACE(LOWER({$jsonSql}), 'ς', 'σ')";
		// Trim only at a string's opening and closing quotes, found by the JSON punctuation beside them
		$opening = '(?<=[:,{])[[:space:]]*"[[:space:]]+';
		$closing = '[[:space:]]+"(?=[[:space:]]*[,}:])';

		return $isPgsql
			? "regexp_replace(regexp_replace({$lowered}, '{$opening}', '\"', 'g'), '{$closing}', '\"', 'g')"
			: "REGEXP_REPLACE(REGEXP_REPLACE({$lowered}, '{$opening}', '\"'), '{$closing}', '\"')";
	}
}
