# Variant attributes

How the Variant Attributes field stores a variant's attributes, how attributes and options get registered, and the custom fields and display types you can attach to each.

## The field

The **Variant Attributes** field type stores each variant's attributes as name and value pairs. One field covers every attribute, on every product type, so adding Color or a new size needs no field, field layout, or template change.

For the steps, see [installation](../installation.md#add-the-variant-attributes-field). For what it stores and how to query it, see [the field reference](../reference/field-type.md).

A CSV column maps straight onto the values, because they are plain text on the variant.

## The registry

Every attribute name and value your variants use also gets its own row, separate from the variants, and that row is where extra fields go. `Color` becomes an attribute; `Red` and `Blue` become its options. Attach a swatch image, a hex value, or a spec sheet to any of them.

### Where rows come from

| Source | When |
|--------|------|
| A variant save | Any save that stores a name and value on a variant registers them. The field is read-only in the control panel, so the save comes from code rather than a person editing the variant. |
| **New attribute** or **New option** | Clicking either button registers one row. See [creating attributes and options](#creating-attributes-and-options). |
| **Load attributes from existing variants** | Registers the names and values a product's variants store. See [Variant Maker](./variant-maker.md). |
| CSV import | Every import registers the names and values it contains. |
| **Utilities -> Variant Attributes** | Reads every variant in one pass. |
| `./craft variant-manager/attributes/backfill` | The same work from the command line. |

Each new attribute and option is recorded in the [activity log](./activity-log.md). A save with no signed-in user, such as a console command or a queue job, is logged against `Unknown`.

### Names

Each attribute and option has two names.

| Name | Editable | Shown to |
|------|----------|----------|
| **System Name** | Only while you create the row | Whatever wrote the value. It is the exact text stored on the variant. |
| **Display Name** | Always | Shoppers, if your templates use it. |

Two attributes cannot share a system name, and neither can two options under one attribute. A display name can be repeated.

Rename an option's display name to change what shoppers read. Every product using that value shows the new name, with no import and no change to any variant. Templates read it as `option.title`.

The control panel labels a row by its system name, and adds the display name in brackets once the two differ: `SM (Small)`. Search matches either one.

### Creating attributes and options

**Variant Manager -> Variant Attributes** has a **New attribute** button, and so does a Variant Maker row's **Attribute** picker. Once a row has an attribute, the row's **Options** picker has a **New option** button, which creates the option under that attribute.

Both open a slideout. Fill in **Display Name**. The control panel copies it into **System Name** as you type. To store different text, such as a short code your other systems already use, change **System Name**.

A new option starts with no **SKU Partial** and no **Price Modifier**. For what each one does, see [Variant Maker](./variant-maker.md).

Neither button appears without Commerce's `commerce-saveProductType` permission.

### Where to find them

**Variant Manager -> Variant Attributes** lists every attribute with its options nested under it, the way entries in a Structure section are. The listing shows the name and the display type, with **System Name** and **Date Created** available from the column menu; open an option to see its **Used by** count.

An option belongs to the attribute it is nested under, so `Blue` under `Color` and `Blue` under `Trim` are separate rows with their own fields. Drag a row to reorder it among the rows sharing its attribute. The control panel does not allow dragging an option onto a different attribute, because that would change which value it stands for.

In the Variant Attributes field on a variant, each name and value is a chip. Click one to open its row in a slideout. A name or value with no row yet shows as plain text until an import or the backfill registers it.

Each attribute is its own filter. **Add a filter** offers **Variant Attribute: Size**, **Variant Attribute: Color** and so on, each listing that attribute's values. Add more than one to combine them.

The filters appear on variant listings, including the Variants index and a product's **Variants** tab. On product listings, a product matches when one of its variants does.

### Display type

**Display Type** is set on the attribute itself, at **Variant Manager -> Variant Attributes**. It sets how the options render on your storefront: dropdown, radio buttons, text buttons, image swatches, color swatches, or lightswitch. It has no effect in the control panel. Dropdown is the default.

**Available Display Types**, at **Variant Manager -> Settings**, narrows that menu to the types your templates render. It applies to every attribute. Setting [`availableDisplayTypes`](../reference/configuration.md#availabledisplaytypes) in `config/variant-manager.php` overrides what that screen saves.

**Default Display Type**, on the same screen, sets the display type for an attribute the first time any route in the table above registers it. The **New attribute** slideout starts on that type, and you can change it there. Attributes that already exist keep the type they have.

### Field sets

A field set is a named pair of field layouts that any number of attributes share:

- **Attribute Fields** apply to the attribute itself, for a value covering all of its options.
- **Option Fields** apply to each option, for a swatch image, a spec sheet, or a note.

Build one at **Variant Manager -> Settings -> New field set**, then open an attribute from the table on that screen and choose it under **Field Set**. An attribute without a field set does not show custom fields. Attributes sharing a field set share its fields, so Color and Trim Color can both use one swatch image field without building the layout twice.

You cannot delete a field set while an attribute uses it. Its page lists those attributes under **Attributes using this field set**.

Changing or clearing an attribute's field set hides the values stored under the old layout. Editing and saving a field of the new set then deletes them. Treat the switch as permanent.

Build a field set in development and deploy it, because it is project config. The field set screen is read-only where `allowAdminChanges` is off. The assignment is a column on the attribute rather than project config, so you set it in each environment.

### Removing rows

The control panel offers no delete for a saved attribute or option, because variants match on the text the row holds. Removing one is the job of the orphan prune, which only ever removes rows no variant uses. To discard a row you are still creating, choose **Delete draft** from its slideout's action menu.

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
