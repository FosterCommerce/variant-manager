# Querying variants

How to filter Commerce variants by their Variant Attributes field. Audience: developers building storefront templates or PHP code that queries variants.

Substitute the handle you gave your Variant Attributes field (`variantAttributes`, `myVariantAttributes`, anything you chose) for `variantAttributes` in the examples below.

## Three filter shapes

The Variant Attributes field accepts:

1. A string. Returns variants that have **any** attribute with that value.
2. An associative array. Returns variants that match **every** name/value pair.
3. A list of strings and/or associative arrays. Returns variants that match **any** of the entries.

## Filter by an option value

Find variants whose Variant Attributes contains the value `Red` under any attribute name.

PHP:

```php
\craft\commerce\elements\Variant::find()
    ->variantAttributes('Red')
    ->all();
```

Twig:

```twig
{% set variants = craft.variants().variantAttributes('Red').all() %}
```

## Filter by all attribute and option pairs (AND)

Find variants that have `Color = Red` AND `Size = Small`.

PHP:

```php
\craft\commerce\elements\Variant::find()
    ->variantAttributes([
        'Color' => 'Red',
        'Size' => 'Small',
    ])
    ->all();
```

Twig:

```twig
{% set filter = {
  'Color': 'Red',
  'Size': 'Small'
} %}
{% set variants = craft.variants().variantAttributes(filter).all() %}
```

## Filter by any attribute and option pair (OR)

Find variants that match any of the entries in a list. Each entry can be a value-only string or a `Name => Value` pair.

PHP:

```php
\craft\commerce\elements\Variant::find()
    ->variantAttributes([
        ['Color' => 'Red'],
        'Cotton',
        ['Size' => 'Small'],
    ])
    ->all();
```

Twig:

```twig
{% set filter = [
  { 'Color': 'Red' },
  'Cotton',
  { 'Size': 'Small' }
] %}
{% set variants = craft.variants().variantAttributes(filter).all() %}
```

## How it works

The field stores attributes as JSON. The query builder generates database conditions tailored to your database:

- MySQL: `json_search()` against the field's JSON path, with one condition per name/value pair.
- PostgreSQL: `@>` containment against the JSON column.

You do not need to do anything special to enable this; both paths are picked automatically.

## Errors

- `$value items must be associative arrays or strings`: a list entry was neither. Check that every element of the array is a string or an `{ name: value }` map.
- `$value must be either an array or a string`: a non-string, non-array value was passed (a number, a Date, an Element). Convert to a string before passing.

## Related

- [Template tags](./template-tags.md), the `getAttributeOptions` helper.
- [Add to cart recipe](../recipes/add-to-cart.md).
- [Variant filter recipe](../recipes/variant-filter.md).
