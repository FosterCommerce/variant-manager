# Template tags

Twig helpers Variant Manager exposes on the `craft` variable for use in storefront templates.

## Reading the field on a variant

The field's value is accessible by its handle:

```twig
{% for attribute in variant.variantAttributes ?? [] %}
  <li>{{ attribute.attributeName }}: {{ attribute.attributeValue }}</li>
{% endfor %}
```

Substitute the handle you gave the field for `variantAttributes`.

## `craft.variantManager.getAttributeRegistry(product, only?)`

Returns each attribute name and the unique values used across a product's variants, paired with the [registry](../user-guide/variant-attributes.md) elements that have its metadata. Use this to render swatches, display names, or any field a developer added.

Parameters:

- `product`: a `Product` element or a product ID.
- `only` (optional): a string or array of attribute names to limit the result to.

Returns an array of associative arrays, each with:

- `name`: the attribute name, as stored on the variant.
- `values`: a deduplicated array of every value used by any variant for that attribute.
- `attribute`: the attribute element, or `null` if the name is not registered yet. Has the display type and the attribute's own fields.
- `options`: the option elements, keyed by the raw value. A value that is not registered is absent from this array.

These are elements, so read them in Twig rather than encoding them. `json_encode` inlines every public property of every element.

## Example: render a picker by display type

1. Call `getAttributeRegistry(product)` for each attribute name, its values, and the elements holding their metadata.
2. Read `attributeOptions.attribute` for the attribute element, and `attributeOptions.options[value]` for each option element.

```twig
{% for attributeOptions in craft.variantManager.getAttributeRegistry(product) %}
  {% set attribute = attributeOptions.attribute %}

  <fieldset>
    <legend>{{ attribute ? attribute.title : attributeOptions.name }}</legend>

    {% for value in attributeOptions.values %}
      {% set option = attributeOptions.options[value] ?? null %}

      <label>
        <input type="radio" name="{{ attributeOptions.name|kebab }}" value="{{ value }}">
        {{ option ? option.title : value }}
      </label>
    {% endfor %}
  </fieldset>
{% endfor %}
```

A name or value that has not been registered yet has no element, so fall back to the raw name and value.

`attribute.displayType` is one of `dropdown`, `radioButtons`, `textButtons`, `imageSwatches`, `colorSwatches`, or `lightswitch`. Branch on it to pick how you render the options.

## `craft.variantManager.getAttributeOptions(product, only?)`

Returns the same names and values as plain strings, without the registry elements. Use it where the metadata is not needed, such as a faceted filter or a JavaScript picker.

Takes the same parameters. Each entry has `name` and `values` only, so it is safe to pass to `json_encode`:

```twig
{{ craft.variantManager.getAttributeOptions(product.id)|json_encode }}
```

```json
[
  { "name": "Color", "values": ["Red", "Blue"] },
  { "name": "Size", "values": ["Small", "Medium", "Large"] }
]
```

To limit the result to some of the attributes, pass their names:

```twig
{% set colorsAndSizes = craft.variantManager.getAttributeOptions(product, ['Color', 'Size']) %}
```

Pass a single string for one attribute:

```twig
{% set colors = craft.variantManager.getAttributeOptions(product, 'Color') %}
```

## Related

- [Querying variants](./twig-queries.md), filtering `craft.variants()` by attribute values.
- [Add to cart recipe](../recipes/add-to-cart.md), a full variant picker that adds to cart.
- [Variant filter recipe](../recipes/variant-filter.md), client-side filtering examples.
