# Variant attributes

Every attribute name and value your variants use gets a row you can attach fields to. `Color` becomes an attribute; `Red` and `Blue` become its options.

Variants keep storing names and values as plain text, so imports and exports are unchanged. The registry sits alongside them and holds anything extra you want to show a shopper.

## Where they come from

| Source | When |
|--------|------|
| A variant save | Any save that stores a name and value on a variant registers them, including an integration writing through Craft's API. |
| CSV import | Every import registers the names and values it contains. |
| **Utilities -> Variant Attributes** | Reads every variant in one pass. Run it once after installing. |
| `./craft variant-manager/attributes/backfill` | The same work from the command line. |

Each new attribute and option is recorded in the [activity log](./activity-log.md). A save with no signed-in user, such as a console command or a queue job, is logged against `Unknown`.

## Names

Each attribute and option has two names.

| Name | Editable | Shown to |
|------|----------|----------|
| **System Name** | No | Whatever wrote the value. It is the exact text stored on the variant. |
| **Display Name** | Yes | Shoppers, if your templates use it. |

Rename an option's display name to change what shoppers read. Every product using that value picks it up, with no import and no change to any variant. Templates read it as `option.title`.

The control panel labels a row by its system name, and adds the display name in brackets once the two differ: `PBS200 (Pebble Stone)`. Search matches either one.

## Where to find them

**Variant Manager -> Variant Attributes** lists every attribute with its options nested under it, the way entries in a Structure section are. An option shows how many variants use it.

An option belongs to the attribute it sits under, so `Blue` under `Color` and `Blue` under `Paint chips` are separate rows with their own fields. Drag a row to reorder it among the rows sharing its attribute. Dragging an option onto a different attribute is refused, since that would change which value it stands for.

In the Variant Attributes field on a variant, each name and value is a chip. Click one to open its row in a slideout. A name or value with no row yet shows as plain text until an import or the backfill registers it.

Each attribute is its own filter. **Add a filter** offers **Variant Attribute: Size**, **Variant Attribute: Color** and so on, each listing that attribute's values. Add more than one to combine them.

The filters appear on variant listings, including the Variants index and a product's **Variants** tab. On product listings, a product matches when one of its variants does.

## Showing values on a variant card

A variant's values can also be shown on its card, and as a column on element indexes.

Go to **Commerce -> Settings -> Product Types -> {product type} -> Variant Fields** and add the field under **Card Attributes**. The card then shows the values, comma separated:

```text
Youth XS, Blue
```

Values come from the variant's own stored data, so the card costs no extra queries.

## Display type and fields

**Display Type** is set on the attribute itself, at **Variant Manager -> Variant Attributes**. It tells your storefront how to render the options: dropdown, radio buttons, text buttons, image swatches, color swatches or lightswitch. It does not change anything in the control panel. Dropdown is the default.

**Available Display Types**, at **Settings** -> **Plugins** -> **Variant Manager**, narrows that menu to the types your templates render. It applies to every attribute. A developer can also set it in [`availableDisplayTypes`](../reference/configuration.md#availabledisplaytypes), which disables the control panel field.

**Default Display Type**, on the same screen, is what a new attribute is given when an import first registers it. Attributes that already exist keep the type they have.

The field layouts are set separately, at **Settings -> Plugins -> Variant Manager**, then pick an attribute. There are two:

- **Attribute Fields** apply to the attribute itself, for something covering all of its options.
- **Option Fields** apply to each option, for a swatch image, a spec sheet, or a note.

Add whatever the storefront needs.

These settings are stored in project config, so they are made in your development environment and deployed. The screen is read-only where `allowAdminChanges` is off.

## Removing rows

Attributes and options are created from what your variants store, so the control panel does not offer a delete. Removing one is the job of the prune, which only ever removes rows nothing uses.

An attribute or option whose name or value is no longer on any variant is an orphan. Nothing removes them automatically.

`./craft variant-manager/attributes/orphans` lists them. **Prune orphans**, in the **Utilities -> Variant Attributes** utility, deletes them, as does the same command with `--prune`.

Deleting is permanent. See [console commands](../reference/console-commands.md).

## Related

- [Console commands](../reference/console-commands.md)
- [Template tags](../dev-guide/template-tags.md), reading attributes and options in Twig
- [Permissions](../reference/permissions.md)
