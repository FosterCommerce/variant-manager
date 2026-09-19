# Variant Maker

Generate a product's variants in the control panel, one for every combination of the attributes and options you pick.

Variant Maker reads no external data, so a store with no ERP, PIM, or spreadsheet can still build a full variant set.

## Permissions

The preview and **Generate variants** both require Commerce's `commerce-editProductType` permission for the product's type. That is the only permission checked, so a user who can edit the product type can generate variants without any Variant Manager permission.

## Turning it on

Disabled by default for every product type.

Go to **Settings -> Plugins -> Variant Manager** and check the product types that should offer it. Setting [`variantMakerProductTypes`](../reference/configuration.md#variantmakerproducttypes) in `config/variant-manager.php` overrides what that screen saves.

The product type also needs a Variant Attributes field in its variant field layout. Without one the tab stays hidden, even when the product type is checked.

A saved product then shows a **Variant Maker** tab, last in the row. A brand new product shows the tab with a note to save first.

## Attributes to combine

One row per attribute. Pick the attribute, then pick as many of its options as you want.

The attribute and option lists come from the registry, so a row can only offer what is already registered. A store whose variants predate the plugin has an empty registry and an empty list. Fill it by importing a CSV, saving a variant that stores attribute pairs, running the **Variant Attributes** utility, or running `variant-manager/attributes/backfill`. See [variant attributes](./variant-attributes.md).

Every option in a row is combined with every option in every other row. Three sizes, four colors, and two finishes make 24 combinations.

The options list is limited to the attribute chosen in that row, so changing a row's attribute clears the options under it.

A row needs both an attribute and at least one option. Leave either empty and the product does not save, with the row number named in the error.

Row order sets the order the SKU partials assemble in, for the default SKU format below.

## What to do with existing variants

The mode is the only control over whether an existing variant is rewritten.

| Mode | What it does |
|------|--------------|
| **Add missing only** | Creates the combinations that do not exist yet. Leaves every existing variant unchanged. |
| **Add and update existing** | Also rewrites existing variants with every included property. |
| **Replace: add, update, and delete** | Does both, and deletes any variant whose combination is not in the generated set. |

Replace deletes variants. The preview lists each one as **Delete** before you generate.

## Variant properties

Each property is a row in the table with two controls:

- **Include**: whether the Variant Maker sets this property. Off means a new variant gets Commerce's default and an existing variant keeps what it has. A new variant is always titled either way, falling back to its combination.
- **Value**: a format, a number, or a switch, depending on the property.

SKU and price are always included, so their switches are on and disabled. Commerce requires both on every variant.

| Property | Value |
|----------|-------|
| Title | Format. Hidden where the product type builds variant titles from its own format. |
| SKU | Format. |
| Price | The price each combination starts from, before its options' price modifiers. |
| Track inventory | Switch. |
| Stock | Number. Shown only while **Track inventory** is included and on. |
| Allow out of stock purchases | Switch. Shown on the same condition as Stock. |
| Available for purchase | Switch. |
| Free shipping | Switch. |
| Promotable | Switch. |

The Variant Maker does not set these, so edit them on the Variants tab after generating: dimensions, weight, tax and shipping categories, minimum and maximum quantity, promotional price, and custom fields.

Where the store has more than one inventory location, an **Inventory location** menu appears under the table. Stock is written to the location you pick.

### Title and SKU formats

In a format, `{Attribute Name}` becomes the option chosen under that attribute. Any other text is used as typed, so a product code goes in literally.

```text
TEE-{Size}-{Color}
```

Each attribute contributes its option's **SKU Partial** where the option has one, and the option's own value where it does not. Set SKU partials at **Variant Manager -> Variant Attributes**, or from the slideout that opens when you click an option chip.

Leave a format blank for the default:

- A title is the combination joined by a slash: `Small / Red`.
- A SKU is the product's default variant SKU followed by each option, joined by dashes. Spaces become dashes.

A format you type is used exactly as written, spaces included.

### Price modifiers

Each option can have a **Price Modifier**, set in the same place as its SKU partial. A combination's price is the base price plus the modifier of every option in it. The modifier is a decimal amount in the store's primary currency, to two decimal places.

## Preview

The table below the form updates as you change the builder. It lists every combination and what generating would do to it.

| Status | Meaning |
|--------|---------|
| **Create** | No variant has this combination yet. |
| **Update** | A variant exists, the mode allows updates, and at least one included property would change it. A changed stock count on its own is enough. |
| **Unchanged** | A variant exists and no field the Variant Maker manages would change. |
| **Delete** | Replace mode only. The variant's combination is not in the generated set. |

A column shows `old -> new` only where the run would write that value. An excluded property shows **Commerce default** on a Create row, and **Kept** on every other row. The Stock column instead shows **Unlimited** where inventory is not tracked. On a Create row it shows **None** where inventory is tracked with no count set; every other row shows **Kept**.

The preview reads the builder as it stands on screen, not what was last saved.

### SKU warnings

Commerce requires every SKU to be unique across the store and no longer than 255 characters. A row that breaks either rule is flagged in red under its SKU, and **Generate variants** does not run while any row is flagged.

| Warning | Cause |
|---------|-------|
| Another row builds this same SKU | Two combinations resolve to the same SKU, or one matches a variant on this product the run leaves alone. Usually a format with no `{Attribute Name}` token, or two options sharing a SKU partial. |
| A variant on another product already uses this SKU | The SKU is taken elsewhere in the store. Comparison ignores case. |
| Longer than 255 characters | The assembled SKU is over the limit Commerce enforces. |

## Saving and generating

Saving the product saves the builder, and creates no variants.

The settings are stored per product, so each product keeps its own attributes, mode, and properties.

**Generate variants** runs the job in the background and returns you to the page. Watch **Variant Manager -> Dashboard** for the result. It records how many variants were created, updated, and deleted, or the error if the run failed.

Generating uses the saved settings, so save the product before pressing **Generate variants**. A product with unsaved changes shows a message instead of generating.

The whole run is one transaction, including the stock writes. If any variant fails to save, no variant is written, no stock level changes, and the activity log records why.

The preview does not refresh itself when the job finishes. Reload the page to see the new variants.

## Related

- [Variant attributes](./variant-attributes.md), where SKU partials and price modifiers are set
- [Activity log](./activity-log.md), where a generation result is recorded
- [Console commands](../reference/console-commands.md), previewing a plan from the command line
- [Configuration reference](../reference/configuration.md#variantmakerproducttypes)
