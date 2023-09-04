
## Reference

### Available Variables

There are no stand-alone variables available presently.

### Available Functions

The following functions are available:

#### `craft.variantManager.getVariantsByOptions()`

This is available to retrieve a list of variants based on a restricted set of attributes/options. The following arguments apply:

1. (Required) A product ID as an `int` that identifies the given product.
2. (Required) An array of key-value pair of options as `string`s that identify the options you want to filter by.

##### Example

```
{% set variants = craft.variantManager.getVariantsByOptions(
    6000, 
    [
        ["What tire pressure are your tires running at?", "65PSI"],
        ["What tire pressure are your tires running at?", "60PSI"]
    ],
) %}
```