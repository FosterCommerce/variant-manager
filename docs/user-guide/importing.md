# Importing

How to upload a CSV to create or update a product in Craft Commerce.

If you have not built the CSV yet, start with [CSV format](./csv-format.md).

To update an existing product rather than create one, export it first and edit that file, so the filename keeps the `{id}__` prefix the import matches on. For the naming rules, see [CSV format](./csv-format.md#the-filename-matters).

## 1. Save as CSV from your spreadsheet

In Excel: **File -> Save As -> CSV UTF-8 (Comma delimited)**. In Numbers or Google Sheets: **File -> Export -> CSV**.

Avoid:

- Saving with `;` as the separator.
- Saving with smart quotes in column headers.

For the full list, see [CSV format: common mistakes](./csv-format.md#common-mistakes).

## 2. Open the dashboard

In the CP, go to **Variant Manager -> Dashboard**.

You see:

- An **Upload Product** button (top right).
- A **Clear activity logs** button next to it, for users with the `variant-manager:manage` permission.
- An activity log table of past imports below.

## 3. Click Upload Product and pick your file

The file picker opens. Select your CSV (or zip, see [bulk import](./bulk-import.md)). Variant Manager reads the filename for a product ID prefix and opens a modal asking you to confirm. A file starting with `{id}__` updates that product; any other filename creates a new one.

The modal asks one of three sets of questions, depending on the filename.

### New product

"Are you sure you want to create a new product?"

- **Product Type** dropdown: pick the Commerce product type the new product should belong to. Required.
- **Create Product** button: starts the import.
- **Cancel** button: abandons the upload.

Click **Create Product** to queue the import.

### Existing product

"Are you sure you want to edit an existing product named **\"{title}\"**?"

The modal does not show a Product Type dropdown, since the existing product keeps its type. It does show one new choice:

#### Existing product update options

Two radio options, with no group label:

- **Update & remove extra variants** (default). The import updates variants whose SKUs match rows in the CSV, and deletes any existing variants whose SKUs are not in the CSV. Use this for a complete catalog sync where the CSV defines the full variant set.
- **Replace all variants**. The plugin deletes every existing variant on the product before importing, then creates fresh variants from the CSV. Use this when you are starting over for that product.

There is no option to import only the changes. If you want to update only a few variants, export the product first to get a CSV with all variants, edit the rows you need, and reupload.

Click **Edit Product** to queue the import.

### Zip file

If you picked a zip file the modal asks the same questions as a new product, plus the choice between updating and replacing variants. For the full zip sequence, see [bulk import](./bulk-import.md).

## 4. Wait for the queue

The upload responds with "File {your-file}.csv has been queued for processing" and the page refreshes. The import runs as a Craft queue job, not immediately.

Watch the dashboard's activity log for the result:

- A green status dot means the import succeeded. The message links to the new or updated product.
- A red status dot means the import failed. The message contains the failure reason.

If your queue is not running automatically, run `./craft queue/run` from the project directory.

## 5. Verify the result

For a successful import, click the linked product title in the activity log to jump to the product edit page. Check:

- The variants tab has the expected rows.
- Variant SKUs, prices, and attributes match what you expected.
- The product status (enabled or disabled) is what you set in the `status` column (default: enabled).

If a value is wrong, edit the source CSV and re-upload it, which writes the corrected values over the old ones.

## When an import fails

Failed imports show their error in the activity log. Two common reasons: a missing `{id}__` prefix on a file meant to update, and a SKU that already exists on a different product. See [troubleshooting](./troubleshooting.md).

**A failed import undoes only part of its work.** It deletes a product it created and restores variants it replaced. Variants it had already updated keep their new values, and its product field and inventory writes stand. Upload the corrected CSV to write every value from the file again.

**Failed queue jobs**: delete them rather than retrying. Fix the CSV and re-upload it. See [cleaning up failed import jobs](./troubleshooting.md#cleaning-up-failed-import-jobs).
