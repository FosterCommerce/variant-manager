<?php

namespace fostercommerce\variantmanager\controllers;

use fostercommerce\variantmanager\VariantManagerAssetBundle;

class DashboardController extends BaseController
{
    public function actionIndex(): \craft\web\Response
    {
        $this->view->registerAssetBundle(VariantManagerAssetBundle::class);

        return $this->renderTemplate('variant-manager/dashboard');
    }
}
