<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;

use fostercommerce\variantmanager\helpers\BaseController;

use fostercommerce\variantmanager\VariantManagerAssetBundle;

class DashboardController extends BaseController
{

    public function actionIndex() : \craft\web\Response
    {

        $this->view->registerAssetBundle(VariantManagerAssetBundle::class);

        return $this->renderTemplate('variant-manager/dashboard');

    }
	
}