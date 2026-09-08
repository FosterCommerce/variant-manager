<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\behaviors\CustomFieldBehavior;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\elements\VariantAttributeOption;
use yii\base\Component;

/**
 * Attribute field layouts, stored in project config under the name key.
 */
class AttributeConfigs extends Component
{
	// Renaming this path orphans every attribute config stored under the old path
	public const CONFIG_PATH = 'variant-manager.attributes';

	/**
	 * Register field handles these layouts override.
	 *
	 * These layouts are never saved to the fieldlayouts table, so nothing else registers their handles.
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

	public function getFieldLayout(string $nameKey): FieldLayout
	{
		return $this->layout($nameKey, 'fieldLayouts', VariantAttribute::class);
	}

	public function getOptionFieldLayout(string $nameKey): FieldLayout
	{
		return $this->layout($nameKey, 'optionFieldLayouts', VariantAttributeOption::class);
	}

	/**
	 * @return list<FieldLayout>
	 */
	public function getAllAttributeLayouts(): array
	{
		return $this->getAllLayouts('fieldLayouts', VariantAttribute::class);
	}

	/**
	 * @return list<FieldLayout>
	 */
	public function getAllOptionLayouts(): array
	{
		return $this->getAllLayouts('optionFieldLayouts', VariantAttributeOption::class);
	}

	public function save(string $nameKey, FieldLayout $fieldLayout, FieldLayout $optionFieldLayout): bool
	{
		if (! $fieldLayout->validate() || ! $optionFieldLayout->validate()) {
			return false;
		}

		// Reuse the stored uid, since an assembled layout has none and a fresh one churns config
		$fieldLayout->uid = $this->getFieldLayout($nameKey)->uid ?? StringHelper::UUID();
		$optionFieldLayout->uid = $this->getOptionFieldLayout($nameKey)->uid ?? StringHelper::UUID();

		Craft::$app->getProjectConfig()->set(
			self::CONFIG_PATH . '.' . $nameKey,
			[
				'fieldLayouts' => [
					$fieldLayout->uid => $fieldLayout->getConfig() ?? [],
				],
				'optionFieldLayouts' => [
					$optionFieldLayout->uid => $optionFieldLayout->getConfig() ?? [],
				],
			],
			"Save the “{$nameKey}” variant attribute settings"
		);

		return true;
	}

	/**
	 * Remove config for name keys with no attribute row.
	 *
	 * Sweep here, since a hard delete from garbage collection fires no element hook.
	 */
	public function removeOrphaned(): void
	{
		$projectConfig = Craft::$app->getProjectConfig();

		if ($projectConfig->readOnly) {
			return;
		}

		$configs = $projectConfig->get(self::CONFIG_PATH);

		if (! is_array($configs)) {
			return;
		}

		$nameKeys = array_flip(array_map(
			static fn (VariantAttribute $attribute): string => $attribute->nameKey,
			VariantAttribute::find()->trashed(null)->all()
		));

		foreach (array_keys($configs) as $nameKey) {
			if (! isset($nameKeys[$nameKey])) {
				$this->remove((string) $nameKey);
			}
		}
	}

	public function remove(string $nameKey): void
	{
		Craft::$app->getProjectConfig()->remove(
			self::CONFIG_PATH . '.' . $nameKey,
			"Delete the “{$nameKey}” variant attribute settings"
		);
	}

	/**
	 * Get every configured layout.
	 *
	 * These layouts exist only in project config, not the fieldlayouts table.
	 *
	 * @param class-string $elementType
	 * @return list<FieldLayout>
	 */
	private function getAllLayouts(string $layoutKey, string $elementType): array
	{
		$configs = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH);

		if (! is_array($configs)) {
			return [];
		}

		$layouts = [];

		foreach (array_keys($configs) as $nameKey) {
			$layouts[] = $this->layout((string) $nameKey, $layoutKey, $elementType);
		}

		return $layouts;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function config(string $nameKey): array
	{
		$config = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH . '.' . $nameKey);

		return is_array($config) ? $config : [];
	}

	/**
	 * @param class-string $elementType
	 */
	private function layout(string $nameKey, string $layoutKey, string $elementType): FieldLayout
	{
		$layouts = $this->config($nameKey)[$layoutKey] ?? [];

		if (! is_array($layouts) || $layouts === []) {
			return new FieldLayout([
				'type' => $elementType,
			]);
		}

		$config = reset($layouts);
		$fieldLayout = FieldLayout::createFromConfig(is_array($config) ? $config : []);
		$fieldLayout->uid = (string) key($layouts);
		$fieldLayout->type = $elementType;

		return $fieldLayout;
	}
}
