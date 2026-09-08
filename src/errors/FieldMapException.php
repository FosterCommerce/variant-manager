<?php

namespace fostercommerce\variantmanager\errors;

use yii\base\Exception;

/**
 * Thrown when `productFieldMap` or `variantFieldMap` cannot produce a usable set of columns.
 *
 * @since 3.0.0
 */
class FieldMapException extends Exception
{
	public function getName(): string
	{
		return 'Invalid Field Map';
	}
}
