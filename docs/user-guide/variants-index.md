# Variants index

One listing of every variant in the store, with filters and a bulk edit action.

## Where to find it

**Variant Manager -> Variants**.

Commerce lists variants under their product. This index lists them on their own, so a filter applies across the whole catalog rather than one product at a time.

## Columns

Each row shows the variant's product, the standard Commerce columns, whether inventory is tracked, and the stock count.

To add more, set [`defaultVariantTableAttributes`](../reference/configuration.md#defaultvarianttableattributes) in `config/variant-manager.php` to a list of variant field handles. They are appended to the columns above.

```php
'defaultVariantTableAttributes' => ['careInstructions', 'fabricWeight'],
```

Each person can also change their own columns from the index, the way any Craft element index works. `defaultVariantTableAttributes` sets the starting columns.

## Filters

On top of Craft's own rules and Commerce's product and SKU rules, **Add a filter** offers:

- **Stock**, a number rule, so equals, less than, greater than, between, and the rest.
- **Inventory tracked**, for separating tracked variants from untracked.
- One filter per registered attribute, so a filter for Color offers every option value registered under it, whether or not a variant uses it.

The attribute filters are the same ones Commerce's own product and variant indexes get. See [variant attributes](./variant-attributes.md).

## Bulk edit a field

Select variants, then choose **Bulk edit field** from the actions menu. Pick a field, set one value, and the action writes that value to every selected variant.

The field list is not every field on the variant. It comes from [`bulkEditableVariantFields`](../reference/configuration.md#bulkeditablevariantfields) in `config/variant-manager.php`:

```php
'bulkEditableVariantFields' => ['careInstructions', 'inventoryTracked'],
```

Two limits:

- **Custom fields only, plus `inventoryTracked`.** Price, SKU, dimensions, and weight are native Commerce properties, not custom fields, so they cannot be bulk edited. To change those across many variants, export the product, edit the column in a spreadsheet, and reimport. See [exporting](./exporting.md).
- **Config file only.** The setting has no control panel screen.

Each field renders its own input, so a date field gets a date picker.

Once at least one handle in the setting matches a field on a variant field layout, the action appears for users with `variant-manager:manage`. See [permissions](./permissions.md).
