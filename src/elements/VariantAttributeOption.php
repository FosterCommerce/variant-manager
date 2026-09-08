<?php

namespace fostercommerce\variantmanager\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use fostercommerce\variantmanager\elements\db\VariantAttributeOptionQuery;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use fostercommerce\variantmanager\records\VariantAttributeOption as VariantAttributeOptionRecord;
use yii\base\InvalidConfigException;

/**
 * A registry row for one value of one attribute, such as “Blue” under “Color”.
 *
 * Variants store the value as a string, so deleting an option does not change a variant.
 *
 * @property-read null|VariantAttribute $variantAttribute
 */
class VariantAttributeOption extends Element
{
	public ?int $attributeId = null;

	public string $value = '';

	public string $valueKey = '';

	private ?VariantAttribute $variantAttribute = null;

	public static function displayName(): string
	{
		return Craft::t('variant-manager', 'options.option');
	}

	public static function lowerDisplayName(): string
	{
		return Craft::t('variant-manager', 'options.optionLower');
	}

	public static function pluralDisplayName(): string
	{
		return Craft::t('variant-manager', 'options.options');
	}

	public static function pluralLowerDisplayName(): string
	{
		return Craft::t('variant-manager', 'options.optionsLower');
	}

	public static function refHandle(): ?string
	{
		return 'variantattributeoption';
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

	public static function find(): VariantAttributeOptionQuery
	{
		return new VariantAttributeOptionQuery(static::class);
	}

	public static function normalizeValue(string $value): string
	{
		return VariantAttribute::normalizeName($value);
	}

	public function getFieldLayout(): ?FieldLayout
	{
		$attribute = $this->getVariantAttribute();

		return $attribute === null
			? null
			: Plugin::getInstance()->getAttributeConfigs()->getOptionFieldLayout($attribute->nameKey);
	}

	public function getVariantAttribute(): ?VariantAttribute
	{
		if ($this->attributeId === null) {
			return null;
		}

		return $this->variantAttribute ??= Plugin::getInstance()->getVariantAttributes()->getAttributeById($this->attributeId);
	}

	public function getCpEditUrl(): ?string
	{
		return UrlHelper::cpUrl("variant-manager/attribute-options/{$this->id}");
	}

	public function getPostEditUrl(): ?string
	{
		return UrlHelper::cpUrl('variant-manager/attribute-options');
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
				$record = new VariantAttributeOptionRecord();
				$record->id = (int) $this->id;
			} else {
				$record = VariantAttributeOptionRecord::findOne($this->id);

				if (! $record instanceof VariantAttributeOptionRecord) {
					throw new InvalidConfigException("Invalid variant attribute option ID: {$this->id}");
				}
			}

			$record->attributeId = $this->attributeId;
			$record->value = $this->value;
			$record->valueKey = $this->valueKey;
			$record->save(false);

			if ($isNew) {
				$attributeName = Html::encode((string) $this->getVariantAttribute()?->name);
				Activity::log(Craft::$app->getUser()->getIdentity(), 'Created option ' . Html::encode($this->value) . " under {$attributeName}");
			}
		}

		parent::afterSave($isNew);
	}

	public function beforeDelete(): bool
	{
		if (! parent::beforeDelete()) {
			return false;
		}

		if (Plugin::getInstance()->getVariantAttributes()->isOptionInUse($this)) {
			$this->addError('value', Craft::t('variant-manager', 'options.deleteInUse'));
			return false;
		}

		return true;
	}

	public function beforeSave(bool $isNew): bool
	{
		$this->value = trim($this->value);
		$this->valueKey = self::normalizeValue($this->value);

		return parent::beforeSave($isNew);
	}

	protected function metaFieldsHtml(bool $static): string
	{
		$variantCount = Plugin::getInstance()->getVariantAttributes()->variantCountForOption($this);

		$fields = Cp::fieldHtml(Html::encode(Craft::t('variant-manager', 'options.variantCount', [
			'count' => $variantCount,
		])), [
			'label' => Craft::t('variant-manager', 'options.usedBy'),
		]);

		// Variants match on the option value string, so the value is read only
		$fields .= Cp::textFieldHtml([
			'label' => Craft::t('variant-manager', 'options.value'),
			'id' => 'value',
			'value' => $this->value,
			'disabled' => true,
		]);

		return $fields . parent::metaFieldsHtml($static);
	}

	protected static function defineSources(string $context): array
	{
		$sources = [
			[
				'key' => '*',
				'label' => Craft::t('variant-manager', 'options.allOptions'),
				'criteria' => [],
			],
		];

		foreach (Plugin::getInstance()->getVariantAttributes()->getAllAttributes() as $attribute) {
			$sources[] = [
				'key' => "attribute:{$attribute->uid}",
				'label' => $attribute->name,
				'criteria' => [
					'attributeId' => $attribute->id,
				],
			];
		}

		return $sources;
	}

	protected static function defineFieldLayouts(?string $source): array
	{
		return Plugin::getInstance()->getAttributeConfigs()->getAllOptionLayouts();
	}

	protected static function defineSortOptions(): array
	{
		return [
			'value' => Craft::t('variant-manager', 'options.value'),
			'dateCreated' => Craft::t('app', 'Date Created'),
		];
	}

	protected static function defineTableAttributes(): array
	{
		return [
			'value' => Craft::t('variant-manager', 'options.value'),
			'attribute' => Craft::t('variant-manager', 'options.attribute'),
			'dateCreated' => Craft::t('app', 'Date Created'),
		];
	}

	protected static function defineDefaultTableAttributes(string $source): array
	{
		return ['value', 'attribute'];
	}

	protected function attributeHtml(string $attribute): string
	{
		return match ($attribute) {
			'value' => Html::encode($this->value),
			'attribute' => Html::encode((string) $this->getVariantAttribute()?->name),
			default => parent::attributeHtml($attribute),
		};
	}

	protected function defineRules(): array
	{
		$rules = parent::defineRules();
		$rules[] = [['attributeId'],
			'number',
			'integerOnly' => true];
		$rules[] = [['value'], 'required'];
		$rules[] = [['value', 'valueKey'],
			'string',
			'max' => 255];
		return $rules;
	}
}
