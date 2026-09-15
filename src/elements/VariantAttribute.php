<?php

namespace fostercommerce\variantmanager\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Structures;
use fostercommerce\variantmanager\elements\db\VariantAttributeQuery;
use fostercommerce\variantmanager\enums\DisplayType;
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

		if (! $this->isOption()) {
			return $attributeConfigs->getFieldLayout($this->nameKey);
		}

		$attribute = $this->getParentAttribute();

		return $attribute === null
			? null
			: $attributeConfigs->getOptionFieldLayout($attribute->nameKey);
	}

	public function getDisplayType(): DisplayType
	{
		return DisplayType::tryFrom($this->displayType) ?? DisplayType::Dropdown;
	}

	public function getCpEditUrl(): ?string
	{
		return UrlHelper::cpUrl("variant-manager/attributes/{$this->id}");
	}

	public function getPostEditUrl(): ?string
	{
		return UrlHelper::cpUrl('variant-manager/attributes');
	}

	public function canView(User $user): bool
	{
		return $user->can('variant-manager:manage-attributes');
	}

	public function canSave(User $user): bool
	{
		return $user->can('variant-manager:manage-attributes');
	}

	/**
	 * Rows are derived from what variants store, so the prune utility removes the unused ones.
	 */
	public function canDelete(User $user): bool
	{
		return false;
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

			$record->attributeId = $this->attributeId;
			$record->name = $this->name;
			$record->nameKey = $this->nameKey;
			$record->displayType = $this->displayType;
			$record->skuPartial = $this->skuPartial;
			$record->priceModifier = $this->priceModifier;
			$record->save(false);

			if ($isNew) {
				$this->placeInStructure();
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

		// Include already-trashed options on a hard delete, since the cascade removes their rows
		$options = self::find()
			->attributeId($this->id)
			->trashed($this->hardDelete ? null : false)
			->all();

		$elementsService = Craft::$app->getElements();

		foreach ($options as $option) {
			// Flag the option, since afterRestore() only restores options flagged here
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
		$this->name = trim($this->name);
		$this->nameKey = self::normalizeName($this->name);

		return parent::beforeSave($isNew);
	}

	protected function uiLabel(): ?string
	{
		return $this->title === $this->name
			? $this->name
			: "{$this->name} ({$this->title})";
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
		$variantAttributes = Plugin::getInstance()->getVariantAttributes();

		return [
			[
				'key' => '*',
				'label' => Craft::t('variant-manager', 'attributes.allAttributes'),
				'criteria' => [],
				'structureId' => $variantAttributes->getStructureId(),
				'structureEditable' => Craft::$app->getRequest()->getIsConsoleRequest() || Craft::$app->getUser()->checkPermission('variant-manager:manage-attributes'),
				'defaultViewMode' => 'structure',
				'defaultSort' => ['structure', 'asc'],
			],
		];
	}

	protected static function defineFieldLayouts(?string $source): array
	{
		$attributeConfigs = Plugin::getInstance()->getAttributeConfigs();

		return [...$attributeConfigs->getAllAttributeLayouts(), ...$attributeConfigs->getAllOptionLayouts()];
	}

	protected static function defineSearchableAttributes(): array
	{
		// Name only, since the title is indexed already
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
		// uiLabel() puts the name in the title column, so a name column repeats it
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
		$rules[] = [['name'], 'required'];
		$rules[] = [['displayType'],
			'in',
			'range' => array_column(DisplayType::cases(), 'value')];
		$rules[] = [['name', 'nameKey', 'skuPartial'],
			'string',
			'max' => 255];
		$rules[] = [['priceModifier'], 'number'];
		return $rules;
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
		$fields = Cp::selectFieldHtml([
			'label' => Craft::t('variant-manager', 'attributes.displayType'),
			'id' => 'displayType',
			'name' => 'displayType',
			'options' => DisplayType::options(Plugin::getInstance()->getSettings()->getAvailableDisplayTypes($this->displayType)),
			'value' => $this->displayType,
			'disabled' => $static,
		]);

		// Variants match on the attribute name string, so the name is read only
		return $fields . Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'attributes.name'),
			'id' => 'name',
			'value' => $this->name,
			'disabled' => true,
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

		// Variants match on the option value string, so the value is read only
		$fields .= Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'attributes.name'),
			'id' => 'name',
			'value' => $this->name,
			'disabled' => true,
		]);

		$fields .= Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'options.skuPartial'),
			'id' => 'skuPartial',
			'name' => 'skuPartial',
			'value' => $this->skuPartial,
		]);

		return $fields . Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'options.priceModifier'),
			'id' => 'priceModifier',
			'name' => 'priceModifier',
			'value' => $this->priceModifier,
		]);
	}
}
