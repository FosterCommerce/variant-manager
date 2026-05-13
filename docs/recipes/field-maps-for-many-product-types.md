# Recipe: field maps for a catalog with many product types

How to organise `productFieldMap` and `variantFieldMap` on a store with a dozen product types that each have their own custom fields on top of a shared core. Audience: developers maintaining `config/variant-manager.php` on a multi-product-type Commerce site.

## The gotcha to know first

The plugin resolves `$map[$productTypeHandle] ?? $map['*']`. **There is no merge.** If a product type handle is listed in the map, only that entry is used; `'*'` is a fallback for unlisted types, not a base layer.

This means: as soon as you add one custom field for one product type, the entry for that product type must list **every other column** you want imported too, not just the new field. The wrong instinct is to add `'apparel' => ['careInstructions' => 'careInstructions']` thinking it gets merged onto the `'*'` defaults; in reality it replaces them, and `title`, `sku`, `basePrice`, etc. stop importing for `apparel` products.

## The pattern

Define shared field groups as PHP arrays at the top of the file, then spread them into each per-product-type entry. The file stays DRY, and every entry is explicit about its full field set so the no-merge behaviour cannot surprise you.

```php
<?php

$sharedProductFields = [
    'title' => 'title',
    'slug' => 'slug',
    'status' => 'status',
    'productCategory' => 'productCategory',
    'additionalSearchKeywords' => 'additionalSearchKeywords',
];

$contentProductFields = [
    'alternateHeading' => 'alternateHeading',
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
        'boatLetters' => [
            ...$sharedProductFields,
            ...$contentProductFields,
            'letterHeight' => 'letterHeight',
            'letterWidth' => 'letterWidth',
            'specialChars' => 'specialChars',
        ],
        'customHitchCover' => [
            ...$sharedProductFields,
        ],
        'customIcons' => [
            ...$sharedProductFields,
            'iconImage' => 'iconImage',
            'iconWidth' => 'iconWidth',
            'iconHeight' => 'iconHeight',
        ],
        'licensePlateFrames' => [
            ...$sharedProductFields,
            'platePrice' => 'platePrice',
            'plateLettersLowercase' => 'plateLettersLowercase',
            'plateLettersUppercase' => 'plateLettersUppercase',
            'letterStyle' => 'letterStyle',
            'bulkPricing' => 'bulkPricing',
        ],
    ],
    'variantFieldMap' => [
        '*' => $sharedVariantFields,
        'boatLetters' => [
            ...$sharedVariantFields,
            'insertColor' => 'insertColor',
        ],
        'customHitchCover' => [
            ...$sharedVariantFields,
            'hitchCoverImage' => 'hitchCoverImage',
            'finish' => 'finish',
        ],
        'customIcons' => $sharedVariantFields,
        'licensePlateFrames' => [
            ...$sharedVariantFields,
            'productImages' => 'productImages',
        ],
    ],
];
```

A few choices worth calling out:

- **`$sharedProductFields` and `$sharedVariantFields`** carry the columns that every CSV in the catalog has (title, sku, status, price, dimensions). Touch these to add a column everywhere.
- **`$contentProductFields`** is a second group for product types that have content-page fields (heading, images, body). Product types that are not editable content pages (a hitch-cover variant catalog) skip it.
- **Per-product-type entries** spread the shared groups, then list each type's own custom fields explicitly. The right side of the arrow is the field handle on the product or variant; the left side is the CSV column header.
- **Aliases**: in the variant map, `'price' => 'basePrice'` lets the CSV use a friendlier column name (`price[default]`) while writing to Commerce's `basePrice` property. The shared group is the right place to keep a rename like this so every product type benefits.
- **A bare `$sharedVariantFields`** as the value is fine for product types that need nothing extra (`customIcons`, `general`). Spread is only needed when adding to the shared set.

## Adding a new product type

1. Add a new entry under `productFieldMap` keyed by the product type's handle.
2. Spread the shared group(s) the type needs.
3. List any custom product field handles the type adds.
4. Repeat for `variantFieldMap` if the variants of the type have custom fields.

Do not rely on the `'*'` fallback for a product type that has custom fields, because spreading the shared group plus the custom fields is the only way to import both.

## Adding a column to every product type

Add the column to the relevant shared group at the top of the file. Every entry that spreads that group picks it up on the next deploy. No per-entry edits.

## When to leave a product type out of the map

If a product type's CSV only uses the core columns (`title`, `sku`, `basePrice`, etc.), do not add an entry for it. The `'*'` fallback handles it.

Add an entry only when the product type needs at least one custom field. Once you do, that entry replaces `'*'` for that product type; remember to spread the shared groups in.

## Related

- [Configuration reference](../reference/configuration.md), every config key with defaults.
- [CSV format: column reference](../user-guide/csv-format.md#column-reference), how CSV headers map to product and variant fields.
