# Upgrading

Work through every section newer than your current version, oldest first. Coming from 2.x means the 3.x, 4.x, 4.1.0, 4.2.0, and 4.3.0 sections in that order. A store on 4.0.x needs the 4.1.0, 4.2.0, and 4.3.0 sections.

## Upgrading to 4.3.0

Run `./craft up` to apply the migrations. They let the activity log store long messages, make attribute and option names that differ only in accents separate on MySQL, and clean existing attribute and option names. The name migration turns tabs, line breaks, and special spaces into plain spaces, trims each name, and matches a dotted capital `İ` as `i`. It also cleans the names and values stored on variants the same way, so the variants match the cleaned names. It rewrites only the stored values, so no variant is saved and no save event runs, and it queues a search index update for the variants it changed. It lists any record it leaves unchanged because the cleaned name matches another record. Delete that record, or move its fields to the other one. On MySQL, run `./craft variant-manager/attributes/backfill` afterward if attribute or option names use accents, so each spelling gets its own record.

### Permissions

Importing and the Variant Maker now ask Craft whether the user can save the product, the same check the product's edit page makes. A user needs `commerce-saveProductType` to update a product and `commerce-createProductType` to create one from a CSV, plus permission to edit the product's site.

Exporting now needs Commerce's `commerce-viewProductType` for each exported product's type, plus permission to edit its site on a multi-site install. Grant it to groups that export.

### CSV imports

- A filename must start with the product's ID and `__`, such as `42__classic-tee.csv`, to update that product. A file named only with the ID, such as `42.csv`, now creates a new product.
- A CSV without attribute columns leaves variant attributes alone, and an attribute the CSV has no column for stays on existing variants. To remove an attribute from every variant, use **Replace all variants**.
- A missing `availableForPurchase` or `promotable` column no longer resets existing variants to `1`, and an empty `enabled` cell now enables the variant.
- Headers match regardless of letter case. A `Title` column that earlier versions ignored now sets variant titles, and a blank cell on a variant row resets that variant's title. An import fails when two columns map to the same field, or a header matches two keys that differ only in letter case, such as `Title` and `title`. Rename one key to fix it. See [the CSV format](./user-guide/csv-format.md#column-reference).
- SKUs match regardless of letter case, as Commerce compares them. A row with `abc-1` updates the existing variant `ABC-1` and gives its SKU the CSV's letter case. Two rows that differ only in letter case fail as duplicates.
- An import fails on an empty SKU or price, a price that isn't written like `1299.00` or `1,299.00` (such as `$20` or `12,50`), a CSV that creates variants without a price column, an inventory column that doesn't name one of the six totals an export writes, a quantity that isn't a whole number, or two sites sharing a Commerce store with different per-site values on a row.
- A Lightswitch field cell accepts `true`, `yes`, and `on`, and an empty cell sets the field's default instead of off.
- Imports and Variant Maker runs now write one at a time on every queue. One that waits more than a minute for another fails, and the activity log records why.

### Display types

An empty `availableDisplayTypes` list offers only Dropdown, where it offered every display type before. The default is `['*']`, which offers all six. If `config/variant-manager.php` sets `'availableDisplayTypes' => []`, change it to `['*']` to keep every type.

If `defaultDisplayType` names a type that `availableDisplayTypes` doesn't offer, newly registered attributes get the first offered type. **Default Display Type** at **Variant Manager -> Settings** shows a warning when this happens, unless `config/variant-manager.php` sets it. To keep that default, add its type to **Available Display Types** and save. If `config/variant-manager.php` sets either setting, change it there.

### Variant Maker

- Where a store has more than one inventory location, the Variant Maker needs a location chosen before it writes stock. Open the product's **Variant Maker** tab, pick an **Inventory location**, and save the product.
- A Variant Maker price saved with a currency symbol, such as `$20`, shows an error on the product until you retype it as a number.
- **Generate variants** is disabled on a named product draft. Apply the draft, then generate from the product.

### Attributes and filters

- Options can no longer move to another attribute. To put an option under another attribute, delete it once no variant uses it, then create it again there.
- On MySQL, `variantAttributes()` filters now match names and values case-sensitively, as on Postgres, and `%` and `_` match literally. Check that storefront filters pass names and values in their stored case.
- The Variant Attribute condition rule now ignores letter case, so a catalog pricing rule or other condition that selects `Red` also matches variants storing `red`. Deleting an option is blocked while a variant stores its value in any letter case, on any site, or in any Variant Attributes field.

### PHP API

`Csv::export()` now takes a `Product` instead of an ID, and throws rather than returning `false`. `Import::fromFilename()` is removed; use `Import::fromCsvData()`. `VariantAttributes::getAllAttributesWithOptions()`, `VariantAttribute::setOptions()`, and `VariantAttribute::nameTakenUnder()` are removed.

## Upgrading to 4.2.0

Run `./craft up` to apply the migration. Templates and the CSV format are unchanged.

An attribute's field layouts move into a **field set**, a named pair of layouts that any number of attributes share. The migration gives every attribute with saved field layouts its own field set, named after the attribute, so every attribute keeps the fields it had. Each field set also gets a handle, derived from the attribute's name. Change it at **Variant Manager -> Settings**.

Where several attributes should share one set, open each attribute at **Variant Manager -> Variant Attributes** and choose the set you are keeping under **Field Set** in its sidebar, then delete the field sets you no longer need. A field set cannot be deleted while an attribute uses it.

Run the migration in development, where it writes the field sets to project config. Run `./craft project-config/write` afterwards, then commit `config/project/`. Where `allowAdminChanges` is off, the migration assigns each attribute its field set but does not write to project config, so the deployed `config/project/` files supply the field sets.

The per-attribute settings screen at `variant-manager/attributes/<id>/settings` is removed. Choose an attribute's field set on the attribute itself.

`AttributeConfigs` is removed. It was marked internal in 4.1.1; field sets are read and written through `FieldSets`.

| 4.1.1 | 4.2.0 |
| --- | --- |
| `Plugin::getAttributeConfigs()` | `Plugin::getFieldSets()` |
| `AttributeConfigs::getFieldLayout($attributeUid)` | `FieldSets::getFieldSetByUid($attribute->fieldSetUid)?->getFieldLayout()` |
| `AttributeConfigs::getOptionFieldLayout($attributeUid)` | `FieldSets::getFieldSetByUid($attribute->fieldSetUid)?->getOptionFieldLayout()` |
| `AttributeConfigs::getAllLayouts()` | `FieldSets::getAllLayouts()` |
| `AttributeConfigs::save($attribute, $layout, $optionLayout)` | `FieldSets::save($fieldSet)` |
| `AttributeConfigs::remove($attributeUid)` | `FieldSets::delete($fieldSetUid)` |

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

In 4.1.1, `AttributeConfigs` is internal. 4.2.0 removes it. For the calls a module should use, see [writing to the registry](./dev-guide/registry-api.md).

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

