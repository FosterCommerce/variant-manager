<?php

namespace fostercommerce\variantmanager\models;

use craft\base\Model;

/**
 * One variant property the maker either leaves alone or takes responsibility for.
 */
class VariantMakerProperty extends Model
{
	public bool $include = false;

	public bool|int|string|null $value = null;
}
