# Release Notes for Variant Manager

## 3.0.0 - 2026-09-08

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
- Added an **Available Display Types** setting, at “Settings” -> “Plugins” -> “Variant Manager”, which narrows the display types an attribute can be set to. It can also be set with `availableDisplayTypes` in `config/variant-manager.php`.
- Added a **Default Display Type** setting, on the same screen, which is the display type a newly registered attribute is given. It can also be set with `defaultDisplayType`.

### Changed

- Attributes and options can no longer be deleted from the control panel. Use “Prune orphans”, which removes only the rows no variant uses.
- The `variant-manager:manage` permission now also gates bulk editing variants.
- A Money column written with thousands separators, such as `1,234.56`, now fails the import instead of being read as a smaller amount.

### Fixed

- Fixed import and export producing no variant columns without a `config/variant-manager.php` file. `productFieldMap` and `variantFieldMap` now default to the `title`, `slug` and `status` product columns and the standard variant columns, so the file is only needed to change them.
- Fixed an empty `variantFieldMap` entry writing a CSV with no variant columns. Import and export now fail with “No variant fields are mapped”.
- Fixed the `src/config.php` template mapping `'price' => 'basePrice'`, which named the price column `price[default]` where the rest of the documentation says `basePrice[default]`.
- Fixed a CSV column for a standard variant field the map does not list, such as `enabled` or `isDefault`, failing the import with an unknown field error.
- Fixed a CSV with no `sku` column raising a PHP error instead of reporting the missing column.
- Fixed a renamed product column, such as `'Product Name' => 'title'`, being ignored on import and written twice on export.
- Fixed the **Export Product** button reporting only the HTTP status. It now shows the message the server returned.
- Fixed a bug where importing a Money field could store a cent less than the CSV held, such as `19.99` becoming `19.98`, including on a straight export and reimport.
- Fixed a bug where importing a Money field in a zero-decimal currency, such as JPY, stored an amount 100 times too large.
- Fixed an error that occurred when a Money column held a value that was not a number.

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
