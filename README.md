![Variant Manager](resources/img/header.png)

# Variant Manager

A Craft CMS plugin that imports and exports Craft Commerce product **variants** from CSV files.

## Overview

- Imports a CSV to create or update a Craft Commerce product and its variants.
- Bulk-imports many products at once from a zip of CSVs, each file becoming its own product.
- Exports a product to CSV from the product edit page, or many products at once from the Commerce products index.
- Adds a **Variant Attributes** field that stores option name and value pairs (Color, Size, Material) on each variant for filtering on the storefront.
- Lets you build a color picker or size swatch in Twig, from swatch images, spec sheets or notes you attach to any attribute value.
- Sets one field on many variants at once from the Variants index.
- Logs each import and export, with configurable retention, in a dashboard activity feed.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.0`
- PHP `^8.2`

## Install

```sh
composer require fostercommerce/variant-manager
./craft plugin/install variant-manager
```

See [`docs/installation.md`](./docs/installation.md) for the full installation and configuration guide, and [`docs/upgrade.md`](./docs/upgrade.md) if you are coming from 2.x.

## Importing

Upload a CSV (or a zip of CSVs) from **Variant Manager -> Dashboard**. The CSV's filename determines the product: a new filename creates a new product, an existing product title updates that product. Each row becomes one variant. Columns map to product fields, variant fields, per-site Commerce fields, inventory levels, and variant attributes.

See [`docs/user-guide/importing.md`](./docs/user-guide/importing.md) and [`docs/user-guide/csv-format.md`](./docs/user-guide/csv-format.md).

## Exporting

Two ways to export: the sidebar **Export Product** button on a product's edit page, or the **Export Variant Data** action on a multi-select at **Commerce -> Products**. A single product downloads as one CSV; multiple products download as a zip. Exported CSVs are shaped so they can be reimported without edits to the column headers.

See [`docs/user-guide/exporting.md`](./docs/user-guide/exporting.md).

## Variant attributes

The plugin ships a **Variant Attributes** field type that you add to each product type's variant field layout. It stores the name and value pairs from your CSV (Color: Red, Size: Small) as JSON on the variant. Twig reads them for variant selectors and faceted filtering.

Every name and value your variants use also gets its own element. Attach a swatch image, a spec sheet or a note to it. Pick how a storefront renders it: dropdown, radio buttons, text buttons, image swatches, color swatches or lightswitch. Imports and exports are unchanged.

See [`docs/user-guide/variant-attributes.md`](./docs/user-guide/variant-attributes.md) and [`docs/reference/field-type.md`](./docs/reference/field-type.md).

## Permissions

In addition to `accessPlugin-variant-manager`:

- `variant-manager:import`, upload CSVs and create or update products and variants.
- `variant-manager:export`, export products from the product edit page or the Commerce products index.
- `variant-manager:manage`, clear the activity log and bulk edit variant fields.
- `variant-manager:manage-attributes`, view and edit variant attributes and their options.

See [`docs/reference/permissions.md`](./docs/reference/permissions.md).

## License

Proprietary.

## Documentation

See [`docs/index.md`](./docs/index.md).

## Credits

Brought to you by [Foster Commerce](https://fostercommerce.com).
