# Installation

A Craft CMS plugin for managing Craft Commerce variants as combinations of **attributes and options**.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.0`
- PHP `>=8.2.0`

## Install

In the control panel, go to **Plugin Store**, search for **Variant Manager**, and press **Install**.

With Composer:

```sh
composer require fostercommerce/variant-manager
./craft plugin/install variant-manager
```

With DDEV:

```sh
ddev composer require fostercommerce/variant-manager -w && ddev craft plugin/install variant-manager
```

After install the CP navigation gets a **Variant Manager** item with **Dashboard** and **Variants**. **Variant Attributes** appears for users with `variant-manager:manage-attributes`.

## Configure

Every setting has a default, so the plugin runs without a config file.

### In a config file

Create `config/variant-manager.php` to change any of these. The file is multi-environment aware, the same as `general.php`.

| Key | Default | Controls |
|-----|---------|----------|
| `emptyAttributeValue` | `''` | What an empty attribute cell writes. |
| `attributePrefix` | `'Attribute: '` | The CSV header prefix for attribute columns. |
| `inventoryPrefix` | `'Inventory'` | The CSV header prefix for inventory columns. |
| `activityLogRetention` | `'30 days'` | How long activity log rows are kept. |
| `productFieldMap` | title, slug, status | Which CSV columns map to which product fields. |
| `variantFieldMap` | title, sku, inventoryTracked, basePrice, height, width, length, weight | Which CSV columns map to which variant fields. |
| `defaultVariantTableAttributes` | `[]` | Extra columns on the Variants index. |
| `bulkEditableVariantFields` | `[]` | Which fields the Bulk edit action can set. Empty hides the action. |
| `availableDisplayTypes` | `[]` | Which display types an attribute can use. Empty allows all six. |
| `defaultDisplayType` | `'dropdown'` | The display type a newly registered attribute gets. |
| `variantMakerProductTypes` | `[]` | Which product types show the Variant Maker tab. Empty hides it everywhere. |

`src/config.php` ships a copy to start from. It sets `activityLogRetention` to `'1 week'`, while the plugin's own default is `'30 days'`.

For what each key does in full, see [configuration reference](./reference/configuration.md).

### In the control panel

**Settings -> Plugins -> Variant Manager** has three settings and requires an admin account:

- **Available Display Types**, which display types an attribute can be given.
- **Default Display Type**, what a newly registered attribute starts as.
- **Variant Maker Product Types**, where the Variant Maker tab appears.

A config file entry overrides the matching screen setting, and the screen shows a warning where one does.

The attribute table at the bottom of the same screen opens each attribute's own page, where **Attribute Fields** and **Option Fields**, the two field layouts, are set. Those are stored in project config too. See [variant attributes](./user-guide/variant-attributes.md).

One more is set elsewhere: an attribute's **display type**, on the attribute itself at **Variant Manager -> Variant Attributes**, stored in the database.

## Add the Variant Attributes field

The plugin ships a **Variant Attributes** field type. Add it to every Commerce product type whose variants have attributes.

1. **Settings -> Fields -> New field**.
2. Set the **Field Type** to **Variant Attributes**. Give it a name and handle, for example `variantAttributes`. The field has no settings of its own.
3. **Commerce -> Settings -> Product Types -> {product type} -> Variant Fields** and drag the field into the variant field layout.

The plugin reads one Variant Attributes field per variant field layout, and ignores any others.

For how the field stores data, see [Variant Attributes field reference](./reference/field-type.md).

## Permissions

Grant the plugin's permissions on user groups at **Users -> {group} -> Permissions** or on individual users. `accessPlugin-variant-manager` is required to see the plugin's CP section at all.

For the full list, see [permissions reference](./reference/permissions.md).

## Console commands

```sh
./craft variant-manager/activities/clear
```

Deletes activity log entries older than `activityLogRetention`.

```sh
./craft variant-manager/attributes/backfill
```

Registers an attribute and option for every name and value already stored on a variant.

```sh
./craft variant-manager/attributes/orphans
```

Lists attributes and options no longer used by any variant.

For every argument and flag, see [console commands](./reference/console-commands.md).
