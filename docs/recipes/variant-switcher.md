# Variant switcher

Select a variant based on variant attribute values.

## Sprig

```twig
{% set product = craft.products.id(productId).one() %}
{% set attributeOptions = craft.variantManager.getAttributeOptions(productId) %}

{% set selection={} %}
{% for attribute in attributeOptions %}
  {# Use _context to get a variable from a string #}
  {% set selected = _context['attributeValue_' ~ loop.index] ?? attribute.values|first %}
  {% set selection = selection|merge({(attribute.name): selected}) %}
{% endfor %}

{% set variant = craft.variants().productId(productId).variantAttributes(selection).one() %}

<h1>{{ product.title }}</h1>
{% if variant is not null %}
  <h2>{{ variant.sku }} - {{ variant.price|commerceCurrency }}</h2>
{% endif %}

<div>
  {% for attribute in attributeOptions %}
    <div>
      <label>
        {{ attribute.name }}
        <select sprig name="attributeValue_{{ loop.index }}">
          {% for value in attribute.values %}
            <option value="{{ value }}" {{ selection[attribute.name] == value ? 'selected' }}>{{ value }}</option>
          {% endfor %}
        </select>
      </label>
    </div>
  {% endfor %}
</div>

{% if variant is not null %}
  <form sprig s-method="post" s-action="commerce/cart/update-cart">
    <input type="hidden" name="purchasableId" value="{{ variant.id }}">
    <input type="submit" value="Add to cart">
  </form>
{% else %}
  <p>No matching variant found</p>
{% endif %}

{% if success is defined %}
  {{ success ? flashes.notice : flashes.error }}
{% endif %}
```
