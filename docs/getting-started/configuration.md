# Configuration

Create a `variant-manager.php` file in your `config` directory. The following shows the defaults applied by Variant Manager:

```php
<?php

return [
	'emptyAttributeValue' => '',
	'attributePrefix' => 'Attribute: ',
	'inventoryPrefix' => 'Inventory: ',
	'activityLogRetention' => '30 days',
	'productFieldMap' => [
		'*' => [
			'title' => 'title',
			'slug' => 'slug',
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
```

## Configuration options

### Variant Attributes

When a variant has a `VariantAttributesField` field, the following config options will be used.

**Note** that variant fields may only have a single VariantAttributes field. Any additional fields will be ignored by the plugin.

#### `emptyAttributeValue`

The value to use when a variant's attribute value is empty.

####  `attributePrefix`

The prefix to use to determine which columns correspond to variant attributes when importing/exporting product variants.

####  `inventoryPrefix`

The prefix to use to determine which columns correspond to variant inventory counts when importing/exporting product variants.

#### `activityLogRetention`

The duration for which activity logs should be retained. Accepts values like '1 week', '30 days', etc. Set to `null` or `false` to retain logs indefinitely.

When enabled, activity logs outside the retention period are automatically removed during GC.

Activity logs can also be removed using the `variant-manager/activities/clear` command. Pass `all` to the command to remove all activity logs.

### `productFieldMap`

The map of column names to product properties.

Supported field types and formatting:
<!-- TODO -->
- title
- slug
- entries

### `variantFieldMap`

The map of column names to variant properties.

This map does _not_ need to include the handle for the VariantAttributes field.

This config option accepts a `'*'` key which would apply the mapping to any product type which isn't included in the map. For example, if you had a product type with an extra field you can include that field with the following config:

```php
<?php

$defaultFieldMap = [
  'sku' => 'sku',
  'stock' => 'stock',
  'price' => 'price',
  'height' => 'height',
  'width' => 'width',
  'length' => 'length',
  'weight' => 'weight',
];

return [
  'emptyAttributeValue' => 'None',
  'attributePrefix' => 'Option : ',
	'activityLogRetention' => '30 days',
  'variantFieldMap' => [
    "*" => $defaultFieldMap,
    'general' => array_merge(
      $defaultFieldMap,
      ['notes' => 'notes']
    ),
  ],
];
```
