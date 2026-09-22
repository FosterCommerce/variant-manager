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

For the dashboard equivalent, see [activity log](../user-guide/activity-log.md).

## `variant-manager/attributes/backfill`

Register an attribute and option for every name and value already stored on a variant.

```sh
./craft variant-manager/attributes/backfill
```

Reads every variant in batches. An attribute or option already registered is skipped, so the command is safe to re-run. New attributes and options appear in the activity log.

| Option | Default | Description |
|--------|---------|-------------|
| `--batchSize` | `500` | Variants read per batch. |

The **Utilities -> Variant Attributes** utility runs the same work in the queue.

## `resave/variant-attributes`

Re-save every attribute and option.

```sh
./craft resave/variant-attributes --update-search-index
```

Craft's own resave command, with an action this plugin adds. `--update-search-index` rewrites the search keywords for each row, so a system name stored before the row was last saved becomes searchable. `resave/all` includes it.

| Option | Default | Description |
|--------|---------|-------------|
| `--update-search-index` | `false` | Rewrite each row's search keywords. |
| `--queue` | `false` | Run in the queue instead of the console. |

## `variant-manager/attributes/orphans`

List attributes and options whose name or value is no longer stored on any variant.

```sh
./craft variant-manager/attributes/orphans
```

Reads every variant, then compares against the registry. Prints one line per orphan and does not delete rows.

```sh
./craft variant-manager/attributes/orphans --prune
```

Deletes exactly the orphans the scan found. A value a variant started storing after the scan began is deleted too, and re-registered on that variant's next save without its custom field values. The display type is on the attribute's row, so deleting the row deletes it. The attribute's field set stays in project config, because other attributes can use it. This is permanent, and any custom field values on the deleted rows are lost.

| Option | Default | Description |
|--------|---------|-------------|
| `--prune` | `false` | Delete the orphans instead of only listing them. |
| `--batchSize` | `500` | Variants read per batch. |

See [variant attributes](../user-guide/variant-attributes.md).

## `variant-manager/variant-maker/plan`

Print what generating a set of combinations would do to a product's variants.

```sh
./craft variant-manager/variant-maker/plan 4054 --select="Size=Small,Medium;Color=Red"
```

The first argument is the product ID. Prints one line per combination with its status, the SKU, and the price, and any SKU warning under it. The command does not save changes.

The selection is read from `--select` rather than the product's saved Variant Maker settings.

| Option | Default | Description |
|--------|---------|-------------|
| `--select` | `''` | Attributes and values to combine, as `Name=A,B;Other Name=C`. |
| `--mode` | `add` | One of `add`, `update`, or `replace`. `add` reports every existing combination as unchanged. `update` reports one as an update where the built title, SKU, or price differs. `replace` does that and adds a delete row for each variant the set does not cover. |
| `--skuFormat` | `''` | SKU format, using `{Attribute Name}` tokens that resolve to each option's SKU partial, or to the option value where no partial is set. Blank uses the product slug followed by each option. |
| `--basePrice` | `''` | Price each combination starts from. Blank uses the product's default variant price. |

See [Variant Maker](../user-guide/variant-maker.md).
