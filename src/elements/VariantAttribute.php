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
use fostercommerce\variantmanager\elements\db\VariantAttributeQuery;
use fostercommerce\variantmanager\enums\DisplayType;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use fostercommerce\variantmanager\records\VariantAttribute as VariantAttributeRecord;
use yii\base\InvalidConfigException;

/**
 * A registry row for one attribute name used by the Variant Attributes field.
 *
 * Variants store the name as a string, so deleting an attribute does not change a variant.
 */
class VariantAttribute extends Element
{
	public string $name = '';

	public string $nameKey = '';

	public string $displayType = DisplayType::Dropdown->value;

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

	public static function isLocalized(): bool
	{
		// Neither table has a siteId column, so per-site rows would be identical
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

	public function getFieldLayout(): ?FieldLayout
	{
		return Plugin::getInstance()->getAttributeConfigs()->getFieldLayout($this->nameKey);
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

			$record->name = $this->name;
			$record->nameKey = $this->nameKey;
			$record->displayType = $this->displayType;
			$record->save(false);

			if ($isNew) {
				Activity::log(Craft::$app->getUser()->getIdentity(), Craft::t('variant-manager', 'attributes.activityCreated', [
					'name' => Html::encode($this->name),
				]));
			}
		}

		parent::afterSave($isNew);
	}

	public function beforeDelete(): bool
	{
		if (! parent::beforeDelete()) {
			return false;
		}

		if (Plugin::getInstance()->getVariantAttributes()->isAttributeInUse($this)) {
			$this->addError('name', Craft::t('variant-manager', 'attributes.deleteInUse'));
			return false;
		}

		// Include already-trashed options on a hard delete, since the cascade removes their rows
		$options = VariantAttributeOption::find()
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
		$options = VariantAttributeOption::find()
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
		$this->name = trim($this->name);
		$this->nameKey = self::normalizeName($this->name);

		return parent::beforeSave($isNew);
	}

	protected function metaFieldsHtml(bool $static): string
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
		$fields .= Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'attributes.name'),
			'id' => 'name',
			'value' => $this->name,
			'disabled' => true,
		]);

		return $fields . parent::metaFieldsHtml($static);
	}

	protected static function defineSources(string $context): array
	{
		return [
			[
				'key' => '*',
				'label' => Craft::t('variant-manager', 'attributes.allAttributes'),
				'criteria' => [],
			],
		];
	}

	protected static function defineFieldLayouts(?string $source): array
	{
		return Plugin::getInstance()->getAttributeConfigs()->getAllAttributeLayouts();
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
		return ['name', 'displayType'];
	}

	protected function attributeHtml(string $attribute): string
	{
		return match ($attribute) {
			'name' => Html::encode($this->name),
			'displayType' => Html::encode($this->getDisplayType()->label()),
			default => parent::attributeHtml($attribute),
		};
	}

	protected function defineRules(): array
	{
		$rules = parent::defineRules();
		$rules[] = [['name'], 'required'];
		$rules[] = [['displayType'],
			'in',
			'range' => array_column(DisplayType::cases(), 'value')];
		$rules[] = [['name', 'nameKey'],
			'string',
			'max' => 255];
		return $rules;
	}
}
