# Roadmap

Internal working notes. Not linked from the docs index.

## Variant Maker

### Create attributes and options from the builder

Today a builder row can only pick from attributes and options the registry already holds. Creating either one means leaving the product and going to **Variant Manager -> Variant Attributes** first.

Deferred 2026-09-15: creating them elsewhere first is little enough work that it does not justify a second creation path in the builder.

If it is picked up, the shape to match is Craft's element select, which offers a create option inside the selection modal. The row partial already renders two `forms.elementSelect` fields, so the work is a create action for `VariantAttribute` that respects the row's `attributeId` criteria, plus the field layout an attribute or option carries.

### Editable preview table

The preview renders what generating would do. Making its cells editable would let a merchant correct one title, SKU or price before generating, the way the CSV importer lets them edit a file.

Deferred 2026-09-15.

Open questions if it is picked up:

- Edits are keyed by combination. A changed builder row invalidates some of them, so either the edits merge by combination key and survive, or they are discarded whenever the plan changes. Merging is the useful behavior and the harder one.
- Editing turns the plan into stored state. It currently derives from the settings on every request, which is what keeps the preview honest about the current builder.
- A large plan is already one HTML response with no pagination. Editable cells multiply the cost per row.
