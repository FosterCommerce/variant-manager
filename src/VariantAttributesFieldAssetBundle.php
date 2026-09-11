<?php

namespace fostercommerce\variantmanager;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class VariantAttributesFieldAssetBundle extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = '@fostercommerce/variantmanager/assets';

		$this->depends = [
			CpAsset::class,
		];

		$this->css = [
			'css/variant-attributes-field.css',
		];

		parent::init();
	}
}
