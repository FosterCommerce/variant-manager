# Upgrading

Work through every section newer than your current version, oldest first. Coming from 2.x means the 3.x, 4.x, and 4.1.0 sections in that order. A store on 4.0.x needs only the 4.1.0 section.

## Upgrading to 4.1.0

4.1.0 requires Craft Commerce 5.7.0 or later, which is where `commerce-saveProductType` was introduced. Composer refuses the update on an earlier Commerce.

Run `./craft up` to apply the migration. Templates and the CSV format are unchanged.

The migration writes to project config: it re-keys each attribute's field layouts and revokes the removed permissions from every user group. Run `./craft project-config/write` afterwards, then commit `config/project/`.

An environment that applies the old config keeps the old layout keys and grants the removed permissions back, so no attribute or option shows its custom fields.

### Permissions

Everything that changes catalog data now checks Commerce's `commerce-saveProductType`. For the full list, see [permissions reference](./reference/permissions.md).

`variant-manager:import` and `variant-manager:manage-attributes` are removed. `variant-manager:manage` now gates only clearing the activity log.

The migration revokes both removed permissions and grants no replacement, because catalog access now comes from Commerce. Grant `commerce-saveProductType` per product type to anyone who imports or generates variants.

A group with `variant-manager:import` and no Commerce permission cannot use the tools until you grant it `commerce-saveProductType`.

The Variant Maker previously checked `commerce-editProductType`, which Commerce 5 removed.

### Service methods changed

| 4.0.3 | 4.1.0 |
| --- | --- |
| `VariantMaker::attributesInUse($product)` | `VariantMaker::rowsFromVariants($product)` |
| `VariantMaker::settingsRows($product, $settings)` | `VariantMaker::settingsRows($settings)` |
| `AttributeConfigs::getFieldLayout($nameKey)` | `AttributeConfigs::getFieldLayout($attributeUid)` |
| `AttributeConfigs::getOptionFieldLayout($nameKey)` | `AttributeConfigs::getOptionFieldLayout($attributeUid)` |
| `AttributeConfigs::save($nameKey, $layout, $optionLayout)` | `AttributeConfigs::save($attribute, $layout, $optionLayout)` |
| `AttributeConfigs::remove($nameKey)` | `AttributeConfigs::remove($attributeUid)` |

`rowsFromVariants()` returns each attribute with the options that product's variants store.

`getFieldLayout()`, `getOptionFieldLayout()`, and `remove()` take a string, so a call still passing a name key runs without error and reads the wrong branch of project config. A call passing one to `save()` raises a `TypeError`.

`AttributeConfigs` is internal. For the calls a module should use, see [writing to the registry](./dev-guide/registry-api.md).

### Variants generated before 4.1.0

The Variant Maker did not store attribute pairs on the variants it created in 4.0.0 through 4.0.3. The attribute filters and the registry exclude those variants, and the Variant Maker cannot match them, so no mode updates one. Add and Update both plan a new variant for the combination and leave the old one in place. The old variant still holds that SKU, so the run stops with "Another row builds this same SKU".

The preview names them: each one is a Create row with that warning.

Replace mode repairs them by deleting the unmatched variant and creating the combination again. Where Replace is too broad for the product, delete those variants and generate again.

## Upgrading to 4.x

Twig using `getAttributeOptions` or `getAttributeRegistry` keeps working. PHP that names an option element directly needs changing.

### Attribute options are no longer their own element type

`VariantAttributeOption` is removed. An option is a `VariantAttribute` with an `attributeId`, and its value is `name` rather than `value`.

| 3.x | 4.x |
| --- | --- |
| `VariantAttributeOption::find()` | `VariantAttribute::find()->attributeId($attributeId)` |
| `$option->value` | `$option->name` |
| `variant_manager_attribute_options` table | `variant_manager_attributes`, with `attributeId` set |

`variantQueryForOption()`, `variantCountForOption()`, and `isOptionInUse()` take a `VariantAttribute`.

Element IDs and UIDs are unchanged, so any reference to an option still resolves.

A query against the dropped options table raises a SQL error. A query filtering on the old element type string returns an empty result instead, which is the silent one to look for. Check integrations that read the database directly.

### Options moved under their attribute

Options are children of their attribute at **Variant Manager -> Variant Attributes**. The separate **Attribute Options** section is gone. The listing opens in structure view, where an attribute's options can be dragged into order. That order applies to the listing. A storefront picker built from `getAttributeOptions()` or `getAttributeRegistry()` renders each attribute's values in the order the product's variants store them.

### After updating

Run migrations:

```sh
./craft up
```

Then rewrite search keywords, so existing attributes and options are searchable by their system name:

```sh
./craft resave/variant-attributes --update-search-index
```

If other code writes attribute values to variants outside the control panel, run the backfill once. On 3.x those values stayed unregistered until it ran; from 4.0.0 on, every variant save registers them.

```sh
./craft variant-manager/attributes/backfill
```

4.0.0 adds the [Variant Maker](./user-guide/variant-maker.md). No product type offers it until you turn it on at **Variant Manager -> Settings**, so an upgrade leaves every product type as it was.

## Upgrading to 3.x

Existing templates keep working. This page is the setup the update needs, plus one change to the Variant Attributes field.

### Editing in the Variant Attributes field moved

The field showed an editable box per value with a **Save Attributes** button. Both are gone, along with the `variant-manager/product-variants/save-variant-attributes` action and its route.

Names and values are now chips. Click one to open the attribute or option in a slideout and edit it there, including any custom fields you have added.

Editing there is global: renaming an option changes what every product using that value shows, with no import and no change to any variant. To change the value the CSV writes, edit the CSV and reimport.

### After updating

Run migrations and project config changes:

```sh
./craft up
```

Then run the backfill once:

```sh
./craft variant-manager/attributes/backfill
```

It reads every variant and registers the names and values already stored on them. Safe to re-run.

This is a catch-up for data that predates 3.x. On 3.x, imports and control panel saves register what they store; values written by an integration stay unregistered until the backfill runs again.

### Grant the attributes permission

3.x added `variant-manager:manage-attributes`, which existing user groups do not have. Grant it at **Users -> {group} -> Permissions** to anyone who needs the **Variant Attributes** section or the utility. Upgrading straight to 4.1.0 removes this permission again, so skip this step and read [Permissions](#permissions) under 4.1.0 instead.

### Set field layouts in development

An attribute's two field layouts are project config. Set them in your development environment and deploy them. The screen is read-only where `allowAdminChanges` is off, so they cannot be set in production directly.
