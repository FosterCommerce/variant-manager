<?php

namespace fostercommerce\variantmanager\controllers;

use craft\web\Controller;

/**
 * BaseController
 */
class BaseController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    /**
     * actionIndex
     *
     * Default action assigned to index
     */
    public function actionIndex()
    {
        return null;
    }
}
