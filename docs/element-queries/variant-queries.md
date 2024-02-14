# Querying Variants

If a `VariantAttributesField` is attached to a product types variants, then it is possible to use that field to filter
variants by the attributes and options stored in that field.

The Variant Attributes field accepts 3 formats of filters:

- Filter by an option value, regardless of attribute;
- Filter _all_ provided attribute/option pairs;
- And, filter by any of the provided attribute/option pairs.

## Filter by an option value

The following will return all variants which have the option `'Value'`:

### PHP

```php
\craft\commerce\elements\Variant::find()->myVariantAttributes('Value')->all();
```

### Twig

```twig
{% craft.variants().myVariantAttributes("Value").all() %}
```

## Filter by all attribute/option pairs

The following will return all variants which have an attribute of `Attribute A` with a value of `Value A1`, _and_ an
attribute of `Attribute B` with a value of `Value B1`.

#### PHP

```php
\craft\commerce\elements\Variant::find()->myVariantAttributes([
  'Attribute A' => 'Value A1',
  'Attribute B' => 'Value B1',
])->all();
```

#### Twig

```twig
{% set filter = {
  'Attribute A': 'Value A1',
  'Attribute B': 'Value B1',
} %}
{% set variants = craft.variants().myVariantAttributes(filter).all() %}
```

## Filter by any attribute/option pairs

The following will return all variants which have an attribute of `Attribute A` with a value of `Value A1`, _or_ any
option value of `Value`, _or_ an attribute of `Attribute B` with a value of `Value B1`.

#### PHP

```php
\craft\commerce\elements\Variant::find()->myVariantAttributes([
  ['Attribute A' => 'Value A1'],
  'Value',
  ['Attribute B' => 'Value B1'],
])->all();
```

#### Twig

```twig
{% set filter = {
  {'Attribute A': 'Value A1'},
  'Value',
  {'Attribute B': 'Value B1'},
} %}
{% set variants = craft.variants().myVariantAttributes(filter).all() %}
```
