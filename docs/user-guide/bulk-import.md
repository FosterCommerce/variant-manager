# Bulk import

Upload many products at once by zipping their CSVs together. Audience: anyone doing a catalog-scale import.

## When to use it

- You have dozens or hundreds of products to create in one batch.
- You are migrating from an external system that exports one CSV per product.
- You want to refresh a whole catalog overnight.

For one or two products, the [single-product upload](./importing.md) is simpler.

## How the zip is read

Variant Manager extracts every `.csv` file from the zip and queues one import job per CSV. Each CSV is imported independently:

- Each CSV's filename decides create-vs-update for its product (see [filename rules](./csv-format.md#the-filename-matters)).
- All CSVs in the zip use the same **Product Type** and **Refresh variants** choice you pick in the modal.
- One CSV failing does not stop the others. Each failure is logged separately in the dashboard activity log.

The plugin ignores:

- Files that are not `.csv`.
- Files inside `__MACOSX/` (the metadata folder macOS adds to zips it creates).
- Hidden files starting with `.` (`.DS_Store`, `.gitkeep`, and so on).

## Building the zip

1. Build one CSV per product, named with the product title (or the `{id}__{slug}.csv` exported name).
2. Put them all in a single folder.
3. Zip the folder, or select all the CSVs and create a zip from them.

A zip with subfolders works, but folder paths are ignored; only the CSV's own filename is used to find or create the product. Avoid relying on folder structure to organise products.

## Uploading

**Variant Manager -> Dashboard -> Upload Product**, and pick the zip.

The modal opens with "Are you sure you want to process this zip file?" and shows:

- **Product Type** dropdown. All new products in the zip are created under this product type.
- **Refresh variants** radio group:
  - **Update and remove extra variants** (default): for any CSV that matches an existing product, update its variants and delete any that are not in the CSV.
  - **Replace all variants**: for any CSV that matches an existing product, delete every existing variant first, then import the CSV.
- **Process Zip** to start, or **Cancel** to abandon.

Click **Process Zip**. The plugin extracts every CSV and queues one job per file. The dashboard responds with "File yourfile.zip has been queued for processing".

## Watching progress

Each CSV inside the zip becomes its own row in the dashboard activity log. Refresh the page to see new rows as jobs run.

For long batches:

- **Utilities -> Queue Manager** shows pending and running jobs by name (`Importing somefile.csv`).
- Filter the dashboard log to **Errored activities** to see failures only.
- The activity log retention setting governs how long these rows stick around (default `30 days`); see [activity log](./activity-log.md).

## Performance

A big zip can generate hundreds or thousands of queue jobs. If your site has other queue work that needs to run promptly (search index rebuilds, image transforms), put Variant Manager on its own queue or lower the priority of its jobs. See [custom queue](../dev-guide/custom-queue.md).

## When things go wrong

The most common failures in a bulk import:

- **Wrong product type for some files**: every CSV in the zip uses the same product type. If some products belong to a different product type, split them into separate zips.
- **Some files have SKUs that already belong to other products**: each failing file logs its own error. Fix those CSVs and reupload them individually or in a smaller zip.
- **Filename mismatches**: a CSV named `Classic Tee Updated.csv` will create a new product, not update `Classic Tee`. Rename or re-export.
- **Queue stalled mid-batch**: the unprocessed jobs sit pending until the queue runs. `./craft queue/run` resumes.

**Failed jobs should be deleted, not retried.** A failed import almost always failed because of bad CSV data, and retrying with the same data fails the same way. Fix the source CSV and upload it again. See [cleaning up failed import jobs](./troubleshooting.md#cleaning-up-failed-import-jobs).
