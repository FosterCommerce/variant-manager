<?php

namespace fostercommerce\variantmanager\helpers;

use Craft;

trait BaseHelper
{
    private $_plugin;

    public function getPlugin()
    {
        if (! $this->_plugin) {
            $this->_plugin = Craft::$app->plugins->getPlugin('variant-manager');
        }

        return $this->_plugin;
    }

    public function setPlugin($plugin): void
    {
        $this->_plugin = $plugin;
    }
}
