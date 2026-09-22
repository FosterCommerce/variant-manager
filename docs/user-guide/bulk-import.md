# Bulk import

Upload many products at once by zipping their CSVs together.

## When to use it

- You have dozens or hundreds of products to create in one batch.
- You are migrating from an external system that exports one CSV per product.

For one or two products, use the [single-product upload](./importing.md).

## How the zip is read

Variant Manager extracts every `.csv` file from the zip and queues one import job per CSV. Each CSV is imported independently:

- Each CSV's filename determines create-vs-update for its product (see [filename rules](./csv-format.md#the-filename-matters)).
- All CSVs in the zip use the same **Product Type** and the same variant-handling choice you pick in the modal.
- One CSV failing does not stop the others. Each failure is logged separately in the dashboard activity log.

The plugin ignores:

- Files that are not `.csv`, which covers `.DS_Store` and `.gitkeep`.
- Entries whose name starts with `.`, such as the `._` copies macOS puts in a `__MACOSX/` folder. Safe to leave in your zip.

## Building the zip

1. Build one CSV per product. Name an update's file `{id}__{slug}.csv`, the name its export came with. A new product's file can take any name.
2. Put them all in a single folder.
3. Zip the folder, or select all the CSVs and create a zip from them.

A zip with subfolders works, but folder paths are ignored; only the CSV's own filename is used to find or create the product.

## Uploading

**Variant Manager -> Dashboard -> Upload Product**, and pick the zip.

The modal opens with "Are you sure you want to process this zip file?" and shows:

- **Product Type** dropdown. All new products in the zip are created under this product type.
- The two variant-handling radios:
  - **Update & remove extra variants** (default): for any CSV that matches an existing product, update its variants and delete any that are not in the CSV.
  - **Replace all variants**: for any CSV that matches an existing product, delete every existing variant first, then import the CSV.
- **Process Zip** to start, or **Cancel** to abandon.

Click **Process Zip**. The plugin extracts every CSV and queues one job per file. Variant Manager shows "File yourfile.zip has been queued for processing".

## Watching progress

Each CSV inside the zip becomes its own row in the dashboard activity log. Refresh the page to see new rows as jobs run.

For long batches:

- **Utilities -> Queue Manager** shows pending and running jobs by name (`Importing somefile.csv`).
- Filter the dashboard log to **Errored activities** to see failures only.
- The activity log retention setting governs how long these rows are kept (default `30 days`); see [activity log](./activity-log.md).

## Performance

A big zip can generate hundreds or thousands of queue jobs. If your site has other queue work that needs to run promptly (search index rebuilds, image transforms), put Variant Manager on its own queue or lower the priority of its jobs. See [custom queue](../dev-guide/custom-queue.md).

## When an import fails

The most common failures in a bulk import:

- **Wrong product type for some files**: every CSV in the zip uses the same product type. If some products belong to a different product type, split them into separate zips.
- **Some files have SKUs that already belong to other products**: each failing file logs its own error. Fix those CSVs and reupload them individually or in a smaller zip.
- **Missing ID prefix**: the file creates a new product instead of updating one. Re-export the product to get the right filename.
- **Queue stalled mid-batch**: the unprocessed jobs stay pending until the queue runs. `./craft queue/run` resumes.

A CSV the import rejects ends its job, so the queue holds no failed job. Fix the source CSV and upload it again. See [an import failed](./troubleshooting.md#an-import-failed).
