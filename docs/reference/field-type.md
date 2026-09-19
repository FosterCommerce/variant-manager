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

For the supported filter shapes and SQL behavior, see [querying variants](../dev-guide/twig-queries.md).

In the control panel, each registered attribute is its own filter, applied on a product listing through Commerce's `hasVariant` param. For where the filters appear, see [variant attributes](../user-guide/variant-attributes.md).

## Related

- [Installation](../installation.md), adding the field to a variant field layout.
- [Template tags](../dev-guide/template-tags.md), reading the field and the registry in Twig.
- [Variant attributes](../user-guide/variant-attributes.md), display types, custom fields, and variant cards.
