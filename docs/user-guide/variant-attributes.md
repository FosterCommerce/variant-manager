# Variant attributes

Every attribute name and value your variants use gets a row you can attach fields to. `Color` becomes an attribute; `Red` and `Blue` become its options.

Variants keep storing names and values as plain text, so imports and exports are unchanged. The registry sits alongside them and holds anything extra you want to show a shopper.

## Where they come from

| Source | When |
|--------|------|
| CSV import | Every import registers the names and values it contains. |
| **Utilities -> Variant Attributes** | Reads every variant in one pass. Run it once after installing. |
| `./craft variant-manager/attributes/backfill` | The same work from the command line. |

Each new attribute and option is recorded in the [activity log](./activity-log.md).

## Names

Each attribute and option has two names.

| Name | Editable | Shown to |
|------|----------|----------|
| **CSV Name** / **CSV Value** | No | The import. It is the exact text in your CSV. |
| Title | Yes | Shoppers, if your templates use it. |

Rename an option's title to change what shoppers read. Every product using that value picks it up, with no import and no change to any variant.

## Where to find them

**Variant Manager -> Variant Attributes** lists every attribute. **Variant Manager -> Attribute Options** lists every value, with a sidebar entry per attribute for narrowing the list. An option shows how many variants use it.

In the Variant Attributes field on a variant, each name and value is a chip. Click one to open its row in a slideout. A name or value with no row yet shows as plain text until an import or the backfill registers it.

Each attribute is its own filter. **Add a filter** offers **Variant Attribute: Size**, **Variant Attribute: Color** and so on, each listing that attribute's values. Add more than one to combine them.

The filters appear on variant listings, including the Variants index and a product's **Variants** tab. On product listings, a product matches when one of its variants does.

The name and value shown on a variant are not editable there. Each is a chip that opens the attribute or option in a slideout, where its display name and custom fields are edited.

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
