<?php

namespace fostercommerce\variantmanager;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ProductExportAssetBundle extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = '@fostercommerce/variantmanager/assets';

		$this->depends = [
			CpAsset::class,
		];

		$this->js = [
			'js/product-export.js',
		];

		parent::init();
	}
}
