<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\behaviors\CustomFieldBehavior;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use fostercommerce\variantmanager\elements\VariantAttribute;
use yii\base\Component;

/**
 * Attribute field layouts, stored in project config under the attribute's uid.
 *
 * Modules register attributes and options through VariantAttributes.
 *
 * @internal
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

	public function getFieldLayout(string $attributeUid): FieldLayout
	{
		return $this->layout($attributeUid, 'fieldLayouts');
	}

	public function getOptionFieldLayout(string $attributeUid): FieldLayout
	{
		return $this->layout($attributeUid, 'optionFieldLayouts');
	}

	/**
	 * Every configured layout, attribute and option alike, for defineFieldLayouts().
	 *
	 * @return list<FieldLayout>
	 */
	public function getAllLayouts(): array
	{
		return [...$this->layouts('fieldLayouts'), ...$this->layouts('optionFieldLayouts')];
	}

	public function save(VariantAttribute $attribute, FieldLayout $fieldLayout, FieldLayout $optionFieldLayout): bool
	{
		if (! $fieldLayout->validate() || ! $optionFieldLayout->validate()) {
			return false;
		}

		// Reuse the stored uid. An assembled layout has none, and a fresh one churns config.
		$fieldLayout->uid = $this->getFieldLayout((string) $attribute->uid)->uid ?? StringHelper::UUID();
		$optionFieldLayout->uid = $this->getOptionFieldLayout((string) $attribute->uid)->uid ?? StringHelper::UUID();

		Craft::$app->getProjectConfig()->set(
			self::CONFIG_PATH . '.' . (string) $attribute->uid,
			[
				'fieldLayouts' => [
					$fieldLayout->uid => $fieldLayout->getConfig() ?? [],
				],
				'optionFieldLayouts' => [
					$optionFieldLayout->uid => $optionFieldLayout->getConfig() ?? [],
				],
			],
			"Save the “{$attribute->name}” variant attribute settings"
		);

		return true;
	}

	/**
	 * Remove config for uids with no attribute row.
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

		$attributeUids = array_flip(array_map(
			static fn (VariantAttribute $attribute): string => (string) $attribute->uid,
			VariantAttribute::find()->attributeId(0)->trashed(null)->all()
		));

		foreach (array_keys($configs) as $attributeUid) {
			// A key that is not a uid predates the rekey migration, which is the only caller that may remove it
			if (! StringHelper::isUUID((string) $attributeUid)) {
				continue;
			}

			if (! isset($attributeUids[$attributeUid])) {
				$this->remove((string) $attributeUid);
			}
		}
	}

	public function remove(string $attributeUid): void
	{
		Craft::$app->getProjectConfig()->remove(
			self::CONFIG_PATH . '.' . $attributeUid,
			"Delete the variant attribute settings for {$attributeUid}"
		);
	}

	/**
	 * Get every configured layout.
	 *
	 * These layouts exist only in project config, not the fieldlayouts table.
	 *
	 * @return list<FieldLayout>
	 */
	private function layouts(string $layoutKey): array
	{
		$configs = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH);

		if (! is_array($configs)) {
			return [];
		}

		$layouts = [];

		foreach (array_keys($configs) as $attributeUid) {
			$layouts[] = $this->layout((string) $attributeUid, $layoutKey);
		}

		return $layouts;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function config(string $attributeUid): array
	{
		$config = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH . '.' . $attributeUid);

		return is_array($config) ? $config : [];
	}

	private function layout(string $attributeUid, string $layoutKey): FieldLayout
	{
		$layouts = $this->config($attributeUid)[$layoutKey] ?? [];

		if (! is_array($layouts) || $layouts === []) {
			return new FieldLayout([
				'type' => VariantAttribute::class,
			]);
		}

		$config = reset($layouts);
		// Set the type in the config. createFromConfig() resolves the native fields before returning.
		$fieldLayout = FieldLayout::createFromConfig([
			'type' => VariantAttribute::class,
		] + (is_array($config) ? $config : []));
		$fieldLayout->uid = (string) key($layouts);

		return $fieldLayout;
	}
}
