# Release Notes for Variant Manager

## Unreleased

### Added

- Added field sets, each a name and a pair of field layouts that any number of variant attributes share.
- Added `FieldSets` and `Plugin::getFieldSets()`.
- Added a `fieldSetUid` param to `VariantAttribute` queries.

### Changed

- An attribute's fields now come from the field set assigned to it, and its settings screen no longer has field layouts of its own.
- Pruning an orphaned attribute no longer removes field layouts from project config.
- An attribute's field set can now be assigned where `allowAdminChanges` is off, because the assignment is stored in the database rather than project config.

### Removed

- Removed `AttributeConfigs` and `Plugin::getAttributeConfigs()`. Use `Plugin::getFieldSets()`.

## 4.1.1 - 2026-09-21

### Changed

- `AttributeConfigs` is now internal. Modules register attributes and options through `VariantAttributes`.

## 4.1.0 - 2026-09-21

### Added

- Added a “New attribute” button to the Variant Attributes index and to the attribute picker in Variant Maker rows. It starts the attribute on the configured default display type. ([#48](https://github.com/FosterCommerce/variant-manager/issues/48))
- Added a “New option” button to the option picker in Variant Maker rows. ([#48](https://github.com/FosterCommerce/variant-manager/issues/48))
- Added a “Load attributes from existing variants” button to the Variant Maker.
- Added an editable “System Name” to the attribute and option create screens.
- Added a “Settings” item to the Variant Manager nav, for admins.
- Added a list of the `{Attribute Name}` tokens available in the Variant Maker title and SKU formats.
- Added a placeholder on the Variant Maker title and SKU fields showing the format that reproduces the default.
- Added a validation error when two attributes, or two options under one attribute, share a system name.
- Added `Plugin::getCsv()`.
- Added `VariantMaker::defaultFormats()`.
- Added `AttributeConfigs::getAllLayouts()`.
- Added `fostercommerce\variantmanager\helpers\PermissionHelper`.

### Changed

- Variant Manager now requires Craft Commerce 5.7.0 or later.
- Importing, the Variant Maker, “Bulk edit field”, the “Variant Attributes” section, the orphan prune, and the backfill now check Commerce's `commerce-saveProductType` permission.
- `variant-manager:manage` now gates only clearing the activity log.
- Attribute field layouts are now keyed on the attribute's UID in project config, rather than on its name.
- The default SKU format now starts from the product slug rather than the default variant's SKU.
- A typed SKU format now collapses spaces and dash runs in each token's value, the way a blank format does.
- Generating now fails when the product type has no Variant Attributes field, rather than writing variants with no attributes.
- Generating with no options picked now reports that no combination was built.
- The Variant Maker no longer prefills its rows from a product's existing variants. Use “Load attributes from existing variants”.
- “Generate variants” is now disabled while the product has unsaved changes.
- Renamed `VariantMaker::attributesInUse()` to `rowsFromVariants()`.
- `VariantMaker::settingsRows()` no longer takes a product argument.
- `AttributeConfigs::getFieldLayout()`, `getOptionFieldLayout()`, and `remove()` now take an attribute UID rather than a name key, and `save()` now takes the attribute element.

### Removed

- Removed `variant-manager:import`. Grant Commerce's `commerce-saveProductType` for importing.
- Removed `variant-manager:manage-attributes`. Commerce's `commerce-saveProductType` gates the “Variant Attributes” section.
- Removed `AttributeConfigs::getAllAttributeLayouts()` and `getAllOptionLayouts()`. Use `getAllLayouts()` instead.

### Fixed

- Fixed a bug where the Variant Maker tab was unavailable to everyone but admins.
- Fixed a bug where an attribute whose name contained a period lost its field layouts during garbage collection.
- Fixed an error that occurred when exporting or importing a product whose type has no field for a mapped handle.
- Fixed a bug where the Variant Maker did not store attribute pairs on the variants it generated. ([#49](https://github.com/FosterCommerce/variant-manager/issues/49))
- Fixed a bug where “Replace all variants” reported “Another row builds this same SKU” for a variant the same run was deleting. ([#49](https://github.com/FosterCommerce/variant-manager/issues/49))
- Fixed a bug where prefilled Variant Maker rows stopped a product from being saved. ([#50](https://github.com/FosterCommerce/variant-manager/issues/50))

## 4.0.3 - 2026-09-19

### Changed

- Upload Product, Export Product, Export Variant Data, Bulk edit field, and Clear activity logs now appear only for users with the matching permission.
- Raised the `league/csv` requirement to `^9.27`.

### Fixed

- Fixed an error that occurred during garbage collection when `activityLogRetention` was set to `null`.
- Fixed a bug where Date, Money, Assets, and Entries columns imported as plain text on stores with more than one product type.
- Fixed a bug where importing two SKUs that differ only by leading zeros deleted one of the variants.
- Fixed a bug where a disabled variant lost its per-site prices and inventory levels on import.
- Fixed an error that occurred when reimporting an export of a product with a disabled variant.
- Fixed a bug where “Replace all variants” left a disabled variant on the product.
- Fixed an error that occurred when exporting a product with a disabled variant.
- Fixed an error that could occur when exporting a product whose variants store different attributes.
- Fixed a bug where the export wrote a variant's attribute value under another attribute's column.
- Fixed a bug where “Bulk edit field” appeared on the Variants index when no configured handle matched a field on a variant.
- Fixed a bug where a SKU already used by a disabled variant on another product was not reported.
- Fixed an issue where an unreadable zip reported that the import had been queued.
- Fixed an issue where a column missing its `[siteHandle]` or `[location]` suffix failed with an unrelated message.
- Fixed an issue where uploading a filename whose ID prefix did not match a product reported a generic server error.

## 4.0.2 - 2026-09-18

### Fixed

- Fixed a failed import keeping the product it created, which blocked re-uploading the same CSV.
- Fixed a failed import losing an existing product's variants.

## 4.0.1 - 2026-09-15

### Fixed

- Fixed an error that occurred when updating to 4.0.0.

## 4.0.0 - 2026-09-15

> {tip} This release removes the `VariantAttributeOption` element type. See [upgrading to 4.x](./docs/upgrade.md) before updating.

### Added

- Added the Variant Maker, which builds a product's variants from the attributes and options you pick, with a preview of what generating would change.
- Added a “Variant Maker Product Types” setting, for choosing which product types offer the Variant Maker. No product type offers it by default.
- Added a “SKU Partial” and a “Price Modifier” to each attribute option, which the Variant Maker assembles into a generated variant's SKU and price.
- Added a `variant-manager/variant-maker/plan` command, for previewing a plan from the command line.
- Added drag ordering to the “Variant Attributes” listing, for arranging an attribute's options.
- Added a `resave/variant-attributes` command, for re-saving attributes and options and rewriting their search keywords.

### Changed

- Nested attribute options under their attribute in a single “Variant Attributes” listing, replacing the separate “Attribute Options” section.
- Renamed the read-only “CSV Name” and “CSV Value” labels to “System Name”, and the Title field on an attribute or option to “Display Name”.
- Labeled an attribute or option by its system name, followed by its display name in brackets where the two differ, and made both searchable.
- Moved attribute registration from the end of a CSV import to each variant save, so a failed import can now leave registry rows that the orphan prune clears.

### Fixed

- Fixed a bug where attribute values written outside the control panel stayed unregistered until the backfill ran.

### Removed

- Removed the `VariantAttributeOption` element type; an option is now a `VariantAttribute` with an `attributeId`, and its value is `name` rather than `value`.

## 3.0.0 - 2026-09-11

> {tip} Run `./craft variant-manager/attributes/backfill` to take advantage of new features.

### Added

- Added Variant Attribute and Attribute Option elements for the names and values your variants store, listed under “Variant Attributes” and “Attribute Options”.
- Added a display type per attribute, set on the attribute itself, and a field layout for the attribute and one for its options, set at “Settings” -> “Plugins” -> “Variant Manager” and stored in project config.
- Added element chips to the Variant Attributes field, which open an attribute or option in a slideout.
- Added a “Variant Attribute: {name}” filter for each attribute, on variant and product listings.
- Added a “Variant Attributes” utility, plus `variant-manager/attributes/backfill` and `variant-manager/attributes/orphans` commands, for populating and pruning the registry.
- Added the `variant-manager:manage-attributes` permission.
- Added `craft.variantManager.getAttributeRegistry()`, which pairs a product's attribute names and values with their elements.
- Added support for showing a variant's attribute values in its card and as an element index column.
- Added a “Bulk edit field” action to the Variants index, configured with `bulkEditableVariantFields`.
- Added `defaultVariantTableAttributes`, for extra default columns on the Variants index.
- Added an “Available Display Types” setting, at “Settings” -> “Plugins” -> “Variant Manager”, which narrows the display types an attribute can be set to. It can also be set with `availableDisplayTypes` in `config/variant-manager.php`.
- Added a “Default Display Type” setting, on the same screen, which is the display type a newly registered attribute is given. It can also be set with `defaultDisplayType`.

### Changed

- Attributes and options can no longer be deleted from the control panel. Use “Prune orphans”, which removes only the rows no variant uses.
- The `variant-manager:manage` permission now also gates bulk editing variants.
- A Money column written with thousands separators, such as `1,234.56`, now fails the import instead of being read as a smaller amount.
- `productFieldMap` and `variantFieldMap` now default to the `title`, `slug` and `status` product columns and the standard variant columns, so `config/variant-manager.php` is only needed to change them.

### Fixed

- Fixed a bug where import and export did not write variant columns without a `config/variant-manager.php` file.
- Fixed a bug where an empty `variantFieldMap` entry exported a CSV with no variant columns.
- Fixed the `src/config.php` template mapping `'price' => 'basePrice'`, which named the price column `price[default]` where the rest of the documentation says `basePrice[default]`.
- Fixed a CSV column for a standard variant field the map does not list, such as `enabled` or `isDefault`, failing the import with an unknown field error.
- Fixed a CSV with no `sku` column raising a PHP error instead of reporting the missing column.
- Fixed a renamed product column, such as `'Product Name' => 'title'`, being ignored on import and written twice on export.
- Fixed a bug where the “Export Product” button reported only the HTTP status of a failed export.
- Fixed a bug where importing a Money field could store a cent less than the CSV held, such as `19.99` becoming `19.98`, including on a straight export and reimport.
- Fixed a bug where importing a Money field in a zero-decimal currency, such as JPY, stored an amount 100 times too large.
- Fixed an error that occurred when a Money column held a value that was not a number.
- Fixed a bug where “Upload Product” failed on sites with a `cpTrigger` other than `admin`.

### Removed

- Removed inline editing of attribute values on a variant, along with the `variant-manager/product-variants/save-variant-attributes` action and its route. A value now changes for every variant using it, edited from the option's slideout, or for one variant through a CSV import.

## 2.1.0 - 2026-05-13

- Add optional `status` column for product imports and exports.
- Refactor the Export Product sidebar button.
- Set `promotable` to true by default
- Improved documentation
- Support Date fields

## 2.0.5 - 2026-02-19

- Fix an error that would be thrown when gc runs during a web request

## 2.0.4 - 2026-02-04

- Include a Variant element index view

## 2.0.3 - 2025-12-02

- Fix an issue where custom fields weren't discovered on new variant imports

## 2.0.2 - 2025-11-27

- Add generic support for any relation field other than already supported Assets and Entries fields.

## 2.0.1 - 2025-06-16

- Fix issue where inventory transactions were being created for variants which have inventory tracking disabled.

## 2.0.0 - 2025-06-05

- Release v2 with support for Craft CMS 5+ and Craft Commerce 5+.
