<?php

namespace fostercommerce\variantmanager\controllers;

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
