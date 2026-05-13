# Console commands

Commands exposed by Variant Manager. Run from your project root.

## `variant-manager/activities/clear`

Delete activity log entries.

```sh
./craft variant-manager/activities/clear
```

Deletes entries older than the `activityLogRetention` setting (default: `30 days`). Same behaviour as Craft's garbage collection running on the plugin.

```sh
./craft variant-manager/activities/clear --all
```

Wipes every activity log entry regardless of age.

Cleared entries are gone permanently; there is no trash to restore from.

See [activity log](../user-guide/activity-log.md) for the dashboard equivalent.
