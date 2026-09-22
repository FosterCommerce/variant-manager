![Variant Manager](resources/img/header.png)

# Variant Manager

A Craft CMS plugin for managing Craft Commerce variants as combinations of **attributes and options**.

## Overview

- Add one Variant Attributes field to your variants instead of a hard-coded custom field per attribute (Color, Size, Material), and give each attribute and its options custom fields for swatch images, hex values, and spec sheets, from a field set that attributes share.
- Add a new attribute or option in the control panel, or by importing a CSV that names it, with no change to a field, a field layout, or a template.
- Build attribute pickers in Twig from each attribute's display type.
- Import a CSV to create or update a product and its variants, or a zip of CSVs for several products in one run.
- Generate a product's variants from the attributes and options you choose, each combination with its own SKU and price.
- Export one product to CSV from its edit page, or several products from **Commerce -> Products**.
- Filter every variant in the store from one index, and bulk edit a field across a selection.

## Use Variant Manager when

- Your product types have a custom field per attribute (Color, Size, Material), or would need one.
- The same attributes appear on more than one product type and have to stay consistent.
- Adding an option has to work without a field, field layout, or template change.
- Product data comes from spreadsheets, an ERP, or a PIM, and every route has to write the same field.
- A product's variants are every combination of its options.
- The storefront renders swatches or dropdowns from variant data.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.7.0`
- PHP `>=8.2.0`

## Install

```sh
composer require fostercommerce/variant-manager
./craft plugin/install variant-manager
```

For the full installation and configuration guide, see [installation](./docs/installation.md). If you are upgrading from an earlier version, see [upgrading](./docs/upgrade.md).

## Variant attributes

Commerce stores a variant as a unique SKU with no attribute structure, so adding an option to a hard-coded Color field means a developer edits the field's settings and a template.

Add the **Variant Attributes** field type to a product type's variant field layout instead. The field stores the name and value pairs on each variant, and a merchant adds new attributes and options without a developer. Every name and value also becomes its own element, so an attribute or an option can have a swatch image, a hex value, a spec sheet, or any custom field you add. Each attribute has a display type (dropdown, radio buttons, text buttons, image swatches, color swatches, lightswitch) that your Twig reads to render the picker.

A **field set** is a name and a pair of field layouts, one for the attribute and one for its options. The layouts hold the custom fields an attribute and its options get. Several attributes can share one field set, so Color and Trim Color use the same swatch image field without building a second layout.

For managing attributes and options, see [variant attributes](./docs/user-guide/variant-attributes.md). For what the field stores and the filter shapes it supports, see [the Variant Attributes field reference](./docs/reference/field-type.md) and [querying variants](./docs/dev-guide/twig-queries.md).

## Importing

Create or update a product and all of its variants from a spreadsheet.

Upload a CSV (or a zip of CSVs) from **Variant Manager -> Dashboard**. Each file in a zip runs as its own queue job, so a file that fails does not stop the other files. A filename starting with a product ID (`42__classic-tee.csv`, the shape every export uses) updates that product; any other filename creates a new product. Columns map to product fields, variant fields, per-site Commerce fields, inventory levels, and variant attributes. Every run writes to an activity log with its outcome and, on failure, the reason.

Uploading needs Commerce's `commerce-saveProductType` on the product type being written, the same permission every tool that changes catalog data uses.

For the upload steps, the column set, and what to do when a run fails, see [importing](./docs/user-guide/importing.md), [CSV format](./docs/user-guide/csv-format.md), [bulk import](./docs/user-guide/bulk-import.md), [the activity log](./docs/user-guide/activity-log.md), [choosing what to grant](./docs/user-guide/permissions.md), and [troubleshooting](./docs/user-guide/troubleshooting.md).

## Variant Maker

Generate a product's variants in the control panel, one for every combination of the attributes and options you pick.

Set the title, price, stock, and purchasing switches each variant starts with. Variant Maker builds each combination's SKU and price from the SKU partials and price modifiers on its options. The preview lists every combination and what generating would change, before Variant Maker writes any variant. Create the attributes and options while you set up the rows, or register them in advance.

Disabled by default for every product type.

For the settings, the preview, and the generation run, see [the Variant Maker](./docs/user-guide/variant-maker.md).

## Exporting

Get a product's variants out as a CSV you can edit and reimport.

Export one product from the **Export Product** button in its edit page sidebar, or several products with the **Export Variant Data** action on a selection at **Commerce -> Products**. A single product downloads as one CSV; multiple products download as a zip. The column headers need no editing before the file is reimported.

Exporting uses the plugin's own `variant-manager:export`, not the Commerce permission importing needs.

For the column set and the reimport path, see [exporting](./docs/user-guide/exporting.md).

## Variants index

**Variant Manager -> Variants** lists every variant in the store on its own, rather than nested under its product, so a filter applies across the whole catalog. Filters cover stock, whether inventory is tracked, and each registered attribute, on top of Commerce's own product and SKU rules.

Select variants and the actions menu offers **Bulk edit field**, which writes one value to all of them.

For the filters and the bulk edit action, see [the Variants index](./docs/user-guide/variants-index.md).

## Documentation

For the full documentation, pricing, and changelog, see the [Variant Manager plugin page](https://www.fostercommerce.com/craft-cms-plugins/variant-manager).

## License

Proprietary.

---

<a href="https://www.fostercommerce.com" target="_blank"><img src="./resources/img/foster-commerce.svg" alt="Foster Commerce" width="160" height="40"></a>
