# Upgrading

Upgrade one major at a time, in order. Coming from 2.x to 4.x means working through both sections below, starting with 3.x.

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

A query against the dropped options table raises a SQL error. A query filtering on the old element type string returns no rows instead, which is the silent one to look for. Check integrations that read the database directly.

### Options moved under their attribute

Options are children of their attribute at **Variant Manager -> Variant Attributes**. The separate **Attribute Options** section is gone. The listing opens in structure view, where an attribute's options can be dragged into order. Twig returns them in that order, so the drag order is the order a storefront renders.

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

4.0.0 adds the [Variant Maker](./user-guide/variant-maker.md). No product type offers it until you turn it on at **Settings -> Plugins -> Variant Manager**, so an upgrade leaves every product type as it was.

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

### Grant the new permission

Existing user groups do not have `variant-manager:manage-attributes`. Grant it at **Users -> {group} -> Permissions** to anyone who needs to see the **Variant Attributes** section or run the utility. See [permissions](./reference/permissions.md).

### Set field layouts in development

An attribute's two field layouts are project config. Set them in your development environment and deploy them. The screen is read-only where `allowAdminChanges` is off, so they cannot be set in production directly.
