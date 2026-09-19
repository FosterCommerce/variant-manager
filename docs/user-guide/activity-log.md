# Activity log

Where to find a record of past imports and variant generations, and how to cap how long its rows are kept.

## What gets logged

Every import attempt and every Variant Maker run writes one row to the activity log, success or failure:

- **Successful imports** (green status dot): "Imported new product {title} into {product type}" or "Imported existing product {title} into {product type}". The product title is a link to the product edit page.
- **Failed imports** (red status dot): "Failed to import {filename}: {error message}". The error message is the same one the import threw.
- **Variant Maker runs**: how many variants were created, updated, and deleted, or the error if the run failed. See [the Variant Maker](./variant-maker.md).
- **New attributes and options**: "Created attribute Color" and "Created option Red under Color", written the first time a name or value is registered, whether by a variant save, a CSV import, or the backfill. See [variant attributes](./variant-attributes.md).

Exporting writes no row.

Each row records the user who triggered the run and the date.

## Where to find it

**Variant Manager -> Dashboard**.

The dropdown next to the **Clear activity logs** button filters by status:

- **All activity logs**
- **Successful activities**
- **Errored activities**

Filter to **Errored activities** while debugging a failed batch.

## Retention

Rows are kept for the period `activityLogRetention` sets. Default: `30 days`.

Cleanup happens two ways:

- **Automatically**, during Craft's garbage collection (`./craft gc` or whenever Craft schedules it).
- **Manually**, by running the cleanup console command.

To keep logs forever, set `activityLogRetention` to `false` or `null` in `config/variant-manager.php`. For the values it accepts, see [`activityLogRetention`](../reference/configuration.md#activitylogretention).

## Clearing the log

Two ways:

- **From the dashboard**: click **Clear activity logs** and confirm. Wipes every row regardless of age. The button appears only for users with the `variant-manager:manage` permission.
- **Via console**:

  ```sh
  # Delete rows older than activityLogRetention (the default cleanup).
  ./craft variant-manager/activities/clear

  # Wipe every row regardless of age.
  ./craft variant-manager/activities/clear 1
  ```

Either way, cleared rows are gone permanently; there is no trash to restore from.

## Permissions

`variant-manager:manage` is required to clear logs from the dashboard. Anyone with `accessPlugin-variant-manager` can read the log.

See [permissions](./permissions.md).
