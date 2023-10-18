<?php

namespace fostercommerce\variantmanager\models;

use craft\base\Model;

/**
 * Variant Manager settings
 */
class Settings extends Model
{
    public array $productFieldMap = [
        "*" => [],
    ];
}
