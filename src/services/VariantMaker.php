<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\commerce\collections\UpdateInventoryLevelCollection;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryUpdateQuantityType;
use craft\commerce\models\inventory\UpdateInventoryLevel;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Localization;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\models\VariantMakerPlanRow;
use fostercommerce\variantmanager\models\VariantMakerSettings;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\VariantMaker as VariantMakerRecord;
use Money\Teller;
use yii\base\Component;
use yii\db\Expression;

/**
 * Variant maker service.
 */
class VariantMaker extends Component
{
	public const MODE_ADD = 'add';

	public const MODE_UPDATE = 'update';

	public const MODE_REPLACE = 'replace';

	/**
	 * @var int the cap Commerce validates a SKU against
	 */
	public const SKU_MAX_LENGTH = 255;

	/**
	 * @var array<string, string> the properties a plan row carries as its own field
	 */
	private const NAMED_VALUES = [
		VariantMakerSettings::PROPERTY_TITLE => 'title',
		VariantMakerSettings::PROPERTY_SKU => 'sku',
		VariantMakerSettings::PROPERTY_PRICE => 'price',
	];

	/**
	 * Works out what generating the given combinations would do to a product's variants.
	 *
	 * Nothing is saved. The preview renders these rows and the commit executes them, so both read one plan.
	 *
	 * @param array<string, list<string>> $valuesByName
	 * @return list<VariantMakerPlanRow>
	 */
	public function plan(Product $product, array $valuesByName, VariantMakerSettings $settings): array
	{
		$registry = Plugin::getInstance()->getVariantAttributes()->getRegistry($valuesByName);

		if ($registry === []) {
			return [];
		}

		$valuesByName = self::registeredValues($valuesByName, $registry);

		if ($valuesByName === []) {
			return [];
		}

		$existingVariants = $this->existingVariantsByCombination($product);
		$stockByVariantId = $settings->manages(VariantMakerSettings::PROPERTY_STOCK)
			? $this->stockByVariantId($existingVariants)
			: [];
		$baseSku = $this->baseSku($product);
		$teller = $this->teller($product);
		$mode = $settings->mode;

		$title = $settings->property(VariantMakerSettings::PROPERTY_TITLE);
		$sku = $settings->property(VariantMakerSettings::PROPERTY_SKU);
		$price = $settings->property(VariantMakerSettings::PROPERTY_PRICE);
		$basePrice = self::amount($price->value ?? $product->getDefaultVariant()?->basePrice ?? 0);

		// Skip the title where the product type formats it, since our value would be overwritten on save
		$commerceOwnsTitles = self::generatesTitles($product);

		$rows = [];

		foreach ($this->combinations($valuesByName) as $combination) {
			$options = $this->combinationOptions($combination, $registry);

			$row = new VariantMakerPlanRow([
				'pairs' => $combination,
				'combinationKey' => self::combinationKey($combination),
				'title' => $commerceOwnsTitles ? null : $this->buildTitle($combination, (string) $title->value),
				'sku' => $sku->include ? $this->buildSku($combination, $options, $baseSku, (string) $sku->value) : null,
				'price' => $price->include ? $this->buildPrice($options, $basePrice, $teller) : null,
			]);

			$variant = $existingVariants[$row->combinationKey] ?? null;
			$row->status = $this->statusFor($row, $variant, $settings, $teller, $stockByVariantId);
			$row->properties = self::propertiesFor($settings, $row->status);

			// A value the run would not write must not read as a change in the preview
			foreach (self::NAMED_VALUES as $propertyName => $field) {
				if (! self::writes($settings, $propertyName, $row->status)) {
					$row->{$field} = null;
				}
			}

			// Price is compared by amount, so 162 and 162.00 would otherwise render as a change
			if ($row->price !== null && $row->currentPrice !== null && $teller->compare($row->price, $row->currentPrice) === 0) {
				$row->price = null;
			}

			$rows[] = $row;
			unset($existingVariants[$row->combinationKey]);
		}

		$this->flagSkuIssues($rows, $product);

		if ($mode !== self::MODE_REPLACE) {
			return $rows;
		}

		// Replace removes every existing variant the generated combinations did not cover
		foreach ($existingVariants as $combinationKey => $variant) {
			$rows[] = new VariantMakerPlanRow([
				'pairs' => $this->variantPairs($variant),
				'combinationKey' => $combinationKey,
				'status' => VariantMakerPlanRow::STATUS_DELETE,
				'variantId' => $variant->id,
				'currentSku' => $variant->sku,
				'currentTitle' => $variant->title,
				'currentPrice' => (string) $variant->basePrice,
			]);
		}

		return $rows;
	}

