# Exporting

Get a CSV out of Craft Commerce so you can edit it in a spreadsheet and reimport.

## Two ways to export

- **One product at a time**: from the product edit page, for editing one product's variants in a spreadsheet.
- **Many products at once**: from **Commerce -> Products**, using a selection action, for catalog-wide updates.

## Exporting one product

1. **Commerce -> Products** and open the product you want to export.
2. In the right-hand sidebar at the bottom of the edit page, click **Export Product**.
3. The file downloads automatically as `{id}__{slug}.csv` (for example `42__classic-tee.csv`).

That filename is important: when you reupload it, Variant Manager uses the `{id}` part before `__` to match the file back to the same product even if you have renamed the product since exporting. The title in the file then overwrites the new title, because every import writes the first cell of row 2 onto the product. Before reimporting an export taken before a rename, edit that cell. Do not rename the file before reuploading.

## Exporting many products

1. **Commerce -> Products**.
2. Filter or search to narrow the list. **Add a filter** offers one entry per attribute, such as **Variant Attribute: Size**.
3. Select the products you want to export. Use the checkbox in the table header to select everything visible.
4. Open the actions menu and choose **Export Variant Data**.

Variant Manager exports one CSV per selected product:

- **One product**: a single CSV downloads, named the same way as the single-product export.
- **Multiple products**: a zip downloads, named `products_{YmdHis}.zip` (for example `products_20260513142301.zip`). Each CSV inside is named `{id}__{slug}.csv`.

## What is in the exported CSV

Variant Manager writes each CSV so you can edit cell values and reimport it unchanged.

Column order:

1. Product fields from `productFieldMap` (title, slug, status, plus any custom fields).
2. Variant fields from `variantFieldMap` (sku, height, width, length, weight, and so on).
3. Per-site Commerce variant fields suffixed with `[siteHandle]` (`basePrice[default]`, `inventoryTracked[default]`, `availableForPurchase[default]`, `freeShipping[default]`, `promotable[default]`, `minQty[default]`, `maxQty[default]`), one column per site in the Craft install, not only the sites in this product's store.
4. Inventory columns for each inventory location, all six totals per location (`Inventory[location]: reserved`, `damaged`, `safety`, `qualityControl`, `committed`, `available`).
5. Variant Attribute columns prefixed with `Attribute: `, one per attribute name any of the product's variants stores. A variant that does not store a given attribute gets an empty cell in that column.

A few notes on the export content:

- The first row after the header is the product row. It has the product's title, slug, status, and any product field values.
- Each following row is one variant.
- Disabled products and disabled variants are both exported. `status` is a product column and reads `disabled` for a disabled product; removing its `productFieldMap` entry skips the column.
- The export omits a variant's enabled state unless `variantFieldMap` has `'enabled' => 'enabled'`.
- Variants with `inventoryTracked` on get their inventory totals filled in; untracked variants have empty cells in the inventory columns.
- The `stock` column, if you have it mapped, is left empty for tracked variants since Commerce's inventory levels manage stock instead.

To change the field maps, see [configuration reference](../reference/configuration.md).

## Editing and reimporting

Steps:

1. Export the product or products.
2. Open the CSV in your spreadsheet app.
3. Edit values. Add new variant rows for new variants; delete rows for variants you want removed.
4. Save the file. Keep the original filename (`{id}__{slug}.csv`).
5. Upload from **Variant Manager -> Dashboard** -> **Upload Product**.

When you reupload an existing product:

- The modal reads the ID prefix and asks whether to **Update & remove extra variants** (default) or **Replace all variants**.
- Choose **Update & remove extra variants** for the round-trip workflow. Any variant whose SKU is in the CSV gets updated; any variant whose SKU is missing gets deleted.

For the difference between the two, see [importing](./importing.md#existing-product-update-options).

## Common mistakes

- **Renaming an export file**: do not. Renaming `42__classic-tee.csv` to any other name makes the import read it as a brand-new product, and the import probably fails with "One or more SKUs already exist".
- **Editing column headers**: do not rename the column headers. The plugin maps columns by header text; changing `basePrice[default]` to `Price` leaves prices unchanged on reimport.
- **Reordering columns**: safe for every column except the first. The plugin matches columns by header, but the product title is read from the first cell of row 2 whatever its header says, so moving another column in front of `title` renames the product on reimport.
- **Adding new attribute columns**: safe. Add a new `Attribute: Material` column with values and reimport.
- **Removing attribute columns**: removes that attribute from every variant.
- **Adding a row with a new SKU**: creates a new variant on reimport.
- **Removing a row**: deletes that variant on reimport, under the default variant-handling choice.
