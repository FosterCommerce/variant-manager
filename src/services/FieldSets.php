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
	 * Register field handles these layouts override.
	 *
	 * These layouts are never saved to the fieldlayouts table, so no other code registers their handles.
	 */
	public function registerOverriddenFieldHandles(): void
	{
		$configs = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH);

		if (! is_array($configs)) {
			return;
		}

		foreach ($configs as $config) {
			foreach (['fieldLayouts', 'optionFieldLayouts'] as $layoutKey) {
				foreach ($config[$layoutKey] ?? [] as $layout) {
					foreach ($layout['tabs'] ?? [] as $tab) {
						foreach ($tab['elements'] ?? [] as $element) {
							if (isset($element['handle'])) {
								CustomFieldBehavior::$fieldHandles[$element['handle']] = true;
							}
						}
					}
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
		$fieldSet->getFieldLayout()->uid = array_key_first($stored['fieldLayouts'] ?? []) ?? StringHelper::UUID();
		$fieldSet->getOptionFieldLayout()->uid = array_key_first($stored['optionFieldLayouts'] ?? []) ?? StringHelper::UUID();

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
	 * @param array<string, mixed> $config
	 */
	private function fieldSetFromConfig(string $uid, array $config): FieldSet
	{
		$fieldSet = new FieldSet([
			'uid' => $uid,
			'name' => $config['name'] ?? null,
		]);

		$fieldSet->setFieldLayout($this->layoutFromConfig($config['fieldLayouts'] ?? []));
		$fieldSet->setOptionFieldLayout($this->layoutFromConfig($config['optionFieldLayouts'] ?? []));

		return $fieldSet;
	}

	/**
	 * @param array<string, mixed> $layouts
	 */
	private function layoutFromConfig(array $layouts): FieldLayout
	{
		$layoutUid = array_key_first($layouts);

		if ($layoutUid === null) {
			return new FieldLayout([
				'type' => VariantAttribute::class,
			]);
		}

		$layout = FieldLayout::createFromConfig([
			...$layouts[$layoutUid],
			'type' => VariantAttribute::class,
		]);
		$layout->uid = (string) $layoutUid;

		return $layout;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function configFromFieldSet(FieldSet $fieldSet): array
	{
		return [
			'name' => $fieldSet->name,
			'fieldLayouts' => [
				(string) $fieldSet->getFieldLayout()->uid => $fieldSet->getFieldLayout()->getConfig() ?? [],
			],
			'optionFieldLayouts' => [
				(string) $fieldSet->getOptionFieldLayout()->uid => $fieldSet->getOptionFieldLayout()->getConfig() ?? [],
			],
		];
	}
}
