<?php

namespace fostercommerce\variantmanager\errors;

use yii\base\Exception;

/**
 * Thrown when the uploaded CSV, or the product it names, cannot produce a valid import.
 *
 * @since 4.2.0
 */
class ImportDataException extends Exception
{
	public function getName(): string
	{
		return 'Invalid Import Data';
	}
}
