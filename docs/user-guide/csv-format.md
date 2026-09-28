# CSV format

How to shape a CSV file so Variant Manager imports it cleanly the first time.

## The filename matters

The CSV's filename is how Variant Manager determines whether to **create a new product** or **update an existing one**. Only one part of it is read: a product ID, followed by two underscores, at the start.

- **Updating an existing product**: the filename starts with the product's ID and `__`, as in `42__classic-tee.csv`. This is the shape every export uses, so the reliable way to update a product is to export it, edit that file, and upload it under the name it came with. The part after `__` is ignored, and the ID keeps working after the product is retitled.
- **Creating a new product**: any filename that does not start with digits and `__`. `Classic Tee.csv`, `spring-range.csv`, and `supplier-feed-3.csv` all create a new product.
- **An ID that does not match a product**: the upload fails rather than creating a product. A filename starting with digits and `__` is always read as an update, so `999__old-export.csv` errors if product 999 does not exist. This catches exports moved between environments, where the IDs differ.
- **A title in the filename has no effect.** `Classic Tee.csv` does not find an existing product called `Classic Tee`; it creates a second one. The product's title comes from the first cell of the row after the header, never from the filename.

The upload modal says which one is about to happen. **Create Product** with a product type dropdown means a new product; **Edit Product** means it matched an ID. Check that before confirming.

Avoid: renaming an export from `42__classic-tee.csv` to `Classic Tee Updated.csv`. That drops the ID, so Variant Manager creates a new product, and the import fails where the SKUs already belong to the original.

## The shape of a CSV

A CSV needs:

- **Row 1**: column headers.
- **Row 2**: the product row. The first column is the product title; other product field columns belong to this row.
- **Rows 3+**: one variant per row.

A minimum file looks like this:

```csv
title,sku,basePrice[default],Attribute: Color,Attribute: Size
Classic Tee,,,,
,TEE-RED-S,19.99,Red,Small
,TEE-RED-M,19.99,Red,Medium
,TEE-BLUE-S,19.99,Blue,Small
,TEE-BLUE-M,19.99,Blue,Medium
```

The first cell of row 2 (`Classic Tee`) is the product title. From row 3 onwards every row is one variant; leave the product title column empty on variant rows. `basePrice[default]` is per-site; replace `default` with your site's handle if it is different.

Download: [`classic-tee-minimum.csv`](../examples/classic-tee-minimum.csv). Upload it under any name; it creates a product titled `Classic Tee` because that is the first cell of row 2. To change the title, edit that cell.

A more complete file showing every column type:

```csv
title,slug,status,sku,basePrice[default],inventoryTracked[default],promotable[default],weight,Inventory[main]: available,Attribute: Color,Attribute: Size
Classic Tee,classic-tee,enabled,,,,,,,,
,,,TEE-RED-S,19.99,1,1,150,42,Red,Small
,,,TEE-RED-M,19.99,1,1,150,38,Red,Medium
,,,TEE-BLUE-S,19.99,1,1,150,55,Blue,Small
,,,TEE-BLUE-M,19.99,1,1,150,60,Blue,Medium
```

Download: [`classic-tee-complete.csv`](../examples/classic-tee-complete.csv). Change its title and SKUs before uploading both, because they match the minimum example.

## Column reference

Each column header belongs to one of five groups, decided by the header text.

Leave a column out to keep existing values. An empty cell clears that value: a text, number, or custom field is emptied, a switch goes back to its default, and an inventory total becomes `0`. An empty SKU or price, or an empty product title on row 2, fails the import before it writes any value.

Headers match regardless of letter case, so `SKU`, `sku`, and `Sku` all write to `sku`. The site handle in brackets, `inventoryPrefix`, and `attributePrefix` must match exactly. An import fails when two columns map to the same field, or when a header matches two `variantFieldMap` keys that differ only in letter case, counting a standard field the map doesn't target as a key under its own name.

### Product columns

These describe the product as a whole and are read from row 2 only. The keys come from `productFieldMap` in `config/variant-manager.php`. Defaults:

| Column | Required | Format | Notes |
|--------|----------|--------|-------|
| `title` | Yes | Text | The product's title. Read from the first cell of row 2 by position, whatever that column is headed. |
| `slug` | No | URL slug | An empty cell generates it from the title. |
| `status` | No | `enabled` or `disabled` | Empty or any value other than `disabled` (case-insensitive) imports as enabled. |

Add custom product fields by mapping them in `productFieldMap`. See [configuration reference](../reference/configuration.md).

### Variant columns

Map to per-variant properties via `variantFieldMap`. Defaults:

| Column | Required | Format | Notes |
|--------|----------|--------|-------|
| `title` | No | Text | Omit the column to leave variant titles alone. On a product type with no variant title field, an empty cell means Commerce generates the title from the product type's variant title format. On a product type that has one, an empty cell means Craft assigns `Product Variant {id}`. |
| `sku` | Yes | Text | Must be unique across every product in the store, ignoring letter case. A row whose SKU differs from a variant's only in letter case updates that variant and gives its SKU the CSV's letter case. |
| `basePrice` | Yes | Number, such as `1299.00` | Listed under the per-site columns below; see "Per-site Commerce columns". A CSV that creates a variant fails without a price column. An update to existing variants can leave the column out. |
| `inventoryTracked` | No | `1` or `0` | Per-site; listed below. |
| `height` / `width` / `length` | No | Number | Same units as the rest of Commerce. |
| `weight` | No | Number | Same units as the rest of Commerce. |

