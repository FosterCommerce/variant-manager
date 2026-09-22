# Troubleshooting

Common problems when importing, by what you see.

If no entry here matches, the dashboard's activity log (**Variant Manager -> Dashboard**) records the failure message from every failed import. Filter to **Errored activities** and read the message.

## The upload started but the product was not created or updated

Imports run as queue jobs, not immediately. Variant Manager shows "File ... has been queued for processing". The product only appears or changes once the job runs.

Check:

1. **Variant Manager -> Dashboard** for an activity log row for the file. A green dot means the import succeeded; a red dot means it failed, and the message names the reason.
2. **Utilities -> Queue Manager**. A pending import job means the queue has not run. A problem with the file or the settings leaves no job, and the reason is in the activity log. A failed job means another kind of failure, such as the database being unreachable. See [an import failed](#an-import-failed).
3. The Craft log (`storage/logs/`) for the relevant exception if the activity log row's message is not enough.

## I uploaded the wrong filename and it created a duplicate product

Updating a product needs the product's ID at the start of the filename, as in `42__classic-tee.csv`. Any other filename creates a new product, including one named with an existing product's exact title.

Fix:

1. Delete the duplicate product Commerce just created (**Commerce -> Products -> {duplicate} -> gear -> Delete**).
2. Export the product you meant to update, to get a `{id}__{slug}.csv` filename. Edit that file and upload it under the name it came with. Renaming your CSV to the product's title creates a third product.
3. Upload again.

For the naming rules, see [CSV format](./csv-format.md#the-filename-matters).

## "Duplicate SKUs found: ..."

Two or more rows in the CSV share the same SKU.

Fix: find the duplicates in your spreadsheet (sort by the SKU column) and give each variant a unique SKU.

## "One or more SKUs already exist: ..." on a new product import

You are uploading what Variant Manager reads as a new product (the filename does not match a product in Commerce), but one or more SKUs in the file already belong to an existing product. The whole import is blocked.

Two possibilities:

- You meant to **update** an existing product, but the filename has no `{id}__` prefix, so the import creates a product instead. Export the product and reupload that file under the name it came with.
- You meant to **create** a new product, but its SKUs collide with another product. SKUs are unique across the whole store. Change the SKUs and re-upload.

## "One or more SKUs already exist on different products: ..."

You are updating an existing product and at least one row's SKU belongs to a different product.

Fix: change the colliding SKU in your CSV. SKUs are not shareable between products.

## "No product has ID ..." on upload

The filename starts with digits and `__`, but no product has that ID. The upload fails instead of creating a product, because Variant Manager always reads a `{digits}__` prefix as an update. For a single CSV the check runs before upload, no job is queued, and the control panel shows the message as an error toast. Inside a zip, only the zip's own name is checked, so the file fails in its queue job and the activity log records it as "Invalid product id", with a lower-case `id`.

Two causes:

- You moved an export between environments, where the same product has a different ID.
- You typed the ID by hand.

Fix: export the product from this environment and upload the file under the name it came with. If you meant to create a new product, remove the `{digits}__` prefix from the filename.

## "Column ... is missing its [siteHandle] suffix"

A per-site column needs the site handle in brackets, as in `basePrice[default]`. The header matched a per-site field in `variantFieldMap` but had no suffix, so the import has no site to write the column to.

This also fires for a header that merely starts with a per-site key: with `'price' => 'basePrice'` in the map, a `priceNote` column matches `price` and then fails.

Fix: add the `[siteHandle]` suffix, or rename the column so it does not start with a mapped key.

## "Column ... is not in the form prefix[location]: total"

An inventory column needs both parts, as in `Inventory[default]: available`. See [CSV format](./csv-format.md#inventory-columns).

## "The zip file could not be read."

The upload was a zip Variant Manager could not open. No job is queued and the dashboard shows the message. Re-create the zip and upload it again.

## "Invalid product title"

The CSV has no data row after the header at all. The plugin reads the product title from the first cell of that row. An empty first cell is a different error, and fails later with "Title cannot be blank".

Fix: put the product title in the first cell of row 2.

## "The CSV has no “sku” column"

Every variant row is matched by SKU, so the column is required on both new and existing product imports.

Fix: add a `sku` column, or rename the column you are using to `sku`. See [CSV format](./csv-format.md#variant-columns).

## "No variant fields are mapped"

`variantFieldMap` in `config/variant-manager.php` has an empty entry for this product type, or an empty `'*'` entry. Import and export both need at least one column.

Fix: remove the entry to fall back on the defaults, or list the columns you want. See [`variantFieldMap`](../reference/configuration.md#variantfieldmap).

## "Invalid product type handle" or the product type dropdown was wrong

For a new product the upload modal asks which product type to create the product under. If the chosen handle does not exist in Commerce the import fails.

Fix: re-upload and pick the correct **Product Type** in the modal.

## Variants were updated, but extras I expected to keep got deleted

The default import behavior for an existing product is **Update & remove extra variants**, meaning any variant in Commerce that is not listed in the CSV by SKU is deleted. This suits full catalog updates, not partial edits.

Fix: if you want to add or update only some variants, your CSV must list every variant you want to keep. Export the product first, edit the export, and reupload. The exported file contains every variant.

To delete every existing variant first, see [existing product update options](./importing.md#existing-product-update-options).

## Inventory levels did not change

Three causes:

1. The variant's `inventoryTracked[siteHandle]` is not `1`. The import skips inventory columns on untracked variants.
2. The column header pattern is wrong. It must be `Inventory[locationHandle]: totalName`. `locationHandle` is the handle from **Commerce -> Settings -> Inventory Locations**. `totalName` is one of `available`, `reserved`, `damaged`, `safety`, `qualityControl`, `committed`. The space after the colon matters.
3. The variant has no inventory levels for the given location (for example a fresh import where the variant was just created with `inventoryTracked` off, then turned on later). Save the product once in the CP to create the inventory levels, then reimport.

## Variant attributes are missing or wrong on the imported variants

Likely causes:

1. The column header does not start with the configured `attributePrefix` (default: `Attribute: ` with a trailing space). Headers like `Color` or `Option: Color` are ignored. Change the header to `Attribute: Color`.
2. The product type's variant field layout does not include the Variant Attributes field. Add it under **Commerce -> Settings -> Product Types -> {type} -> Variant Fields**.
3. Two Variant Attributes fields exist on the same variant field layout. Only the first one is used; remove the duplicates.

## The Variant Attributes list is empty

A store whose variants predate the plugin has no registry records until one of the routes in [where records come from](./variant-attributes.md#where-records-come-from) runs.

Fix: run **Utilities -> Variant Attributes -> Start backfill**, or `./craft variant-manager/attributes/backfill`. See [variant attributes](./variant-attributes.md).

## "$value items must be associative arrays or strings" from a Twig template

The template is calling `.variantAttributes(...)` with a value that is not a string or an associative array. For the supported filter shapes, see [querying variants](../dev-guide/twig-queries.md).

## A Variant Manager button or section is missing

Each control appears only for a user with its permission.

- **Export Product** needs `variant-manager:export`.
- **Clear activity logs** needs `variant-manager:manage`.
- Everything that changes catalog data needs Commerce's `commerce-saveProductType` on at least one product type. For the full list, see [permissions reference](../reference/permissions.md).

**Bulk edit field** also needs a matching handle in `bulkEditableVariantFields`.

Set permissions at **Users -> {group} -> Permissions** or on an individual user.

## "That field cannot be bulk edited."

The field handle is not listed in `bulkEditableVariantFields`. See [configuration reference](../reference/configuration.md#bulkeditablevariantfields).

## An import failed

A problem with the CSV or with `config/variant-manager.php` ends the job, so the queue holds no failed job to clear. The dashboard activity log holds the reason: a bad filename, a duplicate SKU, a missing `sku` column, or a blank `attributePrefix`. Fix the file and upload it again from **Variant Manager -> Dashboard**.

Any other failure, such as the database being unreachable during a deploy, leaves a failed job you can retry.

A single CSV shows in Queue Manager as `Importing your-file`, with the extension dropped. A file from a zip keeps its extension.

## A zip import processed some files but not others

Each CSV in the zip becomes its own queue job. A bad CSV fails on its own without stopping the others. Check the dashboard activity log for one row per CSV; failed files show the error from that CSV alone, and the rest imported normally.

Fix only the failed CSVs and re-upload them individually, or zip a corrected subset.

## The dashboard does not show recent imports

Two possibilities:

- Activity logs older than `activityLogRetention` are deleted on Craft's garbage collection. Default retention is `30 days`. Check `config/variant-manager.php`.
- The dashboard filter is set to **Successful** or **Errored** only. Switch the dropdown to **All activity logs**.

## No entry here matches

Reproduce the failure, then grab:

- The dashboard activity log row for the failed file (screenshot or copy-paste).
- The relevant entry in `storage/logs/web-{date}.log`.
- The CSV that triggered the failure (or a redacted one with the same structure).

Open an issue or contact Foster Commerce with those attached.
