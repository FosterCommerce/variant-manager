<?php

namespace fostercommerce\variantmanager\controllers;

use craft\web\Controller;
use fostercommerce\variantmanager\VariantManagerAssetBundle;
use yii\base\InvalidConfigException;
use yii\web\Response;

class DashboardController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * @throws InvalidConfigException
     */
    public function actionIndex(): Response
    {
        $this->view->registerAssetBundle(VariantManagerAssetBundle::class);

        return $this->renderTemplate('variant-manager/dashboard');
    }
}
