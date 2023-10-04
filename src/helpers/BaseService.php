<?php

namespace fostercommerce\variantmanager\helpers;

use Craft;

use craft\base\Component;

use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;

use yii\base\Event;

trait BaseServiceTrait
{
    use \fostercommerce\variantmanager\helpers\BaseHelper;

    // Public Methods
    // =========================================================================

    protected function strap(
        object $plugin,
    ) {
        $this->plugin = $plugin;

        $this->setupPaths();
        $this->setupEvents();
    }

    protected function setupPaths(
        $paths = null,
    ) {
        $paths ??= $this->paths;

        Event::on(

            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,

            static function(RegisterUrlRulesEvent $registerUrlRulesEvent) use ($paths): void {
                foreach ($paths as $path => $action) {
                    $registerUrlRulesEvent->rules[$path] = $action;
                }
            }

        );
    }

    protected function setupEvents()
    {
    }

    protected function getBodyParams()
    {
        return Craft::$app->request->getBodyParams();
    }

    protected function getUser(
        $id = null,
    ) {

        // This needs to be completed if ID exists.

        return ($id !== null) ? null : Craft::$app->getUser()->getIdentity();
    }

    protected function getCfg()
    {
        return $this->plugin->settings;
    }

    protected function getRequest()
    {
        return Craft::$app->getRequest();
    }

    protected function getResponse()
    {
        return $this->controller->response;
    }

    protected function getController()
    {
        return Craft::$app->controller;
    }

    protected function parameter($name)
    {
        return $this->controller->parameter($name);
    }
}

class BaseService extends Component
{
    use BaseServiceTrait;

    public $paths = [];
}
