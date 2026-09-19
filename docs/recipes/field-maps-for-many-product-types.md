# Recipe: field maps for a catalog with many product types

How to organize `productFieldMap` and `variantFieldMap` on a store with a dozen product types that each have their own custom fields on top of a shared core.

## The rule to know first

The plugin resolves `$map[$productTypeHandle] ?? $map['*']`. **There is no merge.** If a product type handle is listed in the map, only that entry is used; `'*'` is a fallback for unlisted types, not a base layer.

This means: as soon as you add one custom field for one product type, the entry for that product type must list **every other column** you want imported too. Adding `'apparel' => ['careInstructions' => 'careInstructions']` replaces the `'*'` entry for apparel rather than adding to it.

What that costs differs by map. In `productFieldMap`, the omitted columns stop importing, so `slug` and `status` are dropped. In `variantFieldMap`, the standard variant properties (`title`, `sku`, dimensions, and the per-site ones) are re-added under their own handles, so they keep importing. What you lose there is custom variant fields and any column alias: a `'price' => 'basePrice'` rename stops applying, so a `price[default]` column is ignored and the CSV has to use `basePrice[default]`.

## The pattern

Define shared field groups as PHP arrays at the top of the file, then spread them into each per-product-type entry. The file has no repeated blocks, and every entry is explicit about its full field set so the no-merge behavior applies.

```php
<?php

$sharedProductFields = [
    'title' => 'title',
    'slug' => 'slug',
    'status' => 'status',
    'productCategory' => 'productCategory',
];

$contentProductFields = [
    'productImages' => 'productImages',
    'productContent' => 'productContent',
];

$sharedVariantFields = [
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
    'productFieldMap' => [
        '*' => [
            ...$sharedProductFields,
            ...$contentProductFields,
        ],
        'apparel' => [
            ...$sharedProductFields,
            ...$contentProductFields,
            'careInstructions' => 'careInstructions',
            'fabricWeight' => 'fabricWeight',
        ],
        'drinkware' => [
            ...$sharedProductFields,
        ],
        'posters' => [
            ...$sharedProductFields,
            'paperStock' => 'paperStock',
        ],
    ],
    'variantFieldMap' => [
        '*' => $sharedVariantFields,
        'apparel' => [
            ...$sharedVariantFields,
            'fit' => 'fit',
        ],
        'drinkware' => [
            ...$sharedVariantFields,
            'dishwasherSafe' => 'dishwasherSafe',
        ],
        'posters' => $sharedVariantFields,
    ],
];
```

A few choices worth calling out:

- **`$sharedProductFields` and `$sharedVariantFields`** list the columns that every CSV in the catalog has (title, sku, status, price, dimensions). Touch these to add a column everywhere.
- **`$contentProductFields`** is a second group for product types that have content-page fields (heading, images, body). Product types that are not editable content pages skip it.
- **Per-product-type entries** spread the shared groups, then list each type's own custom fields explicitly. The right side of the arrow is the field handle on the product or variant; the left side is the CSV column header.
- **Aliases**: in the variant map, `'price' => 'basePrice'` lets the CSV use a friendlier column name (`price[default]`) while writing to Commerce's `basePrice` property. The shared group is the right place to keep a rename like this so every product type benefits.
- **A bare `$sharedVariantFields`** as the value is fine for product types that need no extra fields (`posters`). Spread is only needed when adding to the shared set.

## Adding a new product type

1. Add a new entry under `productFieldMap` keyed by the product type's handle.
2. Spread the shared groups the type needs.
3. List any custom product field handles the type adds.
4. If the variants of the type have custom fields, repeat for `variantFieldMap`.

Do not rely on the `'*'` fallback for a product type that has custom fields, because spreading the shared group plus the custom fields is the only way to import both.

## Adding a column to every product type

Add the column to the relevant shared group at the top of the file. Every entry that spreads that group includes it on the next deploy. No per-entry edits.

## When to leave a product type out of the map

If a product type's CSV only uses the core columns (`title`, `sku`, `basePrice`, etc.), do not add an entry for it. The `'*'` fallback handles it.

Add an entry only when the product type needs at least one custom field. Once you do, that entry replaces `'*'` for that product type. Spread the shared groups in.

## Related

- [Configuration reference](../reference/configuration.md), every config key with defaults.
- [CSV format: column reference](../user-guide/csv-format.md#column-reference), how CSV headers map to product and variant fields.
