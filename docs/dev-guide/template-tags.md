# Template tags

Twig helpers Variant Manager exposes on the `craft` variable for use in storefront templates.

## `craft.variantManager.getAttributeOptions(product, only?)`

Returns the distinct attribute names and the unique values used across a product's variants. Useful for building variant pickers and faceted filters.

Parameters:

- `product`: a `Product` element or a product ID.
- `only` (optional): a string or array of attribute names to limit the result to.

Returns an array of associative arrays, each with:

- `name`: the attribute name.
- `values`: a deduplicated array of every value used by any variant for that attribute.

### Example output

```twig
{% set attributeOptions = craft.variantManager.getAttributeOptions(product.id) %}
{{ attributeOptions | json_encode }}
```

Renders something like:

```json
[
  { "name": "Color", "values": ["Red", "Blue"] },
  { "name": "Size", "values": ["Small", "Medium", "Large"] }
]
```

### Example: build a radio picker for every attribute

```twig
{% set product = craft.products().id(30).one() %}

{% for attribute in craft.variantManager.getAttributeOptions(product) %}
  <fieldset>
    <legend>{{ attribute.name }}</legend>
    {% for value in attribute.values %}
      <label>
        <input type="radio" name="{{ attribute.name|kebab }}" value="{{ value }}">
        {{ value }}
      </label>
    {% endfor %}
  </fieldset>
{% endfor %}
```

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
