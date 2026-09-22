# Variant Maker

Generate a product's variants in the control panel, one for every combination of the attributes and options you pick.

## Permissions

The preview, **Generate variants**, **New attribute**, and **New option** all require Commerce's `commerce-saveProductType` permission for the product's type.

## Turning it on

Disabled by default for every product type.

Go to **Variant Manager -> Settings** and check the product types that should offer it. Setting [`variantMakerProductTypes`](../reference/configuration.md#variantmakerproducttypes) in `config/variant-manager.php` overrides what that screen saves.

The product type also needs a Variant Attributes field in its variant field layout. Without one the tab stays hidden, even when the product type is checked.

A saved product then shows a **Variant Maker** tab, last in the row. A new product, or a duplicate you have not saved yet, shows the tab with a note to save first.

## Attributes to combine

One row per attribute. Pick the attribute, then pick as many of its options as you want.

The attribute and option lists come from the registry. **New attribute** and **New option**, in the pickers, register a record from the product's edit page. See [creating attributes and options](./variant-attributes.md#creating-attributes-and-options).

A store whose variants predate the plugin starts with an empty registry, so the pickers start empty. To register every attribute in the catalog at once, import a CSV, run the **Utilities -> Variant Attributes** utility, or run `./craft variant-manager/attributes/backfill`. See [variant attributes](./variant-attributes.md).

**Load attributes from existing variants** adds a row for every attribute this product's variants store, with those values selected. The button registers a value the registry has no record for, skips an attribute that already has a Variant Maker row, and reports when every attribute already has one. The rows are not saved until you save the product.

Every option in a row is combined with every option in every other row. Three sizes, four colors, and two finishes make 24 combinations.

The options list is limited to the attribute chosen in that row, so changing a row's attribute clears the options under it.

Every row needs both an attribute and at least one option. If either is empty, the product does not save, and the error names the row number. A product with no rows saves.

Row order sets the order the Variant Maker assembles SKU partials in, for the default SKU format below.

## What to do with existing variants

The mode sets whether existing variants are rewritten.

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

The Variant Maker does not set dimensions, weight, tax and shipping categories, minimum and maximum quantity, promotional price, or custom fields. Edit those on the Variants tab after generating.

Where the store has more than one inventory location, an **Inventory location** menu appears under the table. Stock is written to the location you pick.

### Title and SKU formats

In a format, `{Attribute Name}` becomes the option chosen under that attribute. Any other text is used as typed, so a product code goes in literally.

```text
TEE-{Size}-{Color}
```

Under the properties table, the Variant Maker lists the tokens for the attributes on your rows.

The Variant Maker uses each option's **SKU Partial**, or the option's own value where it has no partial. Set SKU partials at **Variant Manager -> Variant Attributes**, or from the slideout that opens when you click an option chip.

Leave a format blank for the default:

- A title is the combination joined by a slash: `Small / Red`.
- A SKU is the product slug followed by each option, joined by dashes. Spaces become dashes.

Each format field's placeholder shows the format that reproduces the default.

A SKU format collapses spaces and dash runs in each token's value to a single dash, the way a blank format does. A title format leaves the values as they are.

### Price modifiers

Each option can have a **Price Modifier**, set in the same place as its SKU partial. A combination's price is the base price plus the modifier of every option in it. The modifier is a decimal amount in the store's primary currency, to two decimal places.

## Preview

The table below the form updates as you change the rows. It lists every combination and what generating would do to it.

| Status | Meaning |
|--------|---------|
| **Create** | No variant has this combination yet. |
| **Update** | A variant exists, the mode allows updates, and at least one included property would change it. A changed stock count on its own is enough. |
| **Unchanged** | A variant exists and no field the Variant Maker manages would change. |
| **Delete** | Replace mode only. The variant's combination is not in the generated set. |

A column shows `old -> new` only where the run would write that value. An excluded property shows **Commerce default** on a Create row, and **Kept** on every other row. The Stock column instead shows **Unlimited** where inventory is not tracked. On a Create row it shows **None** where inventory is tracked with no count set; every other row shows **Kept**.

The preview reads the rows as they stand on screen, not what was last saved.

### SKU warnings

Each SKU must be unique across the store and no longer than 255 characters. A row that breaks either rule is flagged in red under its SKU, and **Generate variants** reports the problem instead of running.

| Warning | Cause |
|---------|-------|
| Another row builds this same SKU | Two combinations resolve to the same SKU, or one matches a variant on this product the run leaves alone. Usually a format with no `{Attribute Name}` token, or two options sharing a SKU partial. |
| A variant on another product already uses this SKU | The SKU is taken elsewhere in the store. Comparison ignores case. |
| Longer than 255 characters | The assembled SKU is over the limit Commerce enforces. |

## Saving and generating

Saving the product saves the rows and properties. The save does not create variants.

The settings are stored per product, so each product keeps its own attributes, mode, and properties.

**Generate variants** runs the job in the background and returns you to the page. Watch **Variant Manager -> Dashboard** for the result. It records how many variants were created, updated, and deleted, or the error if the run failed.

Generating uses the saved settings, so **Generate variants** stays disabled while the product has unsaved changes.

The whole run is one transaction, including the stock writes. If any variant fails to save, no variant is written, no stock level changes, and the activity log records why.

The preview does not refresh itself when the job finishes. Reload the page to see the new variants.

## Related

- [Variant attributes](./variant-attributes.md), where SKU partials and price modifiers are set
- [Activity log](./activity-log.md), where a generation result is recorded
- [Console commands](../reference/console-commands.md), previewing a plan from the command line
- [Configuration reference](../reference/configuration.md#variantmakerproducttypes)
