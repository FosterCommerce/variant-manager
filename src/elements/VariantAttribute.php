<?php

namespace fostercommerce\variantmanager\elements;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\actions\Delete;
use craft\elements\actions\Duplicate;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\db\VariantAttributeQuery;
use fostercommerce\variantmanager\enums\DisplayType;
use fostercommerce\variantmanager\helpers\PermissionHelper;
use fostercommerce\variantmanager\models\FieldSet;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use fostercommerce\variantmanager\records\VariantAttribute as VariantAttributeRecord;
use yii\base\InvalidConfigException;

/**
 * A registry record for one attribute name, or for one of its option values.
 *
 * Variants store the name and value as strings, so deleting a record does not change a variant.
 *
 * @property-read null|VariantAttribute $parentAttribute
 * @property-read list<VariantAttribute> $options
 */
class VariantAttribute extends Element
{
	public int $attributeId = 0;

	public string $name = '';

	public string $nameKey = '';

	public string $displayType = DisplayType::Dropdown->value;

	public ?string $skuPartial = null;

	public ?float $priceModifier = null;

	public ?string $fieldSetUid = null;

	private ?VariantAttribute $parentAttribute = null;

	/**
	 * @var list<self>|null
	 */
	private ?array $options = null;

	public function init(): void
	{
		parent::init();

		// Set the default for a new attribute. Yii configures a queried row before init().
		if ($this->id === null) {
			$this->displayType = Plugin::getInstance()->getSettings()->getDefaultDisplayType()->value;
		}
	}

	public static function displayName(): string
	{
		return Craft::t('variant-manager', 'attributes.attribute');
	}

	/**
	 * One class covers both an attribute and an option, so neither noun fits the generated "Create {type}".
	 */
	public static function lowerDisplayName(): string
	{
		return Craft::t('variant-manager', 'attributes.recordLower');
	}

	public static function pluralDisplayName(): string
	{
		return Craft::t('variant-manager', 'attributes.attributes');
	}

	public static function pluralLowerDisplayName(): string
	{
		return Craft::t('variant-manager', 'attributes.recordsLower');
	}

	public static function refHandle(): ?string
	{
		return 'variantattribute';
	}

	public static function hasTitles(): bool
	{
		return true;
	}

	public static function hasStructure(): bool
	{
		return true;
	}

	public static function isLocalized(): bool
	{
		// The table has no siteId column, so per-site rows would be identical
		return false;
	}

	/**
	 * @return VariantAttributeQuery<int, self>
	 */
	public static function find(): VariantAttributeQuery
	{
		/** @var VariantAttributeQuery<int, self> $query */
		$query = new VariantAttributeQuery(static::class);

		return $query;
	}

	public static function normalizeName(string $name): string
	{
		return StringHelper::toLowerCase(trim($name));
	}

	public function isOption(): bool
	{
		return $this->attributeId !== 0;
	}

	/**
	 * @return list<self>
	 */
	public function getOptions(): array
	{
		if ($this->isOption()) {
			return [];
		}

		return $this->options ??= self::find()
			->attributeId($this->id)
			->all();
	}

	/**
	 * @param list<self> $options
	 */
	public function setOptions(array $options): void
	{
		$this->options = $options;
	}

	public function getParentAttribute(): ?self
	{
		if (! $this->isOption()) {
			return null;
		}

		return $this->parentAttribute ?? Plugin::getInstance()->getVariantAttributes()->getAttributeById($this->attributeId);
	}

	/**
	 * getAttributeById() can miss an attribute created in the same request.
	 */
	public function setParentAttribute(self $attribute): void
	{
		$this->attributeId = (int) $attribute->id;
		$this->parentAttribute = $attribute;
	}

	public function getFieldLayout(): ?FieldLayout
	{
		$fieldSets = Plugin::getInstance()->getFieldSets();

		$fieldSetUid = $this->isOption() ? $this->getParentAttribute()?->fieldSetUid : $this->fieldSetUid;
		// Never return null. An attribute with no field set still renders in the element editor.
		$fieldSet = $fieldSets->getFieldSetByUid($fieldSetUid) ?? new FieldSet();

		return $this->isOption() ? $fieldSet->getOptionFieldLayout() : $fieldSet->getFieldLayout();
	}

