<?php

namespace fostercommerce\variantmanager\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Structures;
use fostercommerce\variantmanager\elements\db\VariantAttributeQuery;
use fostercommerce\variantmanager\enums\DisplayType;
use fostercommerce\variantmanager\helpers\PermissionHelper;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use fostercommerce\variantmanager\records\VariantAttribute as VariantAttributeRecord;
use yii\base\InvalidConfigException;

/**
 * A registry row for one attribute name, or for one of its option values.
 *
 * Variants store the name and value as strings, so deleting a row does not change a variant.
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

	public static function lowerDisplayName(): string
	{
		return Craft::t('variant-manager', 'attributes.attributeLower');
	}

	public static function pluralDisplayName(): string
	{
		return Craft::t('variant-manager', 'attributes.attributes');
	}

	public static function pluralLowerDisplayName(): string
	{
		return Craft::t('variant-manager', 'attributes.attributesLower');
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

	public static function find(): VariantAttributeQuery
	{
		return new VariantAttributeQuery(static::class);
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

		return $this->parentAttribute ??= Plugin::getInstance()->getVariantAttributes()->getAttributeById($this->attributeId);
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
		$attributeConfigs = Plugin::getInstance()->getAttributeConfigs();

		// The settings are saved against the canonical attribute, and a provisional draft's own uid has no entry
		if (! $this->isOption()) {
			return $attributeConfigs->getFieldLayout((string) $this->getCanonicalUid());
		}

		$attribute = $this->getParentAttribute();

		return $attribute === null
			? null
			: $attributeConfigs->getOptionFieldLayout((string) $attribute->uid);
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
	public function validateNameNotTaken(): void
	{
		$name = $this->resolvedName();
		$nameKey = self::normalizeName($name);

		if ($nameKey === '') {
			return;
		}

		$query = self::find()
			->attributeId($this->attributeId)
			->nameKey(Db::escapeParam($nameKey))
			->status(null)
			// A trashed row keeps its nameKey, so the unique index still rejects a duplicate
			->trashed(null);

		$canonicalId = $this->getCanonicalId();

		if ($canonicalId !== null) {
			$query->id("not {$canonicalId}");
		}

		if (! $query->exists()) {
			return;
		}

		$this->addError('name', $this->isOption()
			? Craft::t('variant-manager', 'options.nameTaken', [
				'name' => trim($name),
				'attribute' => (string) $this->getParentAttribute()?->name,
			])
			: Craft::t('variant-manager', 'attributes.nameTaken', [
				'name' => trim($name),
			]));
	}

	/**
	 * Only a draft is deletable, since the prune utility removes every saved row no variant uses.
	 */
	public function canDelete(User $user): bool
	{
		return $this->getIsDraft() && PermissionHelper::canSaveAnyProductType($user);
	}

	/**
	 * @throws InvalidConfigException
	 */
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
			$record->nameKey = $isDraftOrRevision ? $this->uid : $this->nameKey;
			$record->displayType = $this->displayType;
			$record->skuPartial = $this->skuPartial;
			$record->priceModifier = $this->priceModifier;
			$record->save(false);

			// Place a canonical row once, because an unpublished draft keeps its id through the apply
			if ($isNew && $this->getIsCanonical()) {
				$this->placeInStructure();
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

		// The in-use checks match on the name a draft shares with its canonical row
		if (ElementHelper::isDraftOrRevision($this)) {
			return true;
		}

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

		// Include already-trashed options on a hard delete, because the cascade removes their rows
		$options = self::find()
			->attributeId($this->id)
			->trashed($this->hardDelete ? null : false)
			->all();

		$elementsService = Craft::$app->getElements();

		foreach ($options as $option) {
			// Flag the option. afterRestore() restores only the options flagged here.
			$option->deletedWithOwner = true;
			$elementsService->deleteElement($option, $this->hardDelete);
		}

		return true;
	}

	public function afterRestore(): void
	{
		$options = self::find()
			->attributeId($this->id)
			->trashed(true)
			->andWhere([
				'elements.deletedWithOwner' => true,
			])
			->all();

		Craft::$app->getElements()->restoreElements($options);

		parent::afterRestore();
	}

	public function beforeSave(bool $isNew): bool
	{
		$this->structureId = Plugin::getInstance()->getVariantAttributes()->getStructureId();
		$this->name = $this->resolvedName();
		$this->nameKey = self::normalizeName($this->name);

		return parent::beforeSave($isNew);
	}

	protected function cpEditUrl(): ?string
	{
		return "variant-manager/attributes/{$this->getCanonicalId()}";
	}

	protected function uiLabel(): ?string
	{
		// A row with no name yet has no label of its own
		if ($this->name === '') {
			return null;
		}

		$title = (string) $this->title;

		return $title === '' || $title === $this->name
			? $this->name
			: "{$this->name} ({$title})";
	}

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

		if ($attribute !== null) {
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
		return ($this->isOption() ? $this->optionMetaFieldsHtml() : $this->attributeMetaFieldsHtml($static))
			. parent::metaFieldsHtml($static);
	}

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
		return Plugin::getInstance()->getAttributeConfigs()->getAllLayouts();
	}

	protected static function defineSearchableAttributes(): array
	{
		// Name only. The title is indexed already.
		return ['name'];
	}

	protected static function defineSortOptions(): array
	{
		return [
			'name' => Craft::t('variant-manager', 'attributes.name'),
			'dateCreated' => Craft::t('app', 'Date Created'),
		];
	}

	protected static function defineTableAttributes(): array
	{
		return [
			'name' => Craft::t('variant-manager', 'attributes.name'),
			'displayType' => Craft::t('variant-manager', 'attributes.displayType'),
			'dateCreated' => Craft::t('app', 'Date Created'),
		];
	}

	protected static function defineDefaultTableAttributes(string $source): array
	{
		// Leave the name out of the columns, because uiLabel() puts it in the title column
		return ['displayType'];
	}

	protected function attributeHtml(string $attribute): string
	{
		return match ($attribute) {
			'name' => Html::encode($this->name),
			'displayType' => $this->isOption() ? '' : Html::encode($this->getDisplayType()->label()),
			default => parent::attributeHtml($attribute),
		};
	}

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
		return $rules;
	}

	private function resolvedName(): string
	{
		$name = trim($this->name);

		return $name === '' ? trim((string) $this->title) : $name;
	}

	/**
	 * The read-only half of the field SystemNameField renders while the row is still a draft.
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

		if ($attribute === null) {
			$structuresService->appendToRoot($this->structureId, $this, Structures::MODE_INSERT);
			return;
		}

		$structuresService->append($this->structureId, $this, $attribute, Structures::MODE_INSERT);
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

	private function attributeMetaFieldsHtml(bool $static): string
	{
		return $this->systemNameFieldHtml() . Cp::selectFieldHtml([
			'label' => Craft::t('variant-manager', 'attributes.displayType'),
			'id' => 'displayType',
			'name' => 'displayType',
			'options' => DisplayType::options(Plugin::getInstance()->getSettings()->getAvailableDisplayTypes($this->displayType)),
			'value' => $this->displayType,
			'disabled' => $static,
			'errors' => $this->getErrors('displayType'),
		]);
	}

	private function optionMetaFieldsHtml(): string
	{
		$variantCount = Plugin::getInstance()->getVariantAttributes()->variantCountForOption($this);

		$fields = Cp::fieldHtml(Html::encode(Craft::t('variant-manager', 'options.variantCount', [
			'count' => $variantCount,
		])), [
			'label' => Craft::t('variant-manager', 'options.usedBy'),
		]);

		$fields .= $this->systemNameFieldHtml();

		$fields .= Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'options.skuPartial'),
			'id' => 'skuPartial',
			'name' => 'skuPartial',
			'value' => $this->skuPartial,
			'errors' => $this->getErrors('skuPartial'),
		]);

		return $fields . Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'options.priceModifier'),
			'id' => 'priceModifier',
			'name' => 'priceModifier',
			'value' => $this->priceModifier,
			'errors' => $this->getErrors('priceModifier'),
		]);
	}
}
