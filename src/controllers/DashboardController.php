<?php

namespace fostercommerce\variantmanager\controllers;

use craft\web\Controller;
use craft\web\Response;
use fostercommerce\variantmanager\VariantManagerAssetBundle;

class DashboardController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->view->registerAssetBundle(VariantManagerAssetBundle::class);

        return $this->renderTemplate('variant-manager/dashboard');
    }
}