	public function getDisplayType(): DisplayType
	{
		return DisplayType::tryFrom($this->displayType) ?? DisplayType::Dropdown;
	}

	public function getPostEditUrl(): ?string
	{
		return UrlHelper::cpUrl('variant-manager/attributes');
	}

	public function canView(User $user): bool
	{
		return PermissionHelper::canSaveAnyProductType($user);
	}

	public function canSave(User $user): bool
	{
		return PermissionHelper::canSaveAnyProductType($user);
	}

	/**
	 * A uid the project config no longer has resolves to an empty field set, so the record renders with no custom fields.
	 */
	public function validateFieldSetUid(string $attribute): void
	{
		if ($this->fieldSetUid !== null && ! Plugin::getInstance()->getFieldSets()->getFieldSetByUid($this->fieldSetUid) instanceof FieldSet) {
			$this->addError($attribute, Craft::t('variant-manager', 'fieldSets.notFound'));
		}
	}

	/**
	 * An option whose attributeId names no top-level attribute would have no parent and no field layout.
	 */
	public function validateAttributeId(string $attribute): void
	{
		if ($this->attributeId !== 0 && ! Plugin::getInstance()->getVariantAttributes()->getAttributeById($this->attributeId) instanceof self) {
			$this->addError($attribute, Craft::t('variant-manager', 'attributes.notFound'));
		}
	}

	/**
	 * The registry's attributeId and nameKey index is unique, so a duplicate fails on insert.
	 */
	public function nameTakenUnder(int $attributeId): bool
	{
		$nameKey = self::normalizeName($this->resolvedName());

		if ($nameKey === '') {
			return false;
		}

		$query = self::find()
			->attributeId($attributeId)
			->nameKey(Db::escapeParam($nameKey))
			->status(null)
			// A trashed record keeps its nameKey, so the unique index still rejects a duplicate
			->trashed(null);

		$canonicalId = $this->getCanonicalId();

		if ($canonicalId !== null) {
			$query->id("not {$canonicalId}");
		}

		return $query->exists();
	}

	public function validateNameNotTaken(): void
	{
		if (! $this->nameTakenUnder($this->attributeId)) {
			return;
		}

		$name = $this->resolvedName();

		$this->addError('name', $this->isOption()
			? Craft::t('variant-manager', 'options.nameTaken', [
				'name' => $name,
				'attribute' => (string) $this->getParentAttribute()?->name,
			])
			: Craft::t('variant-manager', 'attributes.nameTaken', [
				'name' => $name,
			]));
	}

