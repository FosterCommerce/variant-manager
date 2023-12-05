# Function Reference

## Available Functions

The following functions are available:

## `craft.variantManager.getAttributeOptions(product, attributes)`

Accepts either a product ID or a `Product`, and an optional attribute filter to return options for specific attributes only.

Returns an array of associative arrays holding an attribute name and an array of possible values for that option.

```twig
{% set attributeOptions = craft.variantManager.getAttributeOptions(product.id) %}
{{ attributeOptions | json_encode }}
```

Would output something similar to:

```json
[
  {
    "name": "Option A",
    "values": [
      "Value 1",
      "Value 2",
      "Value 3"
    ]
  },
  {
    "name": "Option B",
    "values": [
      "Value 1"
    ]
  },
  {
    "name": "Option C",
    "values": [
      "Value 1",
      "Value 2",
      "Value 3",
      "Value 4"
    ]
  },
]
```

### Example: Get all attribute options

```twig
{% set product = craft.products().id(30) %}

<div>
  {% set attributeOptions = craft.variantManager.getAttributeOptions(product.id) %}
  {% for item in attributeOptions %}
    {% set itemIndex = loop.index %}
    <h2>{{ item.name }}</h2>
    {% for value in item.values %}
      <label>
        <input type="radio" name="{{ itemIndex }}_option" value="{{ value }}"/>
        <span>{{ value }}</span>
      </label>
    {% endfor %}
  {% endfor %}
</div>
```

### Example: Get a specific attribute's options

```twig
{% set product = craft.products().id(30) %}

<div>
  {% set attributeOptions = craft.variantManager.getAttributeOptions(product.id, "Option A") %}
  {% for item in attributeOptions %}
    {% set itemIndex = loop.index %}
    <h2>{{ item.name }}</h2>
    {% for value in item.values %}
      <label>
        <input type="radio" name="{{ itemIndex }}_option" value="{{ value }}"/>
        <span>{{ value }}</span>
      </label>
    {% endfor %}
  {% endfor %}
</div>
```
