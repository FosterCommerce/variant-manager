# Console commands

Commands exposed by Variant Manager. Run from your project root.

## `variant-manager/activities/clear`

Delete activity log entries.

```sh
./craft variant-manager/activities/clear
```

Deletes entries older than the `activityLogRetention` setting (default: `30 days`).

```sh
./craft variant-manager/activities/clear 1
```

Wipes every activity log entry regardless of age. The `1` is a positional argument, not an option.

Cleared entries are gone permanently; there is no trash to restore from.

See [activity log](../user-guide/activity-log.md) for the dashboard equivalent.

## `variant-manager/attributes/backfill`

Register an attribute and option for every name and value already stored on a variant.

```sh
./craft variant-manager/attributes/backfill
```

Reads every variant in batches. Anything already registered is skipped, so the command is safe to re-run. New attributes and options appear in the activity log.

| Option | Default | Description |
|--------|---------|-------------|
| `--batchSize` | `500` | Variants read per batch. |

The **Utilities -> Variant Attributes** utility runs the same work in the queue.

## `resave/variant-attributes`

Re-save every attribute and option.

```sh
./craft resave/variant-attributes --update-search-index
```

Craft's own resave command, with an action this plugin adds. `--update-search-index` rewrites the search keywords for each row, which is what picks up a system name stored before the row was last saved. `resave/all` includes it.

| Option | Default | Description |
|--------|---------|-------------|
| `--update-search-index` | `false` | Rewrite each row's search keywords. |
| `--queue` | `false` | Run in the queue instead of the console. |

## `variant-manager/attributes/orphans`

List attributes and options whose name or value is no longer stored on any variant.

```sh
./craft variant-manager/attributes/orphans
```

Reads every variant, then compares against the registry. Prints one line per orphan and deletes nothing.

```sh
./craft variant-manager/attributes/orphans --prune
```

Deletes them. Anything a variant has started using since the scan is skipped. An attribute takes its display type and field layouts with it, unless `allowAdminChanges` is off, where the rows are deleted but their project config stays. This is permanent, and any custom field values on the deleted rows are lost.

| Option | Default | Description |
|--------|---------|-------------|
| `--prune` | `false` | Delete the orphans instead of only listing them. |
| `--batchSize` | `500` | Variants read per batch. |

See [variant attributes](../user-guide/variant-attributes.md).