	/**
	 * Run the in-use check in beforeDelete(), because a variant query per record would slow the element index.
	 *
	 * TODO: move it into deletionBlockers() once the plugin requires Craft 5.10, so the confirmation message states the reason.
	 */
	public function canDelete(User $user): bool
	{
		return PermissionHelper::canSaveAnyProductType($user);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function afterMoveInStructure(int $structureId): void
	{
		// Read the parent from the structure table. m260914 moves options before the fieldSetUid column exists.
		$attributeId = (int) (new Query())
			->select(['elementId'])
			->from(CraftTable::STRUCTUREELEMENTS)
			->where([
				'structureId' => $structureId,
			])
			->andWhere(['<', 'lft', $this->lft])
			->andWhere(['>', 'rgt', $this->rgt])
			->orderBy([
				'lft' => SORT_DESC,
			])
			->scalar();

		if ($attributeId !== $this->attributeId) {
			$this->attributeId = $attributeId;

			// Update the drafts and revisions too, because applying a draft writes its row back over the canonical record
			Db::update(Table::ATTRIBUTES, [
				'attributeId' => $attributeId,
			], [
				'id' => (new Query())
					->select(['id'])
					->from(CraftTable::ELEMENTS)
					->where([
						'or',
						[
							'id' => $this->id,
						],
						[
							'canonicalId' => $this->id,
						],
					]),
			]);
		}

		parent::afterMoveInStructure($structureId);
	}

	public function afterSave(bool $isNew): void
	{
		if (! $this->propagating) {
			if ($isNew) {
				$record = new VariantAttributeRecord();
				$record->id = (int) $this->id;
			} else {
				$record = VariantAttributeRecord::findOne($this->id);

				if (! $record instanceof VariantAttributeRecord) {
					throw new InvalidConfigException("Invalid variant attribute ID: {$this->id}");
				}
			}

			$isDraftOrRevision = ElementHelper::isDraftOrRevision($this);

			$record->attributeId = $this->attributeId;
			$record->name = $this->name;
			// A draft needs its own row for the element query's inner join, and the unique index rejects a shared key
			$record->nameKey = (string) ($isDraftOrRevision ? $this->uid : $this->nameKey);
			$record->displayType = $this->displayType;
			$record->skuPartial = $this->skuPartial;
			$record->priceModifier = $this->priceModifier;
			$record->fieldSetUid = $this->fieldSetUid;
			$record->save(false);

			// Place a canonical record once, because an unpublished draft keeps its id through the apply
			if ($isNew && $this->getIsCanonical()) {
				$this->placeInStructure();
			}

			// Compare against the structure, because applying a draft clears the dirty attributes attributeId would be in
			if (! $isNew && $this->getIsCanonical() && $this->isOption()) {
				$placedUnder = self::find()->ancestorOf($this)->ancestorDist(1)->status(null)->one();

				if (! $placedUnder instanceof self || (int) $placedUnder->id !== $this->attributeId) {
					$this->parentAttribute = null;
					$this->placeInStructure();
				}
			}

			// Applying a draft keeps its id, so isNew is false on the save that creates the attribute
			if (! $isDraftOrRevision && $this->firstSave) {
				$this->logCreation();
			}
		}

		parent::afterSave($isNew);
	}

	public function beforeDelete(): bool
	{
		if (! parent::beforeDelete()) {
			return false;
		}

		// The in-use checks match on the name a draft shares with its canonical record
		if (ElementHelper::isDraftOrRevision($this)) {
			return true;
		}

		// Hard delete the record, because a trashed one keeps its unique key and blocks re-registering the value
		$this->hardDelete = true;

		$variantAttributes = Plugin::getInstance()->getVariantAttributes();

		if ($this->isOption()) {
			if ($variantAttributes->isOptionInUse($this)) {
				$this->addError('name', Craft::t('variant-manager', 'options.deleteInUse'));
				return false;
			}

			return true;
		}

		if ($variantAttributes->isAttributeInUse($this)) {
			$this->addError('name', Craft::t('variant-manager', 'attributes.deleteInUse'));
			return false;
		}

		// Include already-trashed options, because their rows reference an attribute this delete removes
		$options = self::find()
			->attributeId($this->id)
			->trashed(null)
			->all();

		$elementsService = Craft::$app->getElements();

		foreach ($options as $option) {
			$elementsService->deleteElement($option, true);
		}

		return true;
	}

	public function beforeSave(bool $isNew): bool
	{
		$this->structureId = Plugin::getInstance()->getVariantAttributes()->getStructureId();
		$this->name = $this->resolvedName();
		$this->nameKey = self::normalizeName($this->name);

		return parent::beforeSave($isNew);
	}

	/**
	 * Drop the Duplicate action. A copy would collide on the unique attributeId and nameKey index.
	 */
	public static function actions(string $source): array
	{
		return array_values(array_filter(
			parent::actions($source),
			static fn (mixed $action): bool => $action !== Duplicate::class
		));
	}

	/**
	 * Hard-delete records, matching beforeDelete(), so the confirmation message reads as a permanent delete.
	 *
	 * @return list<array{type: class-string, hard: bool}>
	 */
	protected static function defineActions(string $source): array
	{
		return [
			[
				'type' => Delete::class,
				'hard' => true,
			],
		];
	}

	protected function cpEditUrl(): ?string
	{
		return "variant-manager/attributes/{$this->getCanonicalId()}";
	}

	protected function uiLabel(): ?string
	{
		// A record still being created has neither a display name nor a system name
		$displayName = $this->resolvedDisplayName();

		return $displayName === '' ? null : $displayName;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function crumbs(): array
	{
		$crumbs = [
			[
				'label' => Craft::t('variant-manager', 'plugin.name'),
				'url' => UrlHelper::cpUrl('variant-manager/dashboard'),
			],
			[
				'label' => Craft::t('variant-manager', 'attributes.attributes'),
				'url' => UrlHelper::cpUrl('variant-manager/attributes'),
			],
		];

		$attribute = $this->getParentAttribute();

		if ($attribute instanceof self) {
			$crumbs[] = [
				'html' => Cp::elementChipHtml($attribute, [
					'class' => 'chromeless',
					'hyperlink' => true,
				]),
			];
		}

		return $crumbs;
	}

	protected function metaFieldsHtml(bool $static): string
	{
		return ($this->isOption() ? $this->optionMetaFieldsHtml($static) : $this->attributeMetaFieldsHtml($static))
			. parent::metaFieldsHtml($static);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected static function defineSources(string $context): array
	{
		// The index route renders a template rather than an action, so the gate belongs on the source
		if (! PermissionHelper::canSaveAnyProductType()) {
			return [];
		}

		$variantAttributes = Plugin::getInstance()->getVariantAttributes();

		return [
			[
				'key' => '*',
				'label' => Craft::t('variant-manager', 'attributes.allAttributes'),
				'criteria' => [],
				'structureId' => $variantAttributes->getStructureId(),
				'structureEditable' => PermissionHelper::canSaveAnyProductType(),
				'defaultViewMode' => 'structure',
				'defaultSort' => ['structure', 'asc'],
			],
		];
	}

	protected static function defineFieldLayouts(?string $source): array
	{
		return Plugin::getInstance()->getFieldSets()->getAllLayouts();
	}

	protected static function defineSearchableAttributes(): array
	{
		// Name only. The title is indexed already.
		return ['name'];
	}

	/**
	 * @return array<string, string>
	 */
	protected static function defineSortOptions(): array
	{
		return [
			'name' => Craft::t('variant-manager', 'attributes.name'),
			'dateCreated' => Craft::t('app', 'Date Created'),
		];
	}

	/**
	 * @return array<string, string>
	 */
	protected static function defineTableAttributes(): array
	{
		return [
			'name' => Craft::t('variant-manager', 'attributes.name'),
			'displayType' => Craft::t('variant-manager', 'attributes.displayType'),
			'fieldSet' => Craft::t('variant-manager', 'fieldSets.fieldSet'),
			'dateCreated' => Craft::t('app', 'Date Created'),
		];
	}

	protected static function defineDefaultTableAttributes(string $source): array
	{
		return ['name', 'displayType', 'fieldSet'];
	}

	protected function attributeHtml(string $attribute): string
	{
		return match ($attribute) {
			'name' => Html::encode($this->name),
			'displayType' => $this->isOption() ? '' : Html::encode($this->getDisplayType()->label()),
			'fieldSet' => $this->isOption() ? '' : Html::encode($this->fieldSetName()),
			default => parent::attributeHtml($attribute),
		};
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		$rules = parent::defineRules();
		$rules[] = [['attributeId'],
			'number',
			'integerOnly' => true];
		$rules[] = [['attributeId'], 'validateAttributeId'];
		$rules[] = [['name'],
			'required',
			'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]];
		// Creating an element validates on essentials, and the pickers create every attribute and option that way
		$rules[] = [['name'],
			'validateNameNotTaken',
			'skipOnEmpty' => false,
			'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE, self::SCENARIO_ESSENTIALS]];
		$rules[] = [['displayType'],
			'in',
			'range' => array_column(DisplayType::cases(), 'value')];
		$rules[] = [['name', 'nameKey', 'skuPartial'],
			'string',
			'max' => 255];
		$rules[] = [['priceModifier'], 'number'];
		// A select posts '' for None, and the column distinguishes unassigned from a uid
		$rules[] = [['fieldSetUid'],
			'filter',
			'filter' => static fn (?string $fieldSetUid): ?string => $fieldSetUid === '' ? null : $fieldSetUid];
		$rules[] = [['fieldSetUid'], 'validateFieldSetUid'];
		return $rules;
	}

	/**
	 * An import and the backfill register a system name without a display name.
	 */
	private function resolvedDisplayName(): string
	{
		$displayName = trim((string) $this->title);

		return $displayName === '' ? trim($this->name) : $displayName;
	}

	private function resolvedName(): string
	{
		$name = trim($this->name);

		return $name === '' ? trim((string) $this->title) : $name;
	}

	/**
	 * The read-only half of the field SystemNameField renders while the record is still a draft.
	 */
	private function systemNameFieldHtml(): string
	{
		if ($this->getIsUnpublishedDraft()) {
			return '';
		}

		return Cp::fieldHtml(Html::encode($this->name), [
			'label' => Craft::t('variant-manager', 'attributes.name'),
		]);
	}

	private function placeInStructure(): void
	{
		$structuresService = Craft::$app->getStructures();
		$attribute = $this->getParentAttribute();

		if (! $attribute instanceof self) {
			$structuresService->appendToRoot((int) $this->structureId, $this);
			return;
		}

		$structuresService->append((int) $this->structureId, $this, $attribute);
	}

	private function logCreation(): void
	{
		$currentUser = Craft::$app->getUser()->getIdentity();

		if ($this->isOption()) {
			Activity::log($currentUser, Craft::t('variant-manager', 'options.activityCreated', [
				'name' => Html::encode($this->name),
				'attribute' => Html::encode((string) $this->getParentAttribute()?->name),
			]));

			return;
		}

		Activity::log($currentUser, Craft::t('variant-manager', 'attributes.activityCreated', [
			'name' => Html::encode($this->name),
		]));
	}

	private function fieldSetName(): string
	{
		$fieldSet = Plugin::getInstance()->getFieldSets()->getFieldSetByUid($this->fieldSetUid);

		return $fieldSet === null
			? Craft::t('variant-manager', 'fieldSets.none')
			: (string) $fieldSet->name;
	}

	private function attributeMetaFieldsHtml(bool $static): string
	{
		$fieldSets = Plugin::getInstance()->getFieldSets()->getAllFieldSets();
		$fieldSetOptions = [
			'' => Craft::t('variant-manager', 'fieldSets.none'),
		] + ArrayHelper::map($fieldSets, 'uid', 'name');

		return $this->systemNameFieldHtml() . Cp::selectFieldHtml([
			'label' => Craft::t('variant-manager', 'attributes.displayType'),
			'id' => 'displayType',
			'name' => 'displayType',
			'options' => DisplayType::options(Plugin::getInstance()->getSettings()->getAvailableDisplayTypes($this->displayType)),
			'value' => $this->displayType,
			'disabled' => $static,
			'errors' => $this->getErrors('displayType'),
		]) . Cp::selectFieldHtml([
			'label' => Craft::t('variant-manager', 'fieldSets.fieldSet'),
			'instructions' => Craft::t('variant-manager', 'fieldSets.fieldSetInstructions'),
			'id' => 'fieldSetUid',
			'name' => 'fieldSetUid',
			'options' => $fieldSetOptions,
			'value' => $this->fieldSetUid,
			'disabled' => $static,
			'errors' => $this->getErrors('fieldSetUid'),
		]);
	}

	private function optionMetaFieldsHtml(bool $static): string
	{
		$variantCount = Plugin::getInstance()->getVariantAttributes()->variantCountForOption($this);

		$fields = Cp::fieldHtml(Html::encode(Craft::t('variant-manager', 'options.variantCount', [
			'count' => $variantCount,
		])), [
			'label' => Craft::t('variant-manager', 'options.usedBy'),
		]);

		$fields .= Cp::selectFieldHtml([
			'label' => Craft::t('variant-manager', 'attributes.attribute'),
			'id' => 'attributeId',
			'name' => 'attributeId',
			'options' => ArrayHelper::map(self::find()->attributeId(0)->all(), 'id', 'name'),
			'value' => $this->attributeId,
			'disabled' => $static,
			'errors' => $this->getErrors('attributeId'),
		]);

		$fields .= $this->systemNameFieldHtml();

		$fields .= Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'options.skuPartial'),
			'id' => 'skuPartial',
			'name' => 'skuPartial',
			'value' => $this->skuPartial,
			'disabled' => $static,
			'errors' => $this->getErrors('skuPartial'),
		]);

		return $fields . Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'options.priceModifier'),
			'id' => 'priceModifier',
			'name' => 'priceModifier',
			'value' => $this->priceModifier,
			'disabled' => $static,
			'errors' => $this->getErrors('priceModifier'),
		]);
	}
}
