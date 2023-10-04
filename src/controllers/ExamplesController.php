<?php

namespace fostercommerce\variantmanager\controllers;

use fostercommerce\variantmanager\helpers\BaseController;

class ExamplesController extends BaseController
{
    public function actionIndex(): \craft\web\Response
    {
        return $this->renderTemplate('variant-manager/examples/test');
    }

    public function actionTest(): \craft\web\Response
    {
        return $this->renderTemplate('variant-manager/examples/test');
    }
}
