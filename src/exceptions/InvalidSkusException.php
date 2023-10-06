<?php

namespace fostercommerce\variantmanager\exceptions;

use craft\commerce\elements\Product;

class InvalidSkusException extends \Exception
{
    public function __construct(Product $product, array $items)
    {
        $message = "The following SKUs already exist for the given product IDs:\n\n";

        if (array_key_exists($product->id, $items)) {
            unset($items[$product->id]);
        }

        foreach ($items as $id => $skus) {
            $normalized = implode(', ', $skus);

            $message .= sprintf('<strong>%s</strong>: %s%s', $id, $normalized, PHP_EOL);
        }

        parent::__construct($message);
    }
}
