<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Variant;
use craft\helpers\Db;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\elements\VariantAttributeOption;
use fostercommerce\variantmanager\elements\VariantManagerVariant;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;
use Throwable;
use yii\base\Component;
use yii\caching\TagDependency;
use yii\db\IntegrityException;

/**
 * Variant attributes service.
 */
class VariantAttributes extends Component
{
	/**
	 * @var array<int, VariantAttribute>|null
	 */
	private ?array $attributesById = null;

	private string|false|null $fieldHandle = null;

	/**
	 * Attributes for the given names, indexed by name key.
	 *
	 * @param list<string> $names
	 * @return array<string, VariantAttribute>
	 */
	public function getAttributesByNames(array $names, bool $includeTrashed = false): array
	{
		$nameKeys = [];

		foreach ($names as $name) {
			$nameKey = VariantAttribute::normalizeName($name);

			if ($nameKey !== '') {
				$nameKeys[$nameKey] = Db::escapeParam($nameKey);
			}
		}

		if ($nameKeys === []) {
			return [];
		}

		$attributes = [];

		foreach (VariantAttribute::find()->nameKey(array_values($nameKeys))->trashed($includeTrashed ? null : false)->all() as $attribute) {
			$attributes[$attribute->nameKey] = $attribute;
		}

		return $attributes;
	}

	/**
	 * Get every attribute, indexed by ID.
	 *
	 * @return array<int, VariantAttribute>
	 */
	public function getAllAttributes(): array
	{
		if ($this->attributesById === null) {
			$this->attributesById = [];

			foreach (VariantAttribute::find()->all() as $attribute) {
				$this->attributesById[$attribute->id] = $attribute;
			}
		}

		return $this->attributesById;
	}

	public function getAttributeById(int $id): ?VariantAttribute
	{
		return $this->getAllAttributes()[$id] ?? null;
	}

	/**
	 * Get the distinct attribute name and value pairs stored on the given variants.
	 *
	 * @param Variant[] $variants
	 * @return array<string, array{attributeName: string, attributeValue: string}>
	 */
	public function attributePairs(array $variants): array
	{
		$pairs = [];

		foreach ($variants as $variant) {
			$fieldHandle = FieldHelper::getFirstVariantAttributesField($variant->getFieldLayout())?->handle;

			if ($fieldHandle === null) {
				continue;
			}

			$storedAttributes = $variant->{$fieldHandle};

			// An unparseable JSON field value is the raw string
			if (! is_array($storedAttributes)) {
				continue;
			}

			foreach ($storedAttributes as $pair) {
				// One malformed row would otherwise fail the whole import or backfill batch
				if (! is_string($pair['attributeName'] ?? null) || ! is_string($pair['attributeValue'] ?? null)) {
					continue;
				}

				$nameKey = VariantAttribute::normalizeName($pair['attributeName']);
				$valueKey = VariantAttributeOption::normalizeValue($pair['attributeValue']);
				$pairs["{$nameKey}\0{$valueKey}"] = $pair;
			}
		}

		return $pairs;
	}

	/**
	 * Get the registry rows whose name or value no longer appears on any variant.
	 *
	 * @return array{attributes: list<VariantAttribute>, options: list<VariantAttributeOption>}
	 */
	public function findOrphans(int $batchSize = 500): array
	{
		// Read the registry first: a row created during the scan is not an orphan
		$attributes = VariantAttribute::find()->all();
		$allOptions = VariantAttributeOption::find()->all();

		$storedPairs = $this->storedPairs($batchSize);

		$nameKeys = [];

		foreach ($storedPairs as $pair) {
			$nameKeys[VariantAttribute::normalizeName($pair['attributeName'])] = true;
		}

		$attributesById = [];
		$orphanedAttributes = [];

		foreach ($attributes as $attribute) {
			$attributesById[$attribute->id] = $attribute;

			if (! isset($nameKeys[$attribute->nameKey])) {
				$orphanedAttributes[] = $attribute;
			}
		}

		$orphanedOptions = [];

		foreach ($allOptions as $option) {
			$attribute = $attributesById[$option->attributeId] ?? null;
			$pairKey = ($attribute?->nameKey ?? '') . "\0" . $option->valueKey;

			if (! isset($storedPairs[$pairKey])) {
				$orphanedOptions[] = $option;
			}
		}

		return [
			'attributes' => $orphanedAttributes,
			'options' => $orphanedOptions,
		];
	}

