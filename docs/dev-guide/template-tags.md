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

## `craft.variantManager.getAttributeOptions(product, only?)`

Returns the distinct attribute names and the unique values used across a product's variants. Useful for building variant pickers and faceted filters.

Parameters:

- `product`: a `Product` element or a product ID.
- `only` (optional): a string or array of attribute names to limit the result to.

Returns an array of associative arrays, each with:

- `name`: the attribute name, as stored on the variant.
- `values`: a deduplicated array of every value used by any variant for that attribute.

The result is plain strings, so it is safe to pass to `json_encode` for a JavaScript picker:

```twig
{{ craft.variantManager.getAttributeOptions(product.id)|json_encode }}
```

```json
[
  { "name": "Color", "values": ["Red", "Blue"] },
  { "name": "Size", "values": ["Small", "Medium", "Large"] }
]
```

## `craft.variantManager.getAttributeRegistry(product, only?)`

Returns the same names and values, each paired with the [registry](../user-guide/variant-attributes.md) elements that carry its metadata. Use this to render swatches, display names, or any field a developer added.

Takes the same parameters. Each entry adds:

- `attribute`: the attribute element, or `null` if the name is not registered yet. Carries the display type and the attribute's own fields.
- `options`: the option elements, keyed by the raw value. A value that is not registered is absent from this array.

These are elements, so read them in Twig rather than encoding them. `json_encode` inlines every public property of every element.

### Example: render by display type

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

`attribute.displayType` is one of `dropdown`, `radioButtons`, `textButtons`, `imageSwatches`, `colorSwatches` or `lightswitch`. Branch on it to pick how you render the options.

### Example: limit to specific attributes

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
