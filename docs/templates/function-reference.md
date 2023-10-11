
## Reference

### Available Variables

There are no stand-alone variables available presently.

### Available Functions

The following functions are available:

### `craft.variantManager.getAttributeOptions(product, handle)`

Returns an object with keys representing available attribute names for a product. Each keys value holds an array of possible values.

```twig
{% set attributeOptions = craft.variantManager.getAttributeOptions(7200, 'variantAttributes') %}
{{ attributesOptions | json_encode }}
```

Will output 

```
{
  "What tire pressure are your tires running at?": [
    "60PSI",
    "65PSI"
  ],
  "How many Cat’s Eye gauges to do need?": [
    "2 UNIT PACK"
  ],
  "What is your tire size?": [
    "22.5\" & 24.5\"",
    "17.5\"",
    "19.5\""
  ],
  "What type of hose material would you like?": [
    "RUBBER HOSE"
  ],
  "What is your wheel type?": [
    "DUAL TIRES",
    "SINGLE TIRES (ONLY 1 HOSE ON CAT'S EYE)"
  ]
}
```
