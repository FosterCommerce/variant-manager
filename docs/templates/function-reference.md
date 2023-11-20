
## Reference

### Available Functions

The following functions are available:

### `craft.variantManager.getAttributeOptions(product)`

Returns an array of associative arrays holding an attribute name and an array of possible values for that option.

```twig
{% set attributeOptions = craft.variantManager.getAttributeOptions(7200) %}
{{ attributeOptions | json_encode }}
```

Will output 

```
[
  [
    "name": "Option A",
    "values": [
      "Value 1",
      "Value 2",
      "Value 3"
    ]
  ],
  [
    "name": "Option B",
    "values": [
      "Value 1",
    ]
  ],
  [
    "name": "Option C",
    "values": [
      "Value 1",
      "Value 2",
      "Value 3"
      "Value 4"
    ]
  ],
]
```
