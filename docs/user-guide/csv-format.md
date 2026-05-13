# CSV format

How to shape a CSV file so Variant Manager imports it cleanly the first time. Audience: anyone preparing product data in a spreadsheet for upload.

## The filename matters

The CSV's filename is how Variant Manager decides whether to **create a new product** or **update an existing one**. Check it before every upload.

- **New product**: name the file exactly what you want the product to be titled in Commerce, plus `.csv`.
  - `Classic Tee.csv` creates a product titled `Classic Tee`.
  - `Heritage Mug.csv` creates a product titled `Heritage Mug`.
- **Existing product**: name the file with the product's exact title in Commerce.
  - `Classic Tee.csv` updates the existing `Classic Tee` product.
  - Capitalisation, spaces, and punctuation must all match. `classic tee.csv` will **not** find `Classic Tee`; it will create a new product called `classic tee` instead.
- **Exporting then re-uploading**: exported files are named `{id}__{slug}.csv` (for example `42__classic-tee.csv`). Leave this filename alone. The number before `__` ties the upload back to the same product even if its title has been edited in the meantime.
- **Hidden files**: filenames beginning with `.` are ignored, and zip uploads skip files in `__MACOSX/` folders that macOS adds automatically. Both are safe to leave in your zip.

Avoid: renaming an export from `42__classic-tee.csv` to `Classic Tee Updated.csv`. Variant Manager will treat it as a brand-new product (and probably fail because the SKUs already belong to the original product).

## The shape of a CSV

Variant Manager expects:

- **Row 1**: column headers.
- **Row 2**: the product row. The first column is the product title; other product field columns sit in this row.
- **Rows 3+**: one variant per row.

A minimum file looks like this:

```csv
title,sku,basePrice,Attribute: Color,Attribute: Size
Classic Tee,,,,
,TEE-RED-S,19.99,Red,Small
,TEE-RED-M,19.99,Red,Medium
,TEE-BLUE-S,19.99,Blue,Small
,TEE-BLUE-M,19.99,Blue,Medium
```

The first cell of row 2 (`Classic Tee`) is the product title. From row 3 onwards every row is one variant; leave the product title column empty on variant rows.

A more complete file showing every column type:

```csv
title,slug,status,sku,basePrice[default],inventoryTracked[default],promotable[default],weight,Inventory[main]: available,Attribute: Color,Attribute: Size
Classic Tee,classic-tee,enabled,,,,,,,,
,,,TEE-RED-S,19.99,1,1,150,42,Red,Small
,,,TEE-RED-M,19.99,1,1,150,38,Red,Medium
,,,TEE-BLUE-S,19.99,1,1,150,55,Blue,Small
,,,TEE-BLUE-M,19.99,1,1,150,60,Blue,Medium
```

## Column reference

Every column header falls into one of five groups. Variant Manager looks at the header text to decide which group a column belongs to.

### Product columns

These describe the product as a whole and are read from row 2 only. The keys come from `productFieldMap` in `config/variant-manager.php`. Defaults:

| Column | Required | Format | Notes |
|--------|----------|--------|-------|
| `title` | Yes | Text | First column of row 2. Must be present even if you set it via the filename. |
| `slug` | No | URL slug | Generated from the title when blank on a new product. |
| `status` | No | `enabled` or `disabled` | Empty or anything other than `disabled` (case-insensitive) imports as enabled. |

Add custom product fields by mapping them in `productFieldMap`. See [configuration reference](../reference/configuration.md).

### Variant columns

Map to per-variant properties via `variantFieldMap`. Defaults:

| Column | Required | Format | Notes |
|--------|----------|--------|-------|
| `title` | No | Text | Auto-generated from attributes if blank. |
| `sku` | Yes | Text | Must be unique across every product in the store. |
| `basePrice` | Yes | Decimal | Listed under the per-site columns below; see "Per-site Commerce columns". |
| `inventoryTracked` | No | `1` or `0` | Per-site; listed below. |
| `height` / `width` / `length` | No | Number | Same units as the rest of Commerce. |
| `weight` | No | Number | Same units as the rest of Commerce. |

The plugin maps the configured column name on the left side of `variantFieldMap` to the variant property on the right side. So `'price' => 'basePrice'` means a column header `price` writes to the `basePrice` property.

### Per-site Commerce columns

Some Commerce variant fields are per-site. Suffix the column name with `[siteHandle]`. For a single-site store the site handle is usually `default`.