The plugin maps the configured column name on the left side of `variantFieldMap` to the variant property on the right side. So `'price' => 'basePrice'` means a column header `price[default]` writes to the `basePrice` property. `basePrice` is per-site, so the header keeps its `[siteHandle]` suffix; a bare `price` header errors.

### Per-site Commerce columns

Some Commerce variant fields are per-site. Suffix the column name with `[siteHandle]`. For a single-site store the site handle is usually `default`.

| Property | Example header | Format |
|----------|----------------|--------|
| `basePrice` | `basePrice[default]` | Number, such as `1299.00` or `1,299.00`. Required on every variant row. A comma always groups thousands, so `12,50` fails, as does `$20`. |
| `inventoryTracked` | `inventoryTracked[default]` | `1` or `0` |
| `availableForPurchase` | `availableForPurchase[default]` | `1` or `0`. A new variant, or an empty cell, gives `1`. |
| `freeShipping` | `freeShipping[default]` | `1` or `0` |
| `promotable` | `promotable[default]` | `1` or `0`. A new variant, or an empty cell, gives `1`. |
| `minQty` | `minQty[default]` | Number |
| `maxQty` | `maxQty[default]` | Number |

Each site you want to set values for needs its own column with that site's handle. For example `basePrice[en]`, `basePrice[fr]`.

Commerce stores these values once per store, not per site. Where two sites share a store, give their columns the same value on each row. An import fails when they differ. An empty cell is not compared, because an export leaves the cells empty for a site the product doesn't exist on.

### Inventory columns

Inventory values use their own `Inventory[locationHandle]: total` columns. The location handle is the handle from **Commerce -> Settings -> Inventory Locations**. The total name is one of the six totals below. Any other total name fails the import, and each quantity must be a whole number.

| Header pattern | Total |
|----------------|-------|
| `Inventory[locationHandle]: available` | Available stock |
| `Inventory[locationHandle]: reserved` | Reserved |
| `Inventory[locationHandle]: damaged` | Damaged |
| `Inventory[locationHandle]: safety` | Safety stock |
| `Inventory[locationHandle]: qualityControl` | Quality control hold |
| `Inventory[locationHandle]: committed` | Committed |

Only variants with `inventoryTracked` set to `1` receive inventory updates. Tracked variants without an inventory column are not modified.

The `Inventory` prefix is configurable via `inventoryPrefix`. The default is `Inventory`.

### Variant Attribute columns

Any value you want stored on the **Variant Attributes** field uses the `Attribute: ` prefix. Each column becomes one attribute; the column header after the prefix is the attribute name, and the cell value is the attribute value.

```csv
Attribute: Color,Attribute: Size,Attribute: Material
Red,Small,Cotton
```

The `Attribute: ` prefix is configurable via `attributePrefix`. Whatever you set must match exactly, including spaces and the trailing colon if you keep one.

An empty cell stores `emptyAttributeValue` (default: empty string). Attributes without a column stay as they are.

### Other custom fields

Include a custom field by adding its handle to `variantFieldMap` or `productFieldMap`. For the field types the import writes and the CSV value each one takes, see [supported field types](../reference/configuration.md#supported-field-types).

## Common mistakes

These are the imports that fail or behave strangely:

- **A file that isn't UTF-8**: the import fails. In Excel, save as **CSV UTF-8 (Comma delimited)**, not **CSV (Comma delimited)**.
- **Smart quotes in column headers**: a word processor replaces `"` with `“` in a pasted header, which then matches no column. Stick to a spreadsheet editor or a plain-text editor.
- **A space before the site handle**: `basePrice [default]` matches no column and is ignored. Write `basePrice[default]`.
- **Semicolon as the separator**: spreadsheet apps in some regions default to `;`. Variant Manager only reads commas. Re-export with comma as the delimiter.
- **Inventory column on an untracked variant**: the cell is ignored. Set `inventoryTracked[default]` to `1` first.
- **Wrong attribute prefix**: `Option: Color` is ignored if `attributePrefix` is `Attribute: `. Match the config.
- **Mixed prefixes**: every attribute column must use the same prefix you have in config. You cannot mix `Attribute: ` and `Option: ` in one file.
- **SKU collisions**: a SKU on a different product blocks the whole import with an error. SKUs must be unique across the entire store, ignoring letter case.
- **Duplicate SKUs inside the file**: the same SKU on two rows in the same CSV also blocks the import.
- **Expecting the filename to set the title**: it does not. `Heritage Mug.csv` with `Classic Tee` in the first cell of row 2 creates a product titled `Classic Tee`. The filename only determines create-vs-update.

For what to do when an import goes wrong, see [troubleshooting](./troubleshooting.md).
