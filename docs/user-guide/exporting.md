# Exporting

Get a CSV out of Craft Commerce so you can edit it in a spreadsheet and reimport. Audience: anyone maintaining product data.

## Two ways to export

- **One product at a time**: from the product edit page. Useful when you want to edit a single product's variants.
- **Many products at once**: from the **Variants** element index, using a multi-select action. Useful for catalog-wide updates.

## Exporting one product

1. **Commerce -> Products** and open the product you want to export.
2. In the right-hand sidebar at the bottom of the edit page, click **Export Product**.

   ![Screenshot](../../resources/img/export-product.png)
3. The file downloads automatically as `{id}__{slug}.csv` (for example `42__classic-tee.csv`).

That filename is important: when you reupload it, Variant Manager uses the `{id}` part before `__` to match the file back to the same product even if you have renamed the product since exporting. Do not rename the file before reuploading.

## Exporting many products

1. **Variant Manager -> Variants**, which is the plugin's variants element index.
2. Filter or search to narrow down the variants you want.
3. Select the variants you want to export. Use the checkbox in the table header to select everything visible.
4. Open the actions menu and choose **Export Variant Data**.

Variant Manager finds the products that own those variants and exports one CSV per product:

- **One product**: a single CSV downloads, named the same way as the single-product export.
- **Multiple products**: a zip downloads, named `products_{YmdHis}.zip` (for example `products_20260513142301.zip`). Each CSV inside is named `{id}__{slug}.csv`.

You can also trigger this action from the standard **Commerce -> Products -> Variants** element index, since the plugin registers itself on the same element type.

## What is in the exported CSV

Exported CSVs are shaped so they can be reimported as-is after edits to cell values.

Column order:

1. Product fields from `productFieldMap` (title, slug, status, plus anything custom).
2. Variant fields from `variantFieldMap` (sku, height, width, length, weight, and so on).
3. Per-site Commerce variant fields suffixed with `[siteHandle]` (`basePrice[default]`, `inventoryTracked[default]`, `availableForPurchase[default]`, `freeShipping[default]`, `promotable[default]`, `minQty[default]`, `maxQty[default]`), one column per site in the store.
4. Inventory columns for each inventory location, all six totals per location (`Inventory[location]: available`, `committed`, `reserved`, `damaged`, `safety`, `qualityControl`).
5. Variant Attribute columns prefixed with `Attribute: ` (one per attribute on the product).

A few notes on the export content:

- The first row after the header is the product row. It carries the product's title, slug, status, and any product field values.
- Each following row is one variant.
- Disabled products and variants are included; the `status` column reads `disabled` for them. Removing the `status` entry from `productFieldMap` skips the column.
- Variants with `inventoryTracked` on get their inventory totals filled in; untracked variants have empty cells in the inventory columns.
- The `stock` column, if you have it mapped, is left empty for tracked variants since Commerce's inventory levels manage stock instead.

See [configuration reference](../reference/configuration.md) for changing the field maps.

## Editing and reimporting

Steps:

1. Export the product or products.
2. Open the CSV in your spreadsheet app.
3. Edit values. Add new variant rows for new variants; delete rows for variants you want removed.
4. Save the file. Keep the original filename (`{id}__{slug}.csv`).
5. Upload from **Variant Manager -> Dashboard** -> **Upload Product**.

When you reupload an existing product:

- The modal will recognise it as an existing product and ask whether to **Update and remove extra variants** (default) or **Replace all variants**.
- Choose **Update and remove extra variants** for the round-trip workflow. Any variant whose SKU is in the CSV gets updated; any variant whose SKU is missing gets deleted.

See [importing](./importing.md#existing-product-update-options) for the difference between the two refresh options.

## Common gotchas

- **Renaming an export file**: do not. Renaming `42__classic-tee.csv` to anything else makes Variant Manager treat it as a brand-new product and will probably fail with "One or more SKUs already exist".
- **Editing column headers**: do not rename the column headers. The plugin maps columns by header text; changing `basePrice[default]` to `Price` will leave prices unchanged on reimport.
- **Reordering columns**: safe. The plugin matches columns by header, not position.
- **Adding new attribute columns**: safe. Add a new `Attribute: Material` column with values and reimport.
- **Removing attribute columns**: removes that attribute from every variant.
- **Adding a row with a new SKU**: creates a new variant on reimport.
- **Removing a row**: deletes that variant on reimport (under the default Refresh variants choice).
