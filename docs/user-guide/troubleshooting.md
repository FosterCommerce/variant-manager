# Troubleshooting

Common problems when importing, by what you see. Audience: anyone uploading CSVs.

If nothing here matches, the dashboard's activity log (**Variant Manager -> Dashboard**) records the failure message from every failed import. Filter to **Errored activities** and read the message.

## The upload kicked off but the product was not created or updated

Imports run as queue jobs, not immediately. The upload responds with "File ... has been queued for processing". The product only appears or changes once the job runs.

Check:

1. **Variant Manager -> Dashboard** for an activity log row for the file. A green dot means the import succeeded; a red dot means it failed and the message tells you why.
2. **Utilities -> Queue Manager**. A pending import job means the queue has not run yet. A failed job means the import threw an error. See "Cleaning up failed import jobs" below.
3. The Craft log (`storage/logs/`) for the relevant exception if the activity log row's message is not enough.

## I uploaded the wrong filename and it created a duplicate product

The CSV filename decides create-vs-update. A filename that does not match an existing product creates a new one.

Fix:

1. Delete the duplicate product Commerce just created (**Commerce -> Products -> {duplicate} -> gear -> Delete**).
2. Rename your CSV to match the existing product's title exactly (case, spaces, punctuation), or re-export the original to get a `{id}__{slug}.csv` filename you can edit and reupload as-is.
3. Upload again.

See [CSV format](./csv-format.md#the-filename-matters) for the naming rules.

## "Duplicate SKUs found: ..."

Two or more rows in the CSV share the same SKU.

Fix: find the duplicates in your spreadsheet (sort by the SKU column) and give each variant a unique SKU.

## "One or more SKUs already exist: ..." on a new product import

You are uploading what Variant Manager has decided is a new product (the filename does not match anything in Commerce), but one or more SKUs in the file already belong to an existing product. The whole import is blocked.

Two possibilities:

- You meant to **update** an existing product, but the filename does not match. Rename the file to the existing product's title and try again.
- You meant to **create** a new product, but its SKUs collide with another product. SKUs are unique across the whole store. Change the SKUs and re-upload.

## "One or more SKUs already exist on different products: ..."

You are updating an existing product and at least one row's SKU belongs to a different product.

Fix: change the colliding SKU in your CSV. SKUs are not shareable between products.

## "Invalid product title"

Row 2 of the CSV is empty (or the first column on row 2 is empty). The plugin reads the product title from the first cell of row 2.

Fix: put the product title in the first cell of row 2.

## "Invalid product type handle" or the product type dropdown was wrong

For a new product the upload modal asks which product type to create the product under. If the chosen handle does not exist in Commerce the import fails.

Fix: re-upload and pick the correct **Product Type** in the modal.

## Variants were updated, but extras I expected to keep got deleted

The default import behaviour for an existing product is **Update and remove extra variants**, meaning any variant in Commerce that is not listed in the CSV by SKU is deleted. This suits full catalog updates, not partial edits.

Fix: if you want to add or update only some variants, your CSV must list every variant you want to keep. Export the product first, edit the export, and reupload. The exported file contains every variant.

If you actually wanted to wipe everything and start fresh, see "Replace all variants" in [importing](./importing.md#existing-product-update-options).

## Inventory levels did not change

Three causes, in order of likelihood:

1. The variant's `inventoryTracked[siteHandle]` is not `1`. Untracked variants ignore inventory columns.
2. The column header pattern is wrong. It must be `Inventory[locationHandle]: totalName` where `locationHandle` matches the handle in **Commerce -> Settings -> Inventory Locations** and `totalName` is one of `available`, `committed`, `reserved`, `damaged`, `safety`, `qualityControl`. The space after the colon matters.
3. The variant has no inventory levels for the given location (for example a fresh import where the variant was just created with `inventoryTracked` off, then turned on later). Save the product once in the CP to materialise the inventory levels, then reimport.

## Variant attributes are missing or wrong on the imported variants

Likely causes:

1. The column header does not start with the configured `attributePrefix` (default: `Attribute: ` with a trailing space). Headers like `Color` or `Option: Color` are ignored. Change the header to `Attribute: Color`.
2. The product type's variant field layout does not include the Variant Attributes field. Add it under **Commerce -> Settings -> Product Types -> {type} -> Variant Fields**.
3. Two Variant Attributes fields exist on the same variant field layout. Only the first one is used; remove the duplicates.

## "$value items must be associative arrays or strings" from a Twig template

The template is calling `.variantAttributes(...)` with a value that is not a string or an associative array. See [querying variants](../dev-guide/twig-queries.md) for the supported filter shapes.

## "Permission denied" on the Upload Product or Export Product buttons

The user does not have the matching permission.

- Upload requires `variant-manager:import`.
- Export requires `variant-manager:export`.
- Clearing the activity log requires `variant-manager:manage`.

Set permissions at **Users -> {group} -> Permissions** or on an individual user.

## Cleaning up failed import jobs

Failed imports usually fail because the CSV was wrong (bad filename, duplicate SKU, smart quotes, wrong attribute prefix). Retrying the job will fail again with the same error.

**Delete the failed jobs rather than retrying them.** Fix the CSV and re-upload it from the dashboard, which creates a fresh job with the corrected data.

To delete a failed job:

1. **Utilities -> Queue Manager**.
2. Find the failed job (it will say `Importing your-file.csv`).
3. Open it and press **Release** (or the trash icon on the row).
4. Re-upload the corrected CSV from **Variant Manager -> Dashboard**.

Retrying only makes sense for transient failures (database unreachable, file system errors during a deploy). For CSV-content failures, the right move is fix-and-reupload.

## A zip import processed some files but not others

Each CSV in the zip becomes its own queue job. A bad CSV in the zip fails its own job without stopping the others. Check the dashboard activity log for one row per CSV; failed files show the error from that CSV alone, and the rest will have imported normally.

Fix only the failed CSVs and re-upload them individually, or zip a corrected subset.

## The dashboard does not show recent imports

Two possibilities:

- Activity logs older than `activityLogRetention` are deleted on Craft's garbage collection. Default retention is `30 days`. Check `config/variant-manager.php`.
- The dashboard filter is set to **Successful** or **Errored** only. Switch the dropdown to **All activity logs**.

## Nothing here matches

Reproduce the failure, then grab:

- The dashboard activity log row for the failed file (screenshot or copy-paste).
- The relevant entry in `storage/logs/web-{date}.log`.
- The CSV that triggered the failure (or a redacted one with the same structure).

Open an issue or contact Foster Commerce with those attached.