| Property | Example header | Format |
|----------|----------------|--------|
| `basePrice` | `basePrice[default]` | Decimal |
| `inventoryTracked` | `inventoryTracked[default]` | `1` or `0` |
| `availableForPurchase` | `availableForPurchase[default]` | `1` or `0`. Defaults to `1` if the column is missing. |
| `freeShipping` | `freeShipping[default]` | `1` or `0` |
| `promotable` | `promotable[default]` | `1` or `0`. Defaults to `1` if the column is missing. |
| `minQty` | `minQty[default]` | Number |
| `maxQty` | `maxQty[default]` | Number |

Each site you want to set values for needs its own column with that site's handle. For example `basePrice[en]`, `basePrice[fr]`.

### Inventory columns

Inventory values live in their own `Inventory[locationHandle]: total` columns. The location handle is the handle from **Commerce -> Settings -> Inventory Locations**. The total name is one of the six totals Commerce tracks per location.

| Header pattern | Total |
|----------------|-------|
| `Inventory[locationHandle]: available` | Available stock |
| `Inventory[locationHandle]: committed` | Committed to orders |
| `Inventory[locationHandle]: reserved` | Reserved |
| `Inventory[locationHandle]: damaged` | Damaged |
| `Inventory[locationHandle]: safety` | Safety stock |
| `Inventory[locationHandle]: qualityControl` | Quality control hold |

Only variants with `inventoryTracked` set to `1` receive inventory updates. Tracked variants without an inventory column are not modified.

The `Inventory` prefix is configurable via `inventoryPrefix`. The default is `Inventory`.

### Variant Attribute columns

Anything you want stored on the **Variant Attributes** field uses the `Attribute: ` prefix. Each column becomes one attribute; the column header after the prefix is the attribute name, and the cell value is the attribute value.

```csv
Attribute: Color,Attribute: Size,Attribute: Material
Red,Small,Cotton
```

The `Attribute: ` prefix is configurable via `attributePrefix`. Whatever you set must match exactly, including spaces and the trailing colon if you keep one.

Empty cells become the `emptyAttributeValue` configured in the plugin config (default: empty string).

### Other custom fields

Plain text and number fields you have added to your variant or product field layouts can be included by adding their handles to `variantFieldMap` or `productFieldMap`. The plugin also recognises:

- **Money fields**: decimal value (`15.00`).
- **Lightswitch fields**: `1` for on, anything else for off.
- **Entries fields**: comma-separated `sectionHandle:slug` (for example `articles:summer-launch,faqs:returns`).
- **Assets fields**: comma-separated `volumeHandle:path/to/file.jpg` (for example `uploads:product-photos/red-tee.jpg`), or asset IDs.
- **Other relation fields**: comma-separated slugs.

## Common mistakes

These are the imports that fail or behave strangely:

- **Smart quotes in column headers**: typing column headers in a word processor turns `"Attribute: Color"` into `"Attribute: Color"` with curly quotes. Header matching is exact. Stick to a spreadsheet editor or a plain-text editor.
- **A BOM at the start of the file**: some spreadsheet apps add a byte-order mark, which makes the first column header unrecognisable. Save as "CSV (comma-delimited)" or "CSV UTF-8" without BOM.
- **Semicolon as the separator**: spreadsheet apps in some regions default to `;`. Variant Manager only reads commas. Re-export with comma as the delimiter.
- **Inventory column on an untracked variant**: the cell is ignored. Set `inventoryTracked[default]` to `1` first.
- **Wrong attribute prefix**: `Option: Color` does nothing if `attributePrefix` is `Attribute: `. Match the config.
- **Mixed prefixes**: every attribute column must use the same prefix you have in config. You cannot mix `Attribute: ` and `Option: ` in one file.
- **SKU collisions**: an SKU on a different product blocks the whole import with an error. SKUs must be unique across the entire store.
- **Duplicate SKUs inside the file**: the same SKU on two rows in the same CSV also blocks the import.
- **Product title differs from filename**: if `title` in row 2 says `Classic Tee` but the filename is `Heritage Mug.csv`, the file creates a product named `Heritage Mug` and writes `Classic Tee` over its title once saved. Pick one. The filename wins for the create-vs-update decision; the cell wins for the final title.

See [troubleshooting](./troubleshooting.md) for what to do when an import goes wrong.
