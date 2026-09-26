# Variant attributes

How the Variant Attributes field stores a variant's attributes, how attributes and options get registered, and the custom fields and display types you can attach to each.

## The field

The **Variant Attributes** field type stores each variant's attributes as name and value pairs. One field covers every attribute, on every product type, so adding Color or a new size needs no field, field layout, or template change.

For the steps, see [installation](../installation.md#add-the-variant-attributes-field). For what it stores and how to query it, see [the field reference](../reference/field-type.md).

A CSV column maps straight onto the values, because they are plain text on the variant.

## The registry

Every attribute name and value your variants use also gets its own record, separate from the variants, and that record is where extra fields go. `Color` becomes an attribute; `Red` and `Blue` become its options. Attach a swatch image, a hex value, or a spec sheet to any of them.

### Where records come from

| Source | When |
|--------|------|
| A variant save | Any save that stores a name and value on a variant registers them. The field is read-only in the control panel, so the save comes from code rather than a person editing the variant. |
| **New attribute** or **New option** | Clicking either button registers one record. See [creating attributes and options](#creating-attributes-and-options). |
| **Load attributes from existing variants** | Registers the names and values a product's variants store. See [Variant Maker](./variant-maker.md). |
| CSV import | Every import registers the names and values it contains. |
| **Utilities -> Variant Attributes** | Reads every variant in one pass. |
| `./craft variant-manager/attributes/backfill` | The same work from the command line. |

Each new attribute and option is recorded in the [activity log](./activity-log.md). A save with no signed-in user, such as a console command or a queue job, is logged against `Unknown`.

### Names

Each attribute and option has two names.

| Name | Editable | Shown to |
|------|----------|----------|
| **System Name** | Only while you create the record | Whatever wrote the value. It is the exact text stored on the variant. |
| **Display Name** | Always | Shoppers, if your templates use it. |

Two attributes cannot share a system name, and neither can two options under one attribute. A display name can be repeated.

Rename an option's display name to change what shoppers read. Every product using that value shows the new name, with no import and no change to any variant. Templates read it as `option.title`.

The control panel labels a record by its display name, with the system name in its own column. Search matches either one.

### Creating attributes and options

**Variant Manager -> Variant Attributes** has a **New attribute** button, and so does a Variant Maker row's **Attribute** picker. Once a row has an attribute, the row's **Options** picker has a **New option** button, which creates the option under that attribute.

A menu beside **New attribute** on the index offers **New attribute** or **New option**. If no attribute exists, or the listing is sorted by a column rather than by structure, **New option** reports "Create an attribute before adding an option." A new option starts under the first attribute in the listing. To put it under another, choose that attribute under **Variant Attribute** in its slideout before you create it. An option can't move to another attribute after it is created.

**New attribute** and **New option** open a slideout. Fill in **Display Name**. The control panel copies it into **System Name** as you type. To store different text, such as a short code your other systems already use, change **System Name**.

A new option starts with no **SKU Partial** and no **Price Modifier**. For what each one does, see [Variant Maker](./variant-maker.md).

**New attribute** and **New option** do not appear without Commerce's `commerce-saveProductType` permission.

### Where to find them

**Variant Manager -> Variant Attributes** lists every attribute with its options nested under it, the way entries in a Structure section are. Each record shows its **Display Name**, then **System Name**, **Display Type**, and **Field Set**, with **Date Created** available from the column menu. **Display Type** and **Field Set** are blank for an option. Both are set on the attribute and cover its options. Open an option to see its **Used by** count.

An option belongs to the attribute it is nested under, so `Blue` under `Color` and `Blue` under `Trim` are separate records with their own fields. Drag a record to reorder it. The control panel blocks moving an option to another attribute or out to the top level, because variants store each value under its attribute's name. It also blocks nesting an attribute under another attribute. To put a saved option under another attribute, delete it and create it again under that attribute. See [removing records](#removing-records).

In the Variant Attributes field on a variant, each name and value is a chip. Click one to open its record in a slideout. Manage a variant's values with the [Variant Maker](./variant-maker.md) or a [CSV import](./importing.md). A name or value with no record yet shows as plain text until an import or the backfill registers it.

Each attribute is its own filter. **Add a filter** offers **Variant Attribute: Size**, **Variant Attribute: Color** and so on, each listing that attribute's values. Add more than one to combine them.

The filters appear on variant listings, including the Variants index and a product's **Variants** tab. On product listings, a product matches when one of its variants does.

### Display type

**Display Type** is set on the attribute itself, at **Variant Manager -> Variant Attributes**. It sets how the options render on your storefront: dropdown, radio buttons, text buttons, image swatches, color swatches, or lightswitch. It has no effect in the control panel. Dropdown is the default.

**Available Display Types**, at **Variant Manager -> Settings**, narrows that menu to the types your templates render. It applies to every attribute. Setting [`availableDisplayTypes`](../reference/configuration.md#availabledisplaytypes) in `config/variant-manager.php` overrides what that screen saves.

**Default Display Type**, on the same screen, sets the display type for an attribute the first time any route in the table above registers it. The **New attribute** slideout starts on that type, and you can change it there. Attributes that already exist keep the type they have.

### Field sets

A field set is a name, a handle, and a pair of field layouts that any number of attributes share:

- **Attribute Fields** apply to the attribute itself, for a value covering all of its options.
- **Option Fields** apply to each option, for a swatch image, a spec sheet, or a note.

Two field sets can share a name, but not a handle. The control panel fills the handle in from the name as you type it, and you can change it before saving.

Build one at **Variant Manager -> Settings -> New field set**, then open an attribute at **Variant Manager -> Variant Attributes** and choose it under **Field Set** in the sidebar. An attribute without a field set does not show custom fields. Attributes sharing a field set share its fields, so Color and Trim Color can both use one swatch image field without building the layout twice.

You cannot delete a field set while an attribute uses it. Its page lists those attributes under **Used by**, and **Delete field set** is in the **Save** button's menu.

Changing or clearing an attribute's field set hides the values stored under the old layout. Editing and saving a field of the new set then deletes them. Treat the switch as permanent.

Build a field set in development and deploy it, because it is project config. The field set screen is read-only where `allowAdminChanges` is off. The assignment is a column on the attribute rather than project config, so you set it in each environment.

### Removing records

Open a record and choose **Delete record** from the action menu beside **Save**. On the index, select records and choose **Delete** from the actions menu. Deleting an attribute deletes its options too. To discard a record you are still creating, choose **Delete draft** from its slideout's action menu.

The control panel does not delete an option while a variant stores its value, or an attribute while a variant stores one of its options. Take the value off every variant first.

A deleted system name can be reused. There is no trash to restore from.

An attribute or option whose name or value is no longer on any variant is an orphan. The prune removes them in bulk.

`./craft variant-manager/attributes/orphans` lists them. **Prune orphans**, in the **Utilities -> Variant Attributes** utility, deletes them, as does the same command with `--prune`.

See [console commands](../reference/console-commands.md).

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
