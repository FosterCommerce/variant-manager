<?php

namespace fostercommerce\variantmanager\utilities;

use Craft;
use craft\base\Utility;

class AttributesUtility extends Utility
{
	public static function displayName(): string
	{
		return Craft::t('variant-manager', 'attributes.utilityTitle');
	}

	public static function id(): string
	{
		return 'variant-manager-attributes';
	}

	public static function icon(): ?string
	{
		return dirname(__DIR__) . '/icon-mask.svg';
	}

	public static function contentHtml(): string
	{
		return Craft::$app->getView()->renderTemplate('variant-manager/_utilities/attributes');
	}
}
