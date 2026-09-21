<?php

namespace fostercommerce\variantmanager;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;
use fostercommerce\variantmanager\helpers\PermissionHelper;

class VariantAttributeIndexAssetBundle extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = '@fostercommerce/variantmanager/assets';

		$this->depends = [
			CpAsset::class,
		];

		$this->js = [
			'js/variant-attribute-index.js',
		];

		parent::init();
	}

	public function registerAssetFiles($view): void
	{
		parent::registerAssetFiles($view);

		if ($view instanceof View) {
			$view->registerTranslations('variant-manager', [
				'variantMaker.newAttribute',
				'variantMaker.newOption',
			]);

			// JS draws the create buttons, and needs the permission the server enforces on create
			$view->registerJs(sprintf(
				'Craft.VariantManager = Craft.VariantManager || {}; Craft.VariantManager.canManageAttributes = %s;',
				PermissionHelper::canSaveAnyProductType() ? 'true' : 'false',
			), View::POS_BEGIN);
		}
	}
}
