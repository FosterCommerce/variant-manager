# Configuration reference

Every setting Variant Manager reads from `config/variant-manager.php`. The file is multi-environment aware; nest values under environment names if you need per-environment overrides.

## Defaults

```php
<?php

return [
    'emptyAttributeValue' => '',
    'attributePrefix' => 'Attribute: ',
    'inventoryPrefix' => 'Inventory',
    'activityLogRetention' => '30 days',
    'productFieldMap' => [
        '*' => [
            'title' => 'title',
            'slug' => 'slug',
            'status' => 'status',
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

## Settings

### `emptyAttributeValue`

- Type: `string`
- Default: `''`

The placeholder written into the Variant Attributes field when a row's attribute cell is empty. Set to something like `None` or `N/A` if a literal empty string would confuse storefront filters.

### `attributePrefix`

- Type: `string`
- Default: `'Attribute: '`

The prefix used to recognise attribute columns in a CSV. A column whose header starts with this string is mapped to a Variant Attributes entry whose name is the rest of the header. Default behaviour: `Attribute: Color` becomes attribute `Color`.

Changing this is a breaking change for any existing CSVs. Keep it consistent across your store.

### `inventoryPrefix`

- Type: `string`
- Default: `'Inventory'`

The prefix used to recognise inventory columns. The full column pattern is `{prefix}[locationHandle]: totalName`, so with the default prefix a column is `Inventory[main]: available`.

### `activityLogRetention`

- Type: `string`, `int`, `null`, or `false`
- Default: `'30 days'` (the plugin's settings default; the example `config.php` ships `'1 week'`)

How long to keep activity log entries.

- String values use PHP relative-time format: `1 hour`, `1 day`, `1 week`, `1 month`, `1 year`.
- Integer values are interpreted as a number of days.
- `null` or `false` disables expiry; logs grow forever until manually cleared.

Expiry runs during Craft's garbage collection and via the `variant-manager/activities/clear` console command.

### `productFieldMap`

- Type: `array`
- Default: `['*' => ['title' => 'title', 'slug' => 'slug', 'status' => 'status']]`

Maps CSV column headers (left) to product properties or field handles (right). Keys at the top level are product type handles, with `'*'` matching any product type not otherwise listed.

Per-product-type entries do **not** inherit from `'*'`. The plugin picks one entry per import: the product type's own entry if it has one, otherwise `'*'`. List every column you want imported under each product type's entry, including the ones in `'*'`. For a DRY pattern, see [field maps for many product types](../recipes/field-maps-for-many-product-types.md).

Three keys have special handling:

- `title`: always treated as the product title. Required in row 2 of every CSV.
- `slug`: generated from the title for new products if missing.
- `status`: read as `enabled` (default) or `disabled`. Anything other than `disabled` (case-insensitive, trimmed) imports as enabled.

Other entries write to product custom fields by handle. See [supported field types](#supported-field-types) below.

Example per-product-type map:

```php
'productFieldMap' => [
    '*' => [
        'title' => 'title',
        'slug' => 'slug',
        'status' => 'status',
    ],
    'apparel' => [
        'title' => 'title',
        'slug' => 'slug',
        'status' => 'status',
        'careInstructions' => 'careInstructions',
        'fabricNotes' => 'fabricNotes',
    ],
],
```

### `variantFieldMap`

- Type: `array`
- Default: see above

Same shape as `productFieldMap`, but maps to variant properties or field handles. The `'*'` catch-all applies to product types not otherwise listed.

Standard cross-site variant properties:

- `title`, `enabled`, `isDefault`, `sku`, `width`, `height`, `length`, `weight`.

Per-site variant properties (use the column suffix `[siteHandle]`):

- `basePrice`, `inventoryTracked`, `availableForPurchase`, `freeShipping`, `promotable`, `minQty`, `maxQty`.

If `availableForPurchase` or `promotable` are not mapped, the import defaults them to `true` on save. This matches the standard Commerce variant behaviour.

The Variant Attributes field handle does not need to be in this map. The plugin discovers it from the product type's variant field layout.

Example variant map that adds a custom `notes` field for one product type:

```php
$defaults = [
    'title' => 'title',
    'sku' => 'sku',
    'inventoryTracked' => 'inventoryTracked',
    'price' => 'basePrice',
    'height' => 'height',
    'width' => 'width',
    'length' => 'length',
    'weight' => 'weight',
];

return [
    'variantFieldMap' => [
        '*' => $defaults,
        'apparel' => array_merge($defaults, [
            'notes' => 'notes',
            'releaseDate' => 'releaseDate',
        ]),
    ],
];
```

## Supported field types

When `productFieldMap` or `variantFieldMap` maps a column to a custom field, the import knows how to write the following types:

| Field type | CSV value format |
|------------|-----------------|
| Plain Text | Raw text. |
| Number | Raw number. |
| Lightswitch | `1` for on, anything else for off. |
| Money | Decimal value (`15.00`). The plugin multiplies by 100 and creates a Money object in the field's currency. |
| Entries | Comma-separated `sectionHandle:slug` (`articles:summer-launch,faqs:returns`). |
| Assets | Comma-separated `volumeHandle:path/to/file.jpg`. Numeric asset IDs are also accepted. |
| Other relation fields | Comma-separated slugs. |

## Multi-environment overrides

Because Variant Manager's config file is multi-environment aware, you can nest settings under environment names:

```php
return [
    '*' => [
        'attributePrefix' => 'Attribute: ',
        'activityLogRetention' => '30 days',
    ],
    'dev' => [
        'activityLogRetention' => false,
    ],
    'production' => [
        'activityLogRetention' => '90 days',
    ],
];
```

See Craft's [config files documentation](https://craftcms.com/docs/5.x/configure.html#config-files) for the resolution order.
