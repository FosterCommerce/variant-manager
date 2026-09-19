# Variant attributes

How the Variant Attributes field stores a variant's attributes, how attributes and options get registered, and the custom fields and display types you can attach to each.

## The field

The **Variant Attributes** field type stores each variant's attributes as name and value pairs. One field covers every attribute, on every product type, so adding Color or a new size needs no field, field layout, or template change.

For the steps, see [installation](../installation.md#add-the-variant-attributes-field). For what it stores and how to query it, see [the field reference](../reference/field-type.md).

The values are plain text on the variant, so a CSV column maps straight onto them.

## The registry

Every attribute name and value your variants use also gets its own row, separate from the variants, and that row is where extra fields go. `Color` becomes an attribute; `Red` and `Blue` become its options. Attach a swatch image, a hex value, or a spec sheet to any of them.

### Where rows come from

| Source | When |
|--------|------|
| A variant save | Any save that stores a name and value on a variant registers them. The field is read-only in the control panel, so the save comes from code rather than a person editing the variant. |
| CSV import | Every import registers the names and values it contains. |
| **Utilities -> Variant Attributes** | Reads every variant in one pass. Run it once after installing. |
| `./craft variant-manager/attributes/backfill` | The same work from the command line. |

Each new attribute and option is recorded in the [activity log](./activity-log.md). A save with no signed-in user, such as a console command or a queue job, is logged against `Unknown`.

### Names

Each attribute and option has two names.

| Name | Editable | Shown to |
|------|----------|----------|
| **System Name** | No | Whatever wrote the value. It is the exact text stored on the variant. |
| **Display Name** | Yes | Shoppers, if your templates use it. |

Rename an option's display name to change what shoppers read. Every product using that value shows the new name, with no import and no change to any variant. Templates read it as `option.title`.

The control panel labels a row by its system name, and adds the display name in brackets once the two differ: `SM (Small)`. Search matches either one.

### Where to find them

**Variant Manager -> Variant Attributes** lists every attribute with its options nested under it, the way entries in a Structure section are. The listing shows the name and the display type, with **System Name** and **Date Created** available from the column menu; open an option to see its **Used by** count.

An option belongs to the attribute it is nested under, so `Blue` under `Color` and `Blue` under `Trim` are separate rows with their own fields. Drag a row to reorder it among the rows sharing its attribute. The control panel does not allow dragging an option onto a different attribute, since that would change which value it stands for.

In the Variant Attributes field on a variant, each name and value is a chip. Click one to open its row in a slideout. A name or value with no row yet shows as plain text until an import or the backfill registers it.

Each attribute is its own filter. **Add a filter** offers **Variant Attribute: Size**, **Variant Attribute: Color** and so on, each listing that attribute's values. Add more than one to combine them.

The filters appear on variant listings, including the Variants index and a product's **Variants** tab. On product listings, a product matches when one of its variants does.

### Display type and fields

**Display Type** is set on the attribute itself, at **Variant Manager -> Variant Attributes**. It sets how the options render on your storefront: dropdown, radio buttons, text buttons, image swatches, color swatches, or lightswitch. It has no effect in the control panel. Dropdown is the default.

**Available Display Types**, at **Settings -> Plugins -> Variant Manager**, narrows that menu to the types your templates render. It applies to every attribute. A developer can also set it in [`availableDisplayTypes`](../reference/configuration.md#availabledisplaytypes), which overrides what that screen saves.

**Default Display Type**, on the same screen, is what a new attribute is given the first time it is registered, by any of the routes above. Attributes that already exist keep the type they have.

The field layouts are set on each attribute's own page. Click an attribute in the table at **Settings -> Plugins -> Variant Manager** to open it. There are two:

- **Attribute Fields** apply to the attribute itself, for a value covering all of its options.
- **Option Fields** apply to each option, for a swatch image, a spec sheet, or a note.

Add whatever the storefront needs.

These settings are stored in project config, and the screen is read-only where `allowAdminChanges` is off, so set them in development and deploy them.

### Removing rows

Attributes and options are created from what your variants store, so the control panel does not offer a delete. Removing one is the job of the orphan prune, which only ever removes rows no variant uses.

An attribute or option whose name or value is no longer on any variant is an orphan. No other process removes them.

`./craft variant-manager/attributes/orphans` lists them. **Prune orphans**, in the **Utilities -> Variant Attributes** utility, deletes them, as does the same command with `--prune`.

Deleting is permanent. See [console commands](../reference/console-commands.md).

## Showing values on a variant card

A variant's values can also be shown on its card, and as a column on element indexes.

Go to **Commerce -> Settings -> Product Types -> {product type} -> Variant Fields** and add the field under **Card Attributes**. The card then shows the values, comma separated:

```text
Small, Blue
```

The values come from the variant's own stored data.

## Related

- [Console commands](../reference/console-commands.md)
- [Template tags](../dev-guide/template-tags.md), reading attributes and options in Twig
- [Permissions](../reference/permissions.md)
