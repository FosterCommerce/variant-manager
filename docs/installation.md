# Installation

A Craft CMS plugin that manages Craft Commerce product variants from CSV files.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.0`
- PHP `^8.2`

## Install

From the Plugin Store, search for **Variant Manager** in **Settings -> Plugins** and press **Install**.

With Composer:

```sh
composer require fostercommerce/variant-manager
./craft plugin/install variant-manager
```

With DDEV:

```sh
ddev composer require fostercommerce/variant-manager -w && ddev craft plugin/install variant-manager
```

After install the CP navigation gets a **Variant Manager** item with **Dashboard** and **Variants**. **Variant Attributes** and **Attribute Options** appear for users with `variant-manager:manage-attributes`.

## Configure

Import and export settings live in a config file. Create `config/variant-manager.php`:

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

The plugin runs without the file. `productFieldMap` starts empty, so `slug` and `status` columns are only imported once you map them. See [configuration reference](./reference/configuration.md) for what each key controls.

Attribute display types and field layouts are set in the CP instead, at **Settings -> Plugins -> Variant Manager**, and stored in project config. See [variant attributes](./user-guide/variant-attributes.md).

## Add the Variant Attributes field

The plugin ships a **Variant Attributes** field type. Add it to every Commerce product type whose variants you want to import or export by attribute.

1. **Settings -> Fields -> New field**.
2. Set the **Field Type** to **Variant Attributes**. Give it a name and handle, for example `variantAttributes`. The field has no settings of its own.
3. **Commerce -> Settings -> Product Types -> {product type} -> Variant Fields** and drag the field into the variant field layout.

Only one Variant Attributes field per variant field layout is read by the plugin. Additional copies are ignored.

See [Variant Attributes field reference](./reference/field-type.md) for how the field stores data.

## Permissions

Grant the plugin's permissions on user groups at **Users -> {group} -> Permissions** or on individual users. `accessPlugin-variant-manager` is required to see the plugin's CP section at all.

See [permissions reference](./reference/permissions.md) for the full list.

## Console commands

```sh
./craft variant-manager/activities/clear
```

Deletes activity log entries older than `activityLogRetention`. Pass `1` to wipe every entry regardless of age. Craft's garbage collection runs the same expiry pass automatically. See [console commands](./reference/console-commands.md).

```sh
./craft variant-manager/attributes/backfill
```

Registers an attribute and option for every name and value already stored on a variant. Run this once after installing on a store that already has variant data.

```sh
./craft variant-manager/attributes/orphans
```

Lists attributes and options no longer used by any variant. Pass `--prune` to delete them.