	/**
	 * Registry rows for the given names and their values, indexed by name and then by raw value.
	 *
	 * @param array<string, list<string>> $valuesByName
	 * @return array<string, array{attribute: VariantAttribute, options: array<string, VariantAttributeOption>}>
	 */
	public function getRegistry(array $valuesByName): array
	{
		$names = array_map(static fn (int|string $name): string => (string) $name, array_keys($valuesByName));
		$attributes = $this->getAttributesByNames($names);

		if ($attributes === []) {
			return [];
		}

		$attributeIds = array_map(static fn (VariantAttribute $attribute): int => (int) $attribute->id, $attributes);

		$optionsByAttributeId = [];

		$valueKeys = [];

		foreach ($valuesByName as $values) {
			foreach ($values as $value) {
				$valueKeys[VariantAttributeOption::normalizeValue($value)] = true;
			}
		}

		$optionQuery = VariantAttributeOption::find()
			->attributeId(array_values($attributeIds))
			->valueKey(array_map(static fn (int|string $valueKey): string => Db::escapeParam((string) $valueKey), array_keys($valueKeys)));

		foreach ($optionQuery->all() as $option) {
			$optionsByAttributeId[$option->attributeId][$option->valueKey] = $option;
		}

		$registry = [];

		foreach ($valuesByName as $name => $values) {
			$attribute = $attributes[VariantAttribute::normalizeName((string) $name)] ?? null;

			if (! $attribute instanceof VariantAttribute) {
				continue;
			}

			$options = [];

			foreach ($values as $value) {
				$option = $optionsByAttributeId[$attribute->id][VariantAttributeOption::normalizeValue($value)] ?? null;

				if ($option instanceof VariantAttributeOption) {
					$options[$value] = $option;
				}
			}

			$registry[(string) $name] = [
				'attribute' => $attribute,
				'options' => $options,
			];
		}

		return $registry;
	}

	/**
	 * Get a query for the variants storing the option's attribute name and value.
	 *
	 * The match is a JSON search over all variant content, so page or count rather than call all()
	 */
	public function variantQueryForOption(VariantAttributeOption $option): ?VariantQuery
	{
		$attribute = $option->getVariantAttribute();

		if ($attribute === null) {
			return null;
		}

		$fieldHandle = $this->getVariantAttributesFieldHandle();

		if ($fieldHandle === null) {
			return null;
		}

		return VariantManagerVariant::find()
			->status(null)
			->{$fieldHandle}([
				$attribute->name => $option->value,
			]);
	}

	/**
	 * Get how many variants store the option's attribute name and value.
	 *
	 * Cached against the variant element tag, so a variant save or delete invalidates it.
	 */
	public function variantCountForOption(VariantAttributeOption $option): int
	{
		return Craft::$app->getCache()->getOrSet(
			"variant-manager:option-usage:{$option->id}",
			fn (): int => $this->variantQueryForOption($option)?->count() ?? 0,
			null,
			new TagDependency([
				'tags' => [
					sprintf('element::%s::*', Variant::class),
					sprintf('element::%s::*', VariantManagerVariant::class),
				],
			])
		);
	}

	public function isOptionInUse(VariantAttributeOption $option): bool
	{
		return $this->variantQueryForOption($option)?->exists() ?? false;
	}

	public function isAttributeInUse(VariantAttribute $attribute): bool
	{
		foreach (VariantAttributeOption::find()->attributeId($attribute->id)->all() as $option) {
			if ($this->isOptionInUse($option)) {
				return true;
			}
		}

		return false;
	}

	public function getVariantAttributesFieldHandle(): ?string
	{
		if ($this->fieldHandle === null) {
			$this->fieldHandle = false;

			foreach (Craft::$app->getFields()->getLayoutsByType(Variant::class) as $fieldLayout) {
				$field = FieldHelper::getFirstVariantAttributesField($fieldLayout);

				if ($field !== null) {
					$this->fieldHandle = $field->handle;
					break;
				}
			}
		}

		return $this->fieldHandle === false ? null : $this->fieldHandle;
	}

	/**
	 * Creates a registry row for each name that has none yet.
	 *
	 * @param list<string> $names
	 * @return array<string, VariantAttribute>
	 * @throws Throwable
	 */
	public function ensureAttributes(array $names): array
	{
		// A trashed row keeps its unique name key, so the row is restored rather than replaced
		$attributes = $this->getAttributesByNames($names, true);

		foreach ($names as $name) {
			$name = trim($name);
			$nameKey = VariantAttribute::normalizeName($name);

			if ($nameKey === '') {
				continue;
			}

			if (isset($attributes[$nameKey])) {
				$this->restoreIfTrashed($attributes[$nameKey]);
				continue;
			}

			$attribute = new VariantAttribute();
			$attribute->name = $name;
			$attribute->title = $name;
			$attribute->displayType = Plugin::getInstance()->getSettings()->getDefaultDisplayType()->value;

			try {
				Craft::$app->getElements()->saveElement($attribute, false);
			} catch (IntegrityException) {
				// Another process registered this name key first, so use its row
				$attribute = $this->getAttributesByNames([$name], true)[$nameKey] ?? null;

				if (! $attribute instanceof VariantAttribute) {
					continue;
				}

				$this->restoreIfTrashed($attribute);
			}

			$attributes[$nameKey] = $attribute;

			if ($this->attributesById !== null) {
				$this->attributesById[$attribute->id] = $attribute;
			}
		}

		return $attributes;
	}