	/**
	 * Executes a plan, reusing the importer's save order so both paths write variants the same way.
	 *
	 * @return array{created: int, updated: int, deleted: int}
	 * @throws \Throwable
	 */
	public function generate(Product $product, VariantMakerSettings $settings): array
	{
		$rows = $this->plan($product, $this->selectionFromRows($settings->rows), $settings);
		$counts = [
			'created' => 0,
			'updated' => 0,
			'deleted' => 0,
		];

		$elementsService = Craft::$app->getElements();
		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			$written = [];
			$removedIds = [];
			$pendingStock = [];

			foreach ($rows as $row) {
				$variant = $this->variantForRow($row);

				// Another save may have removed the variant between the plan and this run
				if ($variant === null) {
					continue;
				}

				if ($row->status === VariantMakerPlanRow::STATUS_DELETE) {
					$elementsService->deleteElement($variant);
					$removedIds[] = (int) $variant->id;
					$counts['deleted']++;
					continue;
				}

				if ($row->status === VariantMakerPlanRow::STATUS_UNCHANGED) {
					continue;
				}

				$this->applyRow($variant, $row);
				$written[] = $variant;

				if (isset($row->properties[VariantMakerSettings::PROPERTY_STOCK])) {
					$pendingStock[] = [$variant, (int) $row->properties[VariantMakerSettings::PROPERTY_STOCK]];
				}

				$counts[$row->status === VariantMakerPlanRow::STATUS_CREATE ? 'created' : 'updated']++;
			}

			if ($written !== [] || $removedIds !== []) {
				// Commerce rebuilds the product's default variant from whatever it is given, so hand it every variant
				Plugin::getInstance()->csv->saveVariants($product, $this->wholeVariantSet($product, $written, $removedIds));
			}

			// Inventory items only exist once the variant is saved
			// Keep these writes inside the transaction. Our rollback still reverses the inventory rows, since Yii nests Commerce's own transaction as a savepoint.
			foreach ($pendingStock as [$variant, $quantity]) {
				$this->setStock($variant, $quantity, $settings->inventoryLocationId);
			}

			$transaction->commit();
		} catch (\Throwable $throwable) {
			$transaction->rollBack();
			throw $throwable;
		}

