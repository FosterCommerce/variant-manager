<?php

namespace fostercommerce\variantmanager;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class VariantManagerAssetBundle extends AssetBundle
{
    public function init()
    {

        $this->sourcePath = '@fostercommerce/variantmanager/assets';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/main.min.js',
        ];

        $this->jsOptions = [
            'type' => 'module'
        ];

        $this->css = [
            'css/main.css',
        ];

        parent::init();

    }

}