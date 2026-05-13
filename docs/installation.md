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
ddev composer require fostercommerce/variant-manager -w && ddev exec php craft plugin/install variant-manager
```

After install you will see a **Variant Manager** item in the CP navigation with two subnav entries: **Dashboard** and **Variants**.

## Configure

Settings live in a config file, not the Control Panel. Create `config/variant-manager.php`:

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

Every key has a default; the plugin runs without the file. See [configuration reference](./reference/configuration.md) for what each key controls.

## Add the Variant Attributes field

The plugin ships a **Variant Attributes** field type. Add it to every Commerce product type whose variants you want to import or export by attribute.

1. **Settings -> Fields -> New field**.
2. Set the **Field Type** to **Variant Attributes**. Give it a name and handle, for example `variantAttributes`. The field has no settings of its own.
3. **Commerce -> Settings -> Product Types -> {product type} -> Variant Fields** and drag the field into the variant field layout.

Only one Variant Attributes field per variant field layout is read by the plugin. Additional copies are ignored.

See [Variant Attributes field reference](./reference/field-type.md) for how the field stores data.

## Permissions

Grant the plugin's permissions on user groups in **Users -> {group} -> Permissions** or on individual users:

- `variant-manager:import`, upload CSVs and create or update products and variants.
- `variant-manager:export`, export products from the product edit page or the variants index.
- `variant-manager:manage`, clear the activity log and manage plugin data.

`accessPlugin-variant-manager` is required to see the plugin's CP section at all.

See [permissions reference](./reference/permissions.md).

## Console commands

```sh
./craft variant-manager/activities/clear
```

Deletes activity log entries older than `activityLogRetention`. Pass `--all` to wipe every entry regardless of age. Craft's garbage collection runs the same expiry pass automatically. See [console commands](./reference/console-commands.md).
