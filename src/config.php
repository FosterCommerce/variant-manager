<?php

/**
 * Variant Manager config.php
 *
 * This file exists only as a template for the Variant Manager settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'variant-manager.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 */

return [
	'emptyAttributeValue' => '',
	'attributePrefix' => 'Attribute: ',
	'inventoryPrefix' => 'Inventory',
	'activityLogRetention' => '1 week',
	'productFieldMap' => [
		'*' => [
			'title' => 'title',
			// Add default product fields here
		],
	],
	'variantFieldMap' => [
		'*' => [
			'title' => 'title',
			'sku' => 'sku',
			'inventoryTracked' => 'inventoryTracked',
			'price' => 'basePrice',
			'height' => 'height',
			'width' => 'width',
			'length' => 'length',
			'weight' => 'weight',
		],
	],
];