		return $counts;
	}

	/**
	 * The builder as the product form posted it, so a failed save redraws what the merchant had rather than
	 * what is stored.
	 */
	public function postedSettings(): ?VariantMakerSettings
	{
		$request = Craft::$app->getRequest();

		if ($request->getIsConsoleRequest()) {
			return null;
		}

		$postedSettings = $request->getBodyParam('variantMaker');

		return is_array($postedSettings) ? VariantMakerSettings::fromPost($postedSettings) : null;
	}

	public function getSettings(Product $product): VariantMakerSettings
	{
		$record = VariantMakerRecord::findOne([
			'productId' => $product->getCanonicalId(),
		]);

		$settings = VariantMakerSettings::fromJson($record->settings ?? null);
		$settings->forgetMissing($this->registeredIds($settings));

		return $settings;
	}

	/**
	 * The saved rows with their elements loaded, falling back to the attributes the product already uses.
	 *
	 * @return list<array{attribute: VariantAttribute, options: list<VariantAttribute>}>
	 */
	public function settingsRows(Product $product, VariantMakerSettings $settings): array
	{
		if ($settings->rows === []) {
			return array_map(
				static fn (VariantAttribute $attribute): array => [
					'attribute' => $attribute,
					'options' => [],
				],
				$this->attributesInUse($product),
			);
		}

		$elementsById = [];

		foreach (VariantAttribute::find()->id(self::idsIn($settings))->all() as $element) {
			$elementsById[$element->id] = $element;
		}

		$rows = [];

		foreach ($settings->rows as $row) {
			$attribute = $elementsById[$row['attributeId']] ?? null;

			if ($attribute instanceof VariantAttribute) {
				$rows[] = [
					'attribute' => $attribute,
					'options' => array_values(array_filter(array_map(
						static fn (int $optionId): ?VariantAttribute => $elementsById[$optionId] ?? null,
						$row['optionIds'],
					))),
				];
			}
		}

		return $rows;
	}

	public function saveSettings(Product $product, VariantMakerSettings $settings): void
	{
		$productId = $product->getCanonicalId();

		$record = VariantMakerRecord::findOne([
			'productId' => $productId,
		]) ?? new VariantMakerRecord([
			'productId' => $productId,
		]);

		$record->settings = $settings->toJson();
		$record->save(false);
	}

	/**
	 * Attributes the product's variants already store, so the form opens on what this product actually uses.
	 *
	 * @return list<VariantAttribute>
	 */
	public function attributesInUse(Product $product): array
	{
		$names = [];

		foreach (Variant::find()->product($product)->status(null)->all() as $variant) {
			foreach ($this->variantPairs($variant) as $pair) {
				$names[$pair['attributeName']] = true;
			}
		}

		return array_values(Plugin::getInstance()->getVariantAttributes()->getAttributesByNames(array_keys($names)));
	}

	/**
	 * Turns the builder's rows into the name and value map the planner takes.
	 *
	 * @param list<array{attributeId: int, optionIds: list<int>}> $settingsRows in the order their SKU partials assemble
	 * @return array<string, list<string>>
	 */
	public function selectionFromRows(array $settingsRows): array
	{
		$attributeIds = array_column($settingsRows, 'attributeId');
		$optionIdsByAttributeId = array_column($settingsRows, 'optionIds', 'attributeId');

		if ($attributeIds === []) {
			return [];
		}

		$rowsById = [];

		foreach (VariantAttribute::find()->id($attributeIds)->all() as $attribute) {
			$rowsById[$attribute->id] = $attribute;
		}

		$optionIds = array_merge(...array_values($optionIdsByAttributeId));

		$optionsById = [];

		foreach ($optionIds === [] ? [] : VariantAttribute::find()->id($optionIds)->all() as $option) {
			$optionsById[$option->id] = $option;
		}

		$valuesByName = [];

		foreach ($attributeIds as $attributeId) {
			$attribute = $rowsById[$attributeId] ?? null;

			if ($attribute === null) {
				continue;
			}

			$values = [];

			foreach ($optionIdsByAttributeId[$attributeId] ?? [] as $optionId) {
				$option = $optionsById[$optionId] ?? null;

				if ($option instanceof VariantAttribute && $option->attributeId === $attributeId) {
					$values[] = $option->name;
				}
			}

			if ($values !== []) {
				$valuesByName[$attribute->name] = $values;
			}
		}

		return $valuesByName;
	}

	/**
	 * Builds the combination key a variant is matched on, independent of the order its pairs are stored in.
	 *
	 * @param list<array{attributeName: string, attributeValue: string}> $pairs
	 */
	public static function combinationKey(array $pairs): string
	{
		$normalized = [];

		foreach ($pairs as $pair) {
			$normalized[] = VariantAttribute::normalizeName($pair['attributeName']) . "\0" . VariantAttribute::normalizeName($pair['attributeValue']);
		}

		sort($normalized);

		return implode('|', $normalized);
	}

	/**
	 * Whether Commerce builds variant titles itself, leaving nothing for the maker to set.
	 */
	public static function generatesTitles(Product $product): bool
	{
		$productType = $product->getType();

		return ! $productType->hasVariantTitleField && $productType->variantTitleFormat !== '';
	}

	/**
	 * @param list<Variant> $written
	 * @param list<int> $removedIds
	 * @return list<Variant>
	 */
	private function wholeVariantSet(Product $product, array $written, array $removedIds): array
	{
		$writtenIds = array_filter(array_map(static fn (Variant $variant): ?int => $variant->id, $written));
		$untouched = array_flip([...$writtenIds, ...$removedIds]);

		$variants = $written;

		foreach (Variant::find()->product($product)->status(null)->all() as $variant) {
			if (! isset($untouched[(int) $variant->id])) {
				$variants[] = $variant;
			}
		}

		return $variants;
	}

	/**
	 * Whether the run would write this property to a row of the given status.
	 */
	private static function writes(VariantMakerSettings $settings, string $propertyName, string $status): bool
	{
		if ($status === VariantMakerPlanRow::STATUS_CREATE) {
			// A new variant is always titled, whether or not the maker owns titles
			return $propertyName === VariantMakerSettings::PROPERTY_TITLE
				|| $settings->manages($propertyName);
		}

		return $status === VariantMakerPlanRow::STATUS_UPDATE && $settings->manages($propertyName);
	}

	/**
	 * The property values the run would write on a row, given what the maker was told to manage.
	 *
	 * @return array<string, bool|int|null>
	 */
	private static function propertiesFor(VariantMakerSettings $settings, string $status): array
	{
		$properties = [];

		foreach (VariantMakerSettings::propertyNames() as $propertyName) {
			// A row holds its own title, SKU and price, built from the formats these values are
			if (isset(self::NAMED_VALUES[$propertyName])) {
				continue;
			}

			if (self::writes($settings, $propertyName, $status)) {
				$properties[$propertyName] = $settings->property($propertyName)->value;
			}
		}

		return $properties;
	}

	/**
	 * Write stock as an inventory update, since Commerce derives a purchasable's stock from its levels.
	 */
	private function setStock(Variant $variant, int $quantity, ?int $inventoryLocationId): void
	{
		$updates = [];

		foreach ($variant->getInventoryLevels() as $inventoryLevel) {
			$inventoryLocation = $inventoryLevel->getInventoryLocation();

			if ($inventoryLocationId !== null && $inventoryLocation->id !== $inventoryLocationId) {
				continue;
			}

			$updates[] = new UpdateInventoryLevel([
				'type' => 'onHand',
				'updateAction' => InventoryUpdateQuantityType::SET,
				'inventoryItem' => $inventoryLevel->getInventoryItem(),
				'inventoryLocation' => $inventoryLocation,
				'quantity' => $quantity,
				'note' => Craft::t('variant-manager', 'variantMaker.stockNote'),
			]);
		}

		if ($updates !== []) {
			Commerce::getInstance()->getInventory()->executeUpdateInventoryLevels(UpdateInventoryLevelCollection::make($updates));
		}
	}

	private function variantForRow(VariantMakerPlanRow $row): ?Variant
	{
		if ($row->variantId === null) {
			return new Variant();
		}

		$variant = Variant::find()->id($row->variantId)->status(null)->one();

		return $variant instanceof Variant ? $variant : null;
	}

	private function applyRow(Variant $variant, VariantMakerPlanRow $row): void
	{
		if ($row->title !== null) {
			$variant->title = $row->title;
		}

		if ($row->sku !== null) {
			$variant->sku = $row->sku;
		}

		if ($row->price !== null) {
			$variant->basePrice = (float) $row->price;
		}

		foreach ($row->properties as $propertyName => $value) {
			if ($propertyName !== VariantMakerSettings::PROPERTY_STOCK) {
				$variant->{$propertyName} = $value;
			}
		}

		// Each product type names its own field, so the variant's layout is what says where the pairs go
		$field = FieldHelper::getFirstVariantAttributesField($variant->getFieldLayout());

		if ($field !== null) {
			$variant->setFieldValue($field->handle, $row->pairs);
		}
	}

	/**
	 * A row is an update only where a property the maker owns for existing variants would actually change one.
	 *
	 * @phpstan-return VariantMakerPlanRow::STATUS_CREATE|VariantMakerPlanRow::STATUS_UPDATE|VariantMakerPlanRow::STATUS_UNCHANGED
	 */
	private function statusFor(VariantMakerPlanRow $row, ?Variant $variant, VariantMakerSettings $settings, Teller $teller, array $stockByVariantId): string
	{
		if ($variant === null) {
			return VariantMakerPlanRow::STATUS_CREATE;
		}

		$row->variantId = $variant->id;
		$row->currentSku = $variant->sku;
		$row->currentTitle = $variant->title;
		$row->currentPrice = (string) $variant->basePrice;

		if (! $settings->updatesExisting()) {
			return VariantMakerPlanRow::STATUS_UNCHANGED;
		}

		return $this->changesVariant($row, $variant, $settings, $teller, $stockByVariantId)
			? VariantMakerPlanRow::STATUS_UPDATE
			: VariantMakerPlanRow::STATUS_UNCHANGED;
	}

	/**
	 * @param array<int, int> $stockByVariantId
	 */
	private function changesVariant(VariantMakerPlanRow $row, Variant $variant, VariantMakerSettings $settings, Teller $teller, array $stockByVariantId): bool
	{
		if ($settings->manages(VariantMakerSettings::PROPERTY_TITLE) && $row->title !== $row->currentTitle) {
			return true;
		}

		if ($settings->manages(VariantMakerSettings::PROPERTY_SKU) && $row->sku !== $row->currentSku) {
			return true;
		}

		if ($settings->manages(VariantMakerSettings::PROPERTY_PRICE) && $row->price !== null && $teller->compare($row->price, $row->currentPrice) !== 0) {
			return true;
		}

		foreach (VariantMakerSettings::propertyNames() as $propertyName) {
			if (isset(self::NAMED_VALUES[$propertyName]) || ! $settings->manages($propertyName)) {
				continue;
			}

			$value = $settings->property($propertyName)->value;

			$current = $propertyName === VariantMakerSettings::PROPERTY_STOCK
				? ($stockByVariantId[$variant->id] ?? 0)
				: $variant->{$propertyName};

			if ($current !== $value) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads every variant's stock in one query, since Commerce derives it per purchasable from inventory levels.
	 *
	 * @param array<string, Variant> $existingVariants
	 * @return array<int, int>
	 */
	private function stockByVariantId(array $existingVariants): array
	{
		$variantIds = array_values(array_filter(array_map(
			static fn (Variant $variant): ?int => $variant->id,
			$existingVariants,
		)));

		if ($variantIds === []) {
			return [];
		}

		$stockByVariantId = array_fill_keys($variantIds, 0);

		$levels = Commerce::getInstance()->getInventory()->getInventoryLevelQuery()
			->andWhere([
				'ii.purchasableId' => $variantIds,
			])
			->all();

		foreach ($levels as $level) {
			// Commerce counts only positive availability toward stock, and sums it across locations
			$available = (int) $level['availableTotal'];

			if ($available > 0) {
				$stockByVariantId[(int) $level['purchasableId']] += $available;
			}
		}

		return $stockByVariantId;
	}

	/**
	 * The option elements behind one combination, keyed by attribute name, skipping values with no registry row.
	 *
	 * @param list<array{attributeName: string, attributeValue: string}> $combination
	 * @param array<string, array{attribute: VariantAttribute, options: array<string, VariantAttribute>}> $registry
	 * @return array<string, VariantAttribute>
	 */
	private function combinationOptions(array $combination, array $registry): array
	{
		$options = [];

		foreach ($combination as $pair) {
			$option = $registry[$pair['attributeName']]['options'][$pair['attributeValue']] ?? null;

			if ($option instanceof VariantAttribute) {
				$options[$pair['attributeName']] = $option;
			}
		}

		return $options;
	}

	/**
	 * @param list<array{attributeName: string, attributeValue: string}> $combination
	 * @param array<string, VariantAttribute> $options
	 */
	private function buildSku(array $combination, array $options, string $baseSku, string $skuFormat): string
	{
		// An option with no SKU partial contributes its own value instead
		$partials = [];

		foreach ($combination as $pair) {
			$attributeName = $pair['attributeName'];
			$skuPartial = $options[$attributeName]->skuPartial;
			$partials[$attributeName] = ($skuPartial ?? '') === '' ? $pair['attributeValue'] : $skuPartial;
		}

		if (trim($skuFormat) === '') {
			$segments = array_map(self::skuSegment(...), [$baseSku, ...array_values($partials)]);

			return implode('-', array_filter($segments, static fn (string $segment): bool => $segment !== ''));
		}

		$tokens = [];

		foreach ($partials as $attributeName => $partial) {
			$tokens['{' . $attributeName . '}'] = $partial;
		}

		return strtr($skuFormat, $tokens);
	}

	/**
	 * Drops the values no registry row backs, since a combination is built from each value's option.
	 *
	 * @param array<string, list<string>> $valuesByName
	 * @param array<string, array{attribute: VariantAttribute, options: array<string, VariantAttribute>}> $registry
	 * @return array<string, list<string>>
	 */
	private static function registeredValues(array $valuesByName, array $registry): array
	{
		$registered = [];

		foreach ($valuesByName as $attributeName => $values) {
			$options = $registry[$attributeName]['options'] ?? [];
			$values = array_values(array_filter($values, static fn (string $value): bool => isset($options[$value])));

			if ($values !== []) {
				$registered[$attributeName] = $values;
			}
		}

		return $registered;
	}

	/**
	 * A price typed into the control panel carries the locale's separators, which Money's parser rejects.
	 */
	private static function amount(float|int|string $value): string
	{
		return (string) (Localization::normalizeNumber($value) ?: '0');
	}

	/**
	 * Nobody typed a SKU in the default format, so whitespace here comes from the option name.
	 */
	private static function skuSegment(string $value): string
	{
		return trim((string) preg_replace('/[\s-]+/u', '-', trim($value)), '-');
	}

	/**
	 * Marks the rows Commerce would reject, since one failure rolls the whole run back.
	 *
	 * @param list<VariantMakerPlanRow> $rows
	 */
	private function flagSkuIssues(array $rows, Product $product): void
	{
		$rowsByLowercasedSku = [];

		foreach ($rows as $row) {
			if ($row->sku !== null) {
				$rowsByLowercasedSku[mb_strtolower($row->sku)][] = $row;
			}
		}

		$plannedIds = array_filter(array_map(static fn (VariantMakerPlanRow $row): ?int => $row->variantId, $rows));
		$keptSkus = $this->keptSkus($product, $plannedIds);
		$takenElsewhere = $this->skusTakenElsewhere(array_keys($rowsByLowercasedSku), $plannedIds);

		foreach ($rowsByLowercasedSku as $lowercasedSku => $sharingRows) {
			$isDuplicate = count($sharingRows) > 1 || isset($keptSkus[$lowercasedSku]);

			foreach ($sharingRows as $row) {
				if ($isDuplicate) {
					$row->skuIssue = Craft::t('variant-manager', 'variantMaker.skuDuplicate');
				} elseif (isset($takenElsewhere[$lowercasedSku])) {
					$row->skuIssue = Craft::t('variant-manager', 'variantMaker.skuTaken');
				} elseif (mb_strlen((string) $row->sku) > self::SKU_MAX_LENGTH) {
					$row->skuIssue = Craft::t('variant-manager', 'variantMaker.skuTooLong', [
						'max' => self::SKU_MAX_LENGTH,
					]);
				}
			}
		}
	}

	/**
	 * The SKUs of the product's own variants no plan row covers, since Commerce rejects a repeat within one product.
	 *
	 * @param list<int> $plannedIds
	 * @return array<string, true>
	 */
	private function keptSkus(Product $product, array $plannedIds): array
	{
		$planned = array_flip($plannedIds);
		$keptSkus = [];

		foreach (Variant::find()->product($product)->status(null)->all() as $variant) {
			if (! isset($planned[(int) $variant->id])) {
				$keptSkus[mb_strtolower((string) $variant->sku)] = true;
			}
		}

		return $keptSkus;
	}

	/**
	 * @param list<string> $lowercasedSkus lowercased, since Commerce compares SKUs case insensitively
	 * @param list<int> $plannedIds
	 * @return array<string, true>
	 */
	private function skusTakenElsewhere(array $lowercasedSkus, array $plannedIds): array
	{
		if ($lowercasedSkus === []) {
			return [];
		}

		// Read the rows Commerce validates against: no revisions, no drafts, nothing trashed
		$query = (new Query())
			->select(['[[purchasables.sku]]'])
			->from([
				'purchasables' => CommerceTable::PURCHASABLES,
			])
			->innerJoin([
				'elements' => CraftTable::ELEMENTS,
			], '[[elements.id]] = [[purchasables.id]]')
			->where([
				'[[elements.revisionId]]' => null,
				'[[elements.draftId]]' => null,
				'[[elements.dateDeleted]]' => null,
			])
			->andWhere([
				'in',
				new Expression('LOWER([[purchasables.sku]])'),
				$lowercasedSkus,
			]);

		// A plan row rewrites its own variant, so that variant's current SKU is not taken
		if ($plannedIds !== []) {
			$query->andWhere([
				'not',
				[
					'[[purchasables.id]]' => $plannedIds,
				],
			]);
		}

		return array_fill_keys(array_map('mb_strtolower', $query->column()), true);
	}

	/**
	 * @param list<array{attributeName: string, attributeValue: string}> $combination
	 */
	private function buildTitle(array $combination, string $titleFormat): string
	{
		$values = array_map(static fn (array $pair): string => $pair['attributeValue'], $combination);

		if (trim($titleFormat) === '') {
			return implode(' / ', $values);
		}

		$tokens = [];

		foreach ($combination as $pair) {
			$tokens['{' . $pair['attributeName'] . '}'] = $pair['attributeValue'];
		}

		return strtr($titleFormat, $tokens);
	}

	/**
	 * @param array<string, VariantAttribute> $options
	 */
	private function buildPrice(array $options, string $basePrice, Teller $teller): string
	{
		$price = $basePrice;

		foreach ($options as $option) {
			if ($option->priceModifier !== null) {
				$price = $teller->add($price, self::amount($option->priceModifier));
			}
		}

		return $price;
	}

	/**
	 * Every combination of the selected values, one entry per attribute in the order given.
	 *
	 * @param array<string, list<string>> $valuesByName
	 * @return list<list<array{attributeName: string, attributeValue: string}>>
	 */
	private function combinations(array $valuesByName): array
	{
		$combinations = [[]];

		foreach ($valuesByName as $attributeName => $values) {
			$extended = [];

			foreach ($combinations as $combination) {
				foreach ($values as $attributeValue) {
					$extended[] = [...$combination, [
						'attributeName' => (string) $attributeName,
						'attributeValue' => $attributeValue,
					]];
				}
			}

			$combinations = $extended;
		}

		return $combinations;
	}

	/**
	 * @return array<string, Variant>
	 */
	private function existingVariantsByCombination(Product $product): array
	{
		$variants = [];

		foreach (Variant::find()->product($product)->status(null)->all() as $variant) {
			$combinationKey = self::combinationKey($this->variantPairs($variant));

			// Two variants can share a combination, and only the first is the one a row updates
			if (! isset($variants[$combinationKey])) {
				$variants[$combinationKey] = $variant;
			}
		}

		return $variants;
	}

	/**
	 * @return list<array{attributeName: string, attributeValue: string}>
	 */
	private function variantPairs(Variant $variant): array
	{
		$fieldHandle = FieldHelper::getFirstVariantAttributesField($variant->getFieldLayout())?->handle;

		if ($fieldHandle === null) {
			return [];
		}

		$storedAttributes = $variant->{$fieldHandle};

		// Treat an unparseable value as no pairs, since the field stores JSON
		if (! is_array($storedAttributes)) {
			return [];
		}

		$pairs = [];

		foreach ($storedAttributes as $pair) {
			if (is_string($pair['attributeName'] ?? null) && is_string($pair['attributeValue'] ?? null)) {
				$pairs[] = [
					'attributeName' => $pair['attributeName'],
					'attributeValue' => $pair['attributeValue'],
				];
			}
		}

		return $pairs;
	}

	/**
	 * Every attribute and option ID the settings name, whether or not the row still exists.
	 *
	 * @return list<int>
	 */
	private static function idsIn(VariantMakerSettings $settings): array
	{
		$ids = [];

		foreach ($settings->rows as $row) {
			$ids[] = $row['attributeId'];
			$ids = [...$ids, ...$row['optionIds']];
		}

		return $ids;
	}

	/**
	 * @return list<int>
	 */
	private function registeredIds(VariantMakerSettings $settings): array
	{
		$ids = self::idsIn($settings);

		if ($ids === []) {
			return [];
		}

		return array_map(
			static fn (VariantAttribute $attribute): int => (int) $attribute->id,
			VariantAttribute::find()->id($ids)->all(),
		);
	}

	private function baseSku(Product $product): string
	{
		return $product->getDefaultVariant()?->sku ?? (string) $product->slug;
	}

	private function teller(Product $product): Teller
	{
		$currencyIso = Commerce::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso($product->storeId);

		return Commerce::getInstance()->getCurrencies()->getTeller($currencyIso);
	}
}
