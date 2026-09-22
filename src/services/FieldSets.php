<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\behaviors\CustomFieldBehavior;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\models\FieldSet;
use yii\base\Component;

/**
 * Field sets, stored in project config under their own uid.
 */
class FieldSets extends Component
{
	// Renaming this path orphans every field set stored under the old path
	private const CONFIG_PATH = 'variant-manager.fieldSets';

	/**
	 * @var array<string, FieldSet>|null
	 */
	private ?array $fieldSets = null;

	/**
	 * These layouts are never saved to the fieldlayouts table, so no other code registers their handles.
	 */
	public function registerOverriddenFieldHandles(): void
	{
		foreach ($this->getAllLayouts() as $layout) {
			foreach ($layout->getCustomFieldElements() as $layoutElement) {
				if ($layoutElement->handle !== null) {
					CustomFieldBehavior::$fieldHandles[$layoutElement->handle] = true;
				}
			}
		}
	}

	/**
	 * @return array<string, FieldSet> keyed on uid
	 */
	public function getAllFieldSets(): array
	{
		if ($this->fieldSets !== null) {
			return $this->fieldSets;
		}

		$configs = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH);
		$fieldSets = [];

		if (is_array($configs)) {
			foreach ($configs as $uid => $config) {
				$fieldSets[(string) $uid] = $this->fieldSetFromConfig((string) $uid, $config);
			}

			uasort($fieldSets, static fn (FieldSet $first, FieldSet $second): int => strcasecmp((string) $first->name, (string) $second->name));
		}

		return $this->fieldSets = $fieldSets;
	}

	public function getFieldSetByUid(?string $uid): ?FieldSet
	{
		return $uid === null ? null : ($this->getAllFieldSets()[$uid] ?? null);
	}

	/**
	 * Every configured layout, attribute and option alike, for defineFieldLayouts().
	 *
	 * @return list<FieldLayout>
	 */
	public function getAllLayouts(): array
	{
		$layouts = [];

		foreach ($this->getAllFieldSets() as $fieldSet) {
			$layouts[] = $fieldSet->getFieldLayout();
			$layouts[] = $fieldSet->getOptionFieldLayout();
		}

		return $layouts;
	}

	/**
	 * The attributes assigned to a field set, which block its deletion.
	 *
	 * @return list<VariantAttribute>
	 */
	public function getAttributesUsingFieldSet(string $uid): array
	{
		return VariantAttribute::find()->attributeId(0)->fieldSetUid($uid)->all();
	}

	public function save(FieldSet $fieldSet): bool
	{
		if (! $fieldSet->validate()) {
			return false;
		}

		$fieldSet->uid ??= StringHelper::UUID();

		// Read the stored uids from config, because the caller may have replaced the memoized layouts
		$stored = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH . '.' . $fieldSet->uid);
		$stored = is_array($stored) ? $stored : [];

		$fieldSet->getFieldLayout()->uid = $this->storedLayoutUid($stored, 'fieldLayouts');
		$fieldSet->getOptionFieldLayout()->uid = $this->storedLayoutUid($stored, 'optionFieldLayouts');

		Craft::$app->getProjectConfig()->set(
			self::CONFIG_PATH . '.' . $fieldSet->uid,
			$this->configFromFieldSet($fieldSet),
			"Save the “{$fieldSet->name}” variant attribute field set"
		);

		$this->fieldSets = null;

		return true;
	}

	public function delete(string $uid): void
	{
		Craft::$app->getProjectConfig()->remove(
			self::CONFIG_PATH . '.' . $uid,
			"Delete the variant attribute field set {$uid}"
		);

		$this->fieldSets = null;
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	private function storedLayoutUid(array $stored, string $layoutKey): string
	{
		$layouts = $stored[$layoutKey] ?? [];

		return (string) (is_array($layouts) ? array_key_first($layouts) ?? StringHelper::UUID() : StringHelper::UUID());
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function fieldSetFromConfig(string $uid, array $config): FieldSet
	{
		$fieldSet = new FieldSet([
			'uid' => $uid,
			'name' => $config['name'] ?? null,
			'handle' => $config['handle'] ?? null,
		]);

		/** @var array<string, mixed> $fieldLayouts */
		$fieldLayouts = (array) ($config['fieldLayouts'] ?? []);
		/** @var array<string, mixed> $optionFieldLayouts */
		$optionFieldLayouts = (array) ($config['optionFieldLayouts'] ?? []);

		$fieldSet->setFieldLayout($this->layoutFromConfig($fieldLayouts));
		$fieldSet->setOptionFieldLayout($this->layoutFromConfig($optionFieldLayouts));

		return $fieldSet;
	}

	/**
	 * @param array<string, mixed> $layouts
	 */
	private function layoutFromConfig(array $layouts): FieldLayout
	{
		$layoutUid = array_key_first($layouts);

		return FieldLayout::createFromConfig([
			...(array) ($layouts[$layoutUid] ?? []),
			'type' => VariantAttribute::class,
			'uid' => (string) ($layoutUid ?? StringHelper::UUID()),
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function configFromFieldSet(FieldSet $fieldSet): array
	{
		return [
			'name' => $fieldSet->name,
			'handle' => $fieldSet->handle,
			'fieldLayouts' => [
				$fieldSet->getFieldLayout()->uid => $fieldSet->getFieldLayout()->getConfig() ?? [],
			],
			'optionFieldLayouts' => [
				$fieldSet->getOptionFieldLayout()->uid => $fieldSet->getOptionFieldLayout()->getConfig() ?? [],
			],
		];
	}
}
