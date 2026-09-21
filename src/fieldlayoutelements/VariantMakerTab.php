<?php

namespace fostercommerce\variantmanager\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\fieldlayoutelements\BaseUiElement;
use fostercommerce\variantmanager\models\VariantMakerSettings;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\services\VariantMaker as VariantMakerService;

class VariantMakerTab extends BaseUiElement
{
	public function formHtml(?ElementInterface $element = null, bool $static = false): ?string
	{
		if (! $element instanceof Product) {
			return null;
		}

		$variantMaker = Plugin::getInstance()->getVariantMaker();
		$postedSettings = $variantMaker->postedSettings();

		// Only a rejected save has something to redraw, so stored settings are never shown with errors
		$postedSettings?->validate();

		$settings = $postedSettings ?? $variantMaker->getSettings($element);

		return Craft::$app->getView()->renderTemplate('variant-manager/variant-maker/tab', [
			'product' => $element,
			'settings' => $settings,
			'savedRows' => $variantMaker->settingsRows($settings),
			'inventoryLocations' => $element->getStore()->getInventoryLocationsOptions(),
			'generatesTitles' => VariantMakerService::generatesTitles($element),
			'propertyNames' => VariantMakerSettings::propertyNames(),
			'requiredProperties' => array_filter(VariantMakerSettings::propertyNames(), VariantMakerSettings::isRequired(...)),
			'static' => $static,
		]);
	}

	protected function selectorLabel(): string
	{
		return Craft::t('variant-manager', 'variantMaker.name');
	}
}
