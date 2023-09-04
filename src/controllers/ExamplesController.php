<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;

use fostercommerce\variantmanager\helpers\BaseController;

use fostercommerce\variantmanager\VariantManagerAssetBundle;

class ExamplesController extends BaseController
{

    public function actionIndex() : \craft\web\Response
    {

        return $this->renderTemplate('variant-manager/examples/test');

    }

    public function actionTest() : \craft\web\Response
    {

        return $this->renderTemplate('variant-manager/examples/test');

    }
	
}