<?php

namespace fostercommerce\variantmanager\helpers;

use Craft;
use fostercommerce\variantmanager\VariantManager;

trait BaseHelper
{
    private ?VariantManager $_plugin;

    public function getPlugin(): VariantManager
    {
        if (! $this->_plugin) {
            $plugin = Craft::$app->plugins->getPlugin('variant-manager');
            /** @var ?VariantManager $plugin */
            $this->_plugin = $plugin;
        }

        return $this->_plugin;
    }

    public function setPlugin($plugin): void
    {
        $this->_plugin = $plugin;
    }
}
