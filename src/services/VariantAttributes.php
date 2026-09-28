<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Variant;
use craft\db\Query;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\elements\VariantManagerVariant;
use fostercommerce\variantmanager\fields\VariantAttributesField;
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

	private ?int $structureId = null;

	/**
	 * @var array<string, true>
	 */
	private array $ensuredPairKeys = [];

	/**
	 * @var list<VariantAttributesField>|null
	 */
	private ?array $fields = null;

	public function getStructureId(): int
	{
		if ($this->structureId === null) {
			$this->structureId = (int) (new Query())
				->select(['id'])
				->from(Table::STRUCTURES)
				->scalar();
		}

		return $this->structureId;
	}

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
				$nameKeys[$nameKey] = $nameKey;
			}
		}

		if ($nameKeys === []) {
			return [];
		}

		/** @var array<string, VariantAttribute> */
		return VariantAttribute::find()
			->attributeId(0)
			->nameKey(array_values($nameKeys))
			->trashed($includeTrashed ? null : false)
			->indexBy('nameKey')
			->all();
	}

	/**
	 * Get every attribute, indexed by ID.
	 *
	 * @return array<int, VariantAttribute>
	 */
	public function getAllAttributes(): array
	{
		if ($this->attributesById === null) {
			/** @var array<int, VariantAttribute> $attributesById */
			$attributesById = VariantAttribute::find()->attributeId(0)->indexBy('id')->all();
			$this->attributesById = $attributesById;
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
			foreach ($variant->getFieldLayout()?->getCustomFields() ?? [] as $field) {
				if (! $field instanceof VariantAttributesField) {
					continue;
				}

				$storedPairs = $variant->getFieldValue((string) $field->handle);

				// An unparseable JSON field value is the raw string
				if (! is_array($storedPairs)) {
					continue;
				}

				foreach ($storedPairs as $storedPair) {
					// One malformed row would otherwise fail the whole import or backfill batch
					if (! is_string($storedPair['attributeName'] ?? null)) {
						continue;
					}

					if (! is_string($storedPair['attributeValue'] ?? null)) {
						continue;
					}

					$pairs[self::pairKey($storedPair['attributeName'], $storedPair['attributeValue'])] = $storedPair;
				}
			}
		}

		return $pairs;
	}

	/**
	 * Get the registry records whose name or value no longer appears on any variant.
	 *
	 * @return array{attributes: list<VariantAttribute>, options: list<VariantAttribute>}
	 */
	public function findOrphans(int $batchSize = 500): array
	{
		// Read the registry first: a record created during the scan is not an orphan
		$attributes = VariantAttribute::find()->attributeId(0)->all();
		$options = VariantAttribute::find()->attributeId('not 0')->all();

		$storedPairs = $this->storedPairs($batchSize);

		$nameKeys = [];

		foreach ($storedPairs as $storedPair) {
			$nameKeys[VariantAttribute::normalizeName($storedPair['attributeName'])] = true;
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

		foreach ($options as $option) {
			$attribute = $attributesById[$option->attributeId] ?? null;
			$pairKey = ($attribute?->nameKey ?? '') . "\0" . $option->nameKey;

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
	 * Registry records for the given names and their values, indexed by name and then by raw value.
	 *
	 * @param array<string, list<string>> $valuesByName
	 * @return array<string, array{attribute: VariantAttribute, options: array<string, VariantAttribute>}>
	 */
	public function getRegistry(array $valuesByName): array
	{
		$names = array_map(static fn (int|string $name): string => $name, array_keys($valuesByName));
		$attributes = $this->getAttributesByNames($names);

		if ($attributes === []) {
			return [];
		}

		$attributeIds = array_map(static fn (VariantAttribute $attribute): int => (int) $attribute->id, $attributes);

		$optionsByAttributeId = [];

		$nameKeys = [];

		foreach ($valuesByName as $values) {
			foreach ($values as $value) {
				$nameKeys[VariantAttribute::normalizeName($value)] = true;
			}
		}

		$matchedOptions = VariantAttribute::find()
			->attributeId(array_values($attributeIds))
			->nameKey(array_map('strval', array_keys($nameKeys)))
			->all();

		foreach ($matchedOptions as $matchedOption) {
			$optionsByAttributeId[$matchedOption->attributeId][$matchedOption->nameKey] = $matchedOption;
		}

		$registry = [];

		foreach ($valuesByName as $name => $values) {
			$attribute = $attributes[VariantAttribute::normalizeName((string) $name)] ?? null;

			if (! $attribute instanceof VariantAttribute) {
				continue;
			}

			$options = [];

			foreach ($values as $value) {
				$option = $optionsByAttributeId[$attribute->id][VariantAttribute::normalizeName($value)] ?? null;

				if ($option instanceof VariantAttribute) {
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
	 *
	 * @return VariantQuery<int, Variant>|null
	 */
	public function variantQueryForOption(VariantAttribute $option): ?VariantQuery
	{
		$attribute = $option->getParentAttribute();

		if (! $attribute instanceof VariantAttribute) {
			return null;
		}

		$fields = $this->getVariantAttributesFields();

		if ($fields === []) {
			return null;
		}

		$params = [];
		// Ignore letter case, because the registry keys records by their lowercased name
		$condition = VariantAttributesField::pairConditionIgnoringCase($fields, $attribute->name, $option->name, $params);

		// Read every site, because a product type can be enabled only on another store's site
		return VariantManagerVariant::find()
			->status(null)
			->site('*')
			->unique()
			->andWhere($condition, $params);
	}

	/**
	 * Get how many variants store the option's attribute name and value.
	 *
	 * Cached against the variant element tag, so a variant save or delete invalidates it.
	 */
	public function variantCountForOption(VariantAttribute $option): int
	{
		return (int) Craft::$app->getCache()?->getOrSet(
			"variant-manager:option-usage:{$option->id}",
			function () use ($option): int {
				$attribute = $option->getParentAttribute();

				return $attribute instanceof VariantAttribute && $this->needsVariantScan($attribute, $option)
					? count($this->scanVariantIdsForOption($attribute, $option))
					: (int) ($this->variantQueryForOption($option)?->count() ?? 0);
			},
			null,
			new TagDependency([
				'tags' => [
					sprintf('element::%s::*', Variant::class),
					sprintf('element::%s::*', VariantManagerVariant::class),
				],
			])
		);
	}

	/**
	 * Drop the option's cached Used by count, because the count follows the option's attribute name.
	 */
	public function forgetOptionUsage(VariantAttribute $option): void
	{
		Craft::$app->getCache()?->delete("variant-manager:option-usage:{$option->id}");
	}

	public function isOptionInUse(VariantAttribute $option): bool
	{
		$attribute = $option->getParentAttribute();

		// Decide from the scan alone for names outside ASCII, so a delete agrees with the Used by count
		if ($attribute instanceof VariantAttribute && $this->needsVariantScan($attribute, $option)) {
			return $this->scanVariantIdsForOption($attribute, $option, true) !== [];
		}

		return $this->variantQueryForOption($option)?->exists() ?? false;
	}

	public function isAttributeInUse(VariantAttribute $attribute): bool
	{
		$options = VariantAttribute::find()->attributeId($attribute->id)->all();

		foreach ($options as $option) {
			if ($this->isOptionInUse($option)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every Variant Attributes field on a variant field layout.
	 *
	 * @return list<VariantAttributesField>
	 */
	public function getVariantAttributesFields(): array
	{
		if ($this->fields === null) {
			$this->fields = [];

			foreach (Craft::$app->getFields()->getLayoutsByType(Variant::class) as $fieldLayout) {
				foreach ($fieldLayout->getCustomFields() as $field) {
					if ($field instanceof VariantAttributesField) {
						$this->fields[] = $field;
					}
				}
			}
		}

		return $this->fields;
	}

	public function getVariantAttributesFieldHandle(): ?string
	{
		return ($this->getVariantAttributesFields()[0] ?? null)?->handle;
	}

	/**
	 * Creates a registry record for each name that has none yet.
	 *
	 * @param list<string> $names
	 * @return array<string, VariantAttribute>
	 * @throws Throwable
	 */
	public function ensureAttributes(array $names): array
	{
		// A trashed record keeps its unique name key, so the record is restored rather than replaced
		$attributes = $this->getAttributesByNames($names, true);

		foreach ($names as $name) {
			$name = trim($name);
			$nameKey = VariantAttribute::normalizeName($name);

			if (! $this->canRegister($nameKey)) {
				continue;
			}

			if (isset($attributes[$nameKey])) {
				$this->restoreIfTrashed($attributes[$nameKey]);
				continue;
			}

			$attribute = new VariantAttribute();
			$attribute->name = $name;
			$attribute->title = $name;

			try {
				Craft::$app->getElements()->saveElement($attribute, false);
			} catch (IntegrityException) {
				// Another process registered this name key first, so use its record
				$attribute = $this->getAttributesByNames([$name], true)[$nameKey] ?? null;

				if (! $attribute instanceof VariantAttribute) {
					continue;
				}

				$this->restoreIfTrashed($attribute);
			}

			$attributes[$nameKey] = $attribute;

			if ($this->attributesById !== null) {
				$this->attributesById[(int) $attribute->id] = $attribute;
			}
		}

		return $attributes;
	}

	/**
	 * Creates an option record for each of an attribute's values that has none yet.
	 *
	 * @param list<string> $values
	 * @throws Throwable
	 */
	public function ensureOptions(VariantAttribute $attribute, array $values): void
	{
		$nameKeys = [];

		foreach ($values as $value) {
			$nameKey = VariantAttribute::normalizeName($value);

			if ($this->canRegister($nameKey)) {
				$nameKeys[$nameKey] = $nameKey;
			}
		}

		if ($nameKeys === []) {
			return;
		}

		$options = [];

		// Filter to the given values so the query does not grow with the attribute's option count
		// A trashed record keeps its unique name key, so the record is restored rather than replaced
		$optionQuery = VariantAttribute::find()
			->attributeId($attribute->id)
			->nameKey(array_values($nameKeys))
			->trashed(null);

		/** @var array<string, VariantAttribute> $options */
		$options = $optionQuery->indexBy('nameKey')->all();

		foreach ($values as $value) {
			$nameKey = VariantAttribute::normalizeName($value);

			if (! isset($nameKeys[$nameKey])) {
				continue;
			}

			// Give a restored option its parent directly, since the attributes cache can predate the attribute's restore
			if (isset($options[$nameKey])) {
				$options[$nameKey]->setParentAttribute($attribute);
				$this->restoreIfTrashed($options[$nameKey]);
				continue;
			}

			$option = new VariantAttribute();
			$option->setParentAttribute($attribute);
			$option->name = trim($value);
			$option->title = trim($value);

			try {
				Craft::$app->getElements()->saveElement($option, false);
			} catch (IntegrityException) {
				// Another process registered this name key first, so use its record
				$option = VariantAttribute::find()
					->attributeId($attribute->id)
					->nameKey($nameKey)
					->trashed(null)
					->one();

				if (! $option instanceof VariantAttribute) {
					continue;
				}

				$option->setParentAttribute($attribute);
				$this->restoreIfTrashed($option);
			}

			$options[$nameKey] = $option;
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

		// Skip pairs already registered by this process, so a large sync only queries for new pairs
		foreach ($pairs as $pair) {
			if (! isset($this->ensuredPairKeys[self::pairKey($pair['attributeName'], $pair['attributeValue'])])) {
				$valuesByName[$pair['attributeName']][] = $pair['attributeValue'];
			}
		}

		foreach ($valuesByName as $name => $values) {
			$name = (string) $name;
			$attribute = $this->ensureAttributes([$name])[VariantAttribute::normalizeName($name)] ?? null;

			if (! $attribute instanceof VariantAttribute) {
				continue;
			}

			$values = array_values(array_unique($values));
			$this->ensureOptions($attribute, $values);

			foreach ($values as $value) {
				$this->ensuredPairKeys[self::pairKey($name, $value)] = true;
			}
		}
	}

	/**
	 * Deletes every orphaned option and attribute.
	 *
	 * @param array{attributes: list<VariantAttribute>, options: list<VariantAttribute>}|null $orphans orphans already found, to skip a second scan
	 * @return array{attributes: int, options: int}
	 * @throws Throwable
	 */
	public function pruneOrphans(int $batchSize = 500, ?array $orphans = null): array
	{
		$orphans ??= $this->findOrphans($batchSize);
		$elementsService = Craft::$app->getElements();

		$deleted = [
			'attributes' => 0,
			'options' => 0,
		];

		// Options first. Deleting an attribute deletes the options under it.
		// Hard delete the record, because a trashed one keeps its unique key and blocks re-registering the value
		foreach ($orphans['options'] as $option) {
			if ($elementsService->deleteElement($option, true)) {
				$deleted['options']++;
			}
		}

		// Deleting an attribute keeps its field set, which other attributes may use
		foreach ($orphans['attributes'] as $attribute) {
			if ($elementsService->deleteElement($attribute, true)) {
				$deleted['attributes']++;
			}
		}

		return $deleted;
	}

	public static function pairKey(string $name, string $value): string
	{
		return VariantAttribute::normalizeName($name) . "\0" . VariantAttribute::normalizeName($value);
	}

	/**
	 * Scan names outside ASCII, because the SQL lowercasing can differ from the registry's for those characters.
	 */
	private function needsVariantScan(VariantAttribute $attribute, VariantAttribute $option): bool
	{
		return preg_match('/[^\x00-\x7F]/', $attribute->name . $option->name) === 1;
	}

	/**
	 * The IDs of the variants storing the option's pair, compared as the registry compares names.
	 *
	 * @return list<int>
	 */
	private function scanVariantIdsForOption(VariantAttribute $attribute, VariantAttribute $option, bool $stopAtFirst = false): array
	{
		$pairKey = self::pairKey($attribute->name, $option->name);
		$variantIds = [];

		$variantQuery = Variant::find()->status(null)->site('*')->orderBy([
			'elements.id' => SORT_ASC,
			'elements_sites.siteId' => SORT_ASC,
		]);

		$variantBatches = $variantQuery->batch();

		foreach ($variantBatches as $variantBatch) {
			/** @var array<Variant> $variantBatch */
			foreach ($variantBatch as $variant) {
				if (isset($this->attributePairs([$variant])[$pairKey])) {
					$variantIds[(int) $variant->id] = (int) $variant->id;

					if ($stopAtFirst) {
						return array_values($variantIds);
					}
				}
			}
		}

		return array_values($variantIds);
	}

	/**
	 * Get every attribute name and value pair stored on any variant.
	 *
	 * @return array<string, array{attributeName: string, attributeValue: string}>
	 */
	private function storedPairs(int $batchSize = 500): array
	{
		$pairs = [];

		$variantBatches = Variant::find()->status(null)->site('*')->orderBy([
			'elements.id' => SORT_ASC,
			'elements_sites.siteId' => SORT_ASC,
		])->batch($batchSize);

		foreach ($variantBatches as $variantBatch) {
			/** @var array<Variant> $variantBatch */
			$pairs = [...$pairs, ...$this->attributePairs($variantBatch)];
		}

		return $pairs;
	}

	/**
	 * Skip a name longer than the registry's name columns, rather than fail the whole import or backfill.
	 */
	private function canRegister(string $nameKey): bool
	{
		if ($nameKey === '') {
			return false;
		}

		if (mb_strlen($nameKey) > 255) {
			Craft::warning("Skipped registering “{$nameKey}”, which is longer than 255 characters.", __METHOD__);

			return false;
		}

		return true;
	}

	private function restoreIfTrashed(VariantAttribute $variantAttribute): void
	{
		if (! $variantAttribute->dateDeleted instanceof \DateTime) {
			return;
		}

		Craft::$app->getElements()->restoreElement($variantAttribute);

		// Cache the restored attribute, because its options read their parent from the cache
		if (! $variantAttribute->isOption() && $this->attributesById !== null) {
			$this->attributesById[(int) $variantAttribute->id] = $variantAttribute;
		}
	}
}
