# Configuration

Create a `variant-manager.php` file in your `config` directory. The following shows the defaults applied by Variant Manager:

```php
<?php

return [
    'emptyAttributeValue' => '',
    'attributePrefix' => 'Attribute: ',
    'variantFieldMap' => [
        '*' => [
            'sku' => 'sku',
            'stock' => 'stock',
            'price' => 'price',
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
  'variantFieldMap' => [
    "*" => $defaultFieldMap,
    'general' => array_merge(
      $defaultFieldMap,
      ['notes' => 'notes']
    ),
  ],
];
```
