# Writing to the registry from PHP

How a module or companion plugin registers attributes and options, and writes attribute pairs onto variants.

The registry is the set of attribute and option records behind every picker, filter, and storefront listing. For what the registry holds, see [variant attributes](../user-guide/variant-attributes.md).

## Registration on a variant save

Every variant save registers the pairs that variant stores:

```php
$variant->setFieldValue('variantAttributes', [
    ['attributeName' => 'Color', 'attributeValue' => 'Red'],
]);

Craft::$app->getElements()->saveElement($variant);
```

The save creates Red under Color, with the display type from [`defaultDisplayType`](../reference/configuration.md#defaultdisplaytype). A picker or a filter offers the new option with no further call.

Use the calls below to register a value before any variant stores it.

## Example

Register a color and build a variant that uses it.

1. Register the pairs, so the values exist even if the save below fails.
2. Read the field handle from the product type.
3. Set the pairs on the variant and save it through the product.

```php
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;

$plugin = Plugin::getInstance();
$product = Product::find()->slug('classic-tee')->one();

$pairs = [
    ['attributeName' => 'Color', 'attributeValue' => 'Red'],
];

$plugin->getVariantAttributes()->ensureFromAttributePairs($pairs);

$handle = FieldHelper::getFirstVariantAttributesField(
    $product->getType()->getVariantFieldLayout()
)?->handle;

$variant = new Variant();
$variant->sku = 'TEE-RED';
$variant->basePrice = 25.00;
$variant->setFieldValue($handle, $pairs);

$plugin->getCsv()->saveVariants($product, [...$product->getVariants()->all(), $variant]);
```

`saveVariants()` attaches the owner and saves each variant in the order Commerce needs. Commerce then rebuilds the product's default variant from the set you pass, so pass every variant the product should keep, not only the new one. Calling `saveElement()` on each variant does not attach the owner or order the saves.

## The service

```php
use fostercommerce\variantmanager\Plugin;

$variantAttributes = Plugin::getInstance()->getVariantAttributes();
```

### ensureFromAttributePairs

Registers every attribute and option named in a set of pairs, creating the ones that do not exist.

```php
$variantAttributes->ensureFromAttributePairs([
    ['attributeName' => 'Color', 'attributeValue' => 'Red'],
    ['attributeName' => 'Color', 'attributeValue' => 'Blue'],
    ['attributeName' => 'Size', 'attributeValue' => 'Large'],
]);
```

The method ignores a pair that already has a record, and restores a trashed record rather than duplicating it. It skips a pair registered earlier in the same request without querying again.

### ensureAttributes

Registers each named attribute and returns them.

```php
$attributes = $variantAttributes->ensureAttributes(['Color', 'Size']);
```

The attributes are keyed by their normalized name, so `$attributes['color']` is the Color attribute whatever case you passed.

### ensureOptions

```php
$variantAttributes->ensureOptions($attributes['color'], ['Red', 'Blue']);
```

Takes the attribute element, not its name.

### getVariantAttributesFieldHandle

```php
$handle = $variantAttributes->getVariantAttributesFieldHandle();
```

Returns the first Variant Attributes field handle found on any variant field layout, or `null` where no layout has one.

This method is a convenience for a store with a single handle. Where product types use different handles, read the handle from the product type instead:

```php
use fostercommerce\variantmanager\helpers\FieldHelper;

$handle = FieldHelper::getFirstVariantAttributesField(
    $product->getType()->getVariantFieldLayout()
)?->handle;
```

A variant resolves its field layout through its owner, and a `new Variant()` has no owner until it is saved against a product.

## Generating from PHP

Call [Variant Maker](../user-guide/variant-maker.md) from code. Register the values first. Variant Maker combines only values that already have a record.

```php
$variantMaker = Plugin::getInstance()->getVariantMaker();

$settings = $variantMaker->getSettings($product);
$rows = $variantMaker->plan($product, ['Color' => ['Red', 'Blue']], $settings);
```

`plan()` returns what generating would do and does not write to the database, so the selection you pass affects only the preview. `generate($product, $settings)` builds its own selection from `$settings->rows`.

`getSettings()` returns SKU and price included, because Commerce requires both on every variant, and every other property excluded until the product's Variant Maker settings are saved. A `VariantMakerSettings` you construct yourself excludes every property. For a working set of settings, see [the `variant-manager/variant-maker/plan` command](../reference/console-commands.md#variant-managervariant-makerplan).

## Edge cases

The plugin skips an empty or whitespace-only name or value.

An option is unique per attribute by its normalized name, so `Blue` and `blue` are the same option. Two attributes can each have an option with the same name.

A registered value that no variant uses stays until you delete it or the orphan prune removes it. For the prune, see [removing records](../user-guide/variant-attributes.md#removing-records).

The plugin does not delete an attribute or option a variant still stores. Deleting an unused one leaves every variant unchanged, because a variant stores the name and value as strings. See [variant attributes](../user-guide/variant-attributes.md).