	/**
	 * Creates an option row for each of an attribute's values that has none yet.
	 *
	 * @param list<string> $values
	 * @throws Throwable
	 */
	public function ensureOptions(VariantAttribute $attribute, array $values): void
	{
		$valueKeys = [];

		foreach ($values as $value) {
			$valueKey = VariantAttributeOption::normalizeValue($value);

			if ($valueKey !== '') {
				$valueKeys[$valueKey] = Db::escapeParam($valueKey);
			}
		}

		if ($valueKeys === []) {
			return;
		}

		$options = [];

		// Filter to the given values so the query does not grow with the attribute's option count
		// A trashed row keeps its unique value key, so the row is restored rather than replaced
		$optionQuery = VariantAttributeOption::find()
			->attributeId($attribute->id)
			->valueKey(array_values($valueKeys))
			->trashed(null);

		foreach ($optionQuery->all() as $option) {
			$options[$option->valueKey] = $option;
		}

		foreach ($values as $value) {
			$valueKey = VariantAttributeOption::normalizeValue($value);

			if ($valueKey === '') {
				continue;
			}

			if (isset($options[$valueKey])) {
				$this->restoreIfTrashed($options[$valueKey]);
				continue;
			}

			$option = new VariantAttributeOption();
			$option->attributeId = $attribute->id;
			$option->value = trim($value);
			$option->title = trim($value);

			try {
				Craft::$app->getElements()->saveElement($option, false);
			} catch (IntegrityException) {
				// Another process registered this value key first, so use its row
				$option = VariantAttributeOption::find()
					->attributeId($attribute->id)
					->valueKey(Db::escapeParam($valueKey))
					->trashed(null)
					->one();

				if (! $option instanceof VariantAttributeOption) {
					continue;
				}

				$this->restoreIfTrashed($option);
			}

			$options[$valueKey] = $option;
		}
	}

	/**
	 * Registers every attribute name and option value in the given name/value pairs.
	 *
	 * @param array<int, array{attributeName: string, attributeValue: string}> $pairs
	 * @throws Throwable
	 */
	public function ensureFromAttributePairs(array $pairs): void
	{
		$valuesByName = [];

		foreach ($pairs as $pair) {
			$valuesByName[$pair['attributeName']][] = $pair['attributeValue'];
		}

		foreach ($valuesByName as $name => $values) {
			$name = (string) $name;
			$attribute = $this->ensureAttributes([$name])[VariantAttribute::normalizeName($name)] ?? null;

			if ($attribute instanceof VariantAttribute) {
				$this->ensureOptions($attribute, array_values(array_unique($values)));
			}
		}
	}

	/**
	 * Deletes every orphaned option and attribute.
	 *
	 * @param array{attributes: list<VariantAttribute>, options: list<VariantAttributeOption>}|null $orphans orphans already found, to skip a second scan
	 * @return array{attributes: int, options: int}
	 * @throws Throwable
	 */
	public function pruneOrphans(int $batchSize = 500, ?array $orphans = null): array
	{
		$orphans ??= $this->findOrphans($batchSize);
		$elementsService = Craft::$app->getElements();

		// Options first: variant_manager_attribute_options.attributeId cascades on delete
		// Hard delete, since a trashed row keeps its unique key and blocks re-registering the value
		foreach ($orphans['options'] as $option) {
			$elementsService->deleteElement($option, true);
		}

		$attributeConfigs = Plugin::getInstance()->getAttributeConfigs();
		$projectConfig = Craft::$app->getProjectConfig();

		foreach ($orphans['attributes'] as $attribute) {
			if (! $elementsService->deleteElement($attribute, true)) {
				continue;
			}

			// Remove the config here, after the delete commits, rather than from afterDelete()
			if (! $projectConfig->readOnly) {
				$attributeConfigs->remove($attribute->nameKey);
			}
		}

		return [
			'attributes' => count($orphans['attributes']),
			'options' => count($orphans['options']),
		];
	}

	/**
	 * Get every attribute name and value pair stored on any variant.
	 *
	 * @return array<string, array{attributeName: string, attributeValue: string}>
	 */
	private function storedPairs(int $batchSize = 500): array
	{
		$pairs = [];

		foreach (Variant::find()->status(null)->batch($batchSize) as $variants) {
			$pairs = [...$pairs, ...$this->attributePairs($variants)];
		}

		return $pairs;
	}

	private function restoreIfTrashed(ElementInterface $element): void
	{
		if ($element->dateDeleted !== null) {
			Craft::$app->getElements()->restoreElement($element);
		}
	}
}
