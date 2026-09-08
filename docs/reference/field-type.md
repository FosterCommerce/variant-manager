# Variant Attributes field

The custom field type that Variant Manager uses to store option name and value pairs on each variant.

## What it stores

The field's value is an array of associative arrays, each one an attribute name and value:

```php
[
    ['attributeName' => 'Color', 'attributeValue' => 'Red'],
    ['attributeName' => 'Size', 'attributeValue' => 'Small'],
]
```

It is persisted as JSON in the element content row (`Schema::TYPE_JSON`).

## Filtering in queries

Use the field handle as an element-query parameter on `craft.variants()` (Twig) or `Variant::find()` (PHP). The filter accepts a string, an associative array, or a list of either.

See [querying variants](../dev-guide/twig-queries.md) for the supported filter shapes and SQL behavior.

In the control panel, each registered attribute is its own filter under **Add a filter**, on both variant and product listings. On a product listing the filter is applied through Commerce's `hasVariant` param, so a product matches when one of its variants does.

## Related

- [Installation](../installation.md), adding the field to a variant field layout.
- [Template tags](../dev-guide/template-tags.md), reading the field and the registry in Twig.
- [Variant attributes](../user-guide/variant-attributes.md), display types, custom fields, and variant cards.
