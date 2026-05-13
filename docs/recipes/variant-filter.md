# Recipe: filter variants based on a selection

Storefront examples that filter a product's variants by attribute, showing the matching variants in a list. Two variations: filter by **all** selected values (AND), or by **any** of the selected values (OR). Each shown using Sprig and Alpine.js.

Replace `variantAttributes` with the handle of your Variant Attributes field.

## Filter by all selected values

Returns only variants that match every attribute the user has chosen.

### Sprig

```twig
{% set product = craft.products.id(productId).one() %}
{% set selection = {} %}

<h1>{{ product.title }}</h1>
<div>
  {% for attribute in craft.variantManager.getAttributeOptions(productId) %}
    {% set selected = _context['attributeValue_' ~ loop.index] ?? (selection[attribute.name] ?? null) %}

    {% if selected is not null and selected != '' %}
      {% set selection = selection|merge({(attribute.name): selected}) %}
    {% endif %}
    <div>
      <label>
        {{ attribute.name }} - {{ selected }}
        <select sprig name="attributeValue_{{ loop.index }}">
          <option value="" {{ selected is null or selected == '' ? 'selected' }}>Select an option</option>
          {% for value in attribute.values %}
            <option value="{{ value }}" {{ selected == value ? 'selected' }}>{{ value }}</option>
          {% endfor %}
        </select>
      </label>
    </div>
  {% endfor %}
</div>

<ul>
  {% for variant in craft.variants().productId(productId).variantAttributes(selection).all() %}
    <li>{{ variant.sku }} - {{ variant.price|commerceCurrency }}</li>
  {% endfor %}
</ul>
```

### Alpine.js

```twig
{% set product = craft.products.id(productId).one() %}
{% set selection = {} %}

<h1>{{ product.title }}</h1>
<div>
  {% for attribute in craft.variantManager.getAttributeOptions(productId) %}
    {% set selected = craft.app.request.getParam('attributeValue_' ~ loop.index) ?? (selection[attribute.name] ?? null) %}

    {% if selected is not null and selected != '' %}
      {% set selection = selection|merge({(attribute.name): selected}) %}
    {% endif %}
    <div>
      <label>
        {{ attribute.name }} - {{ selected }}
        <select name="attributeValue_{{ loop.index }}" @change="attributeChanged">
          <option value="" {{ selected is null or selected == '' ? 'selected' }}>Select an option</option>
          {% for value in attribute.values %}
            <option value="{{ value }}" {{ selected == value ? 'selected' }}>{{ value }}</option>
          {% endfor %}
        </select>
      </label>
    </div>
  {% endfor %}
</div>

<ul>
  {% for variant in craft.variants().productId(productId).variantAttributes(selection).all() %}
    <li>{{ variant.sku }} - {{ variant.price|commerceCurrency }}</li>
  {% endfor %}
</ul>

{% js %}
  function attributeChanged(event) {
    const queryParams = new URLSearchParams(window.location.search);
    queryParams.set(event.target.name, event.target.value);
    window.location.search = queryParams.toString();
  }
{% endjs %}
```

## Filter by any selected value

The selection is built as a list of single-pair objects, which the field treats as an OR filter.

### Sprig

```twig
{% set product = craft.products.id(productId).one() %}
{% set selection = [] %}

<h1>{{ product.title }}</h1>
<div>
  {% for attribute in craft.variantManager.getAttributeOptions(productId) %}
    {% set selected = _context['attributeValue_' ~ loop.index] ?? null %}

    {% if selected is not null and selected != '' %}
      {% set selection = selection|merge([{(attribute.name): selected}]) %}
    {% endif %}
    <div>
      <label>
        {{ attribute.name }} - {{ selected }}
        <select sprig name="attributeValue_{{ loop.index }}">
          <option value="" {{ selected is null or selected == '' ? 'selected' }}>Select an option</option>
          {% for value in attribute.values %}
            <option value="{{ value }}" {{ selected == value ? 'selected' }}>{{ value }}</option>
          {% endfor %}
        </select>
      </label>
    </div>
  {% endfor %}
</div>

<ul>
  {% for variant in craft.variants().productId(productId).variantAttributes(selection).all() %}
    <li>{{ variant.sku }} - {{ variant.price|commerceCurrency }}</li>
  {% endfor %}
</ul>
```

### Alpine.js

```twig
{% set product = craft.products.id(productId).one() %}
{% set selection = [] %}

<h1>{{ product.title }}</h1>
<div>
  {% for attribute in craft.variantManager.getAttributeOptions(productId) %}
    {% set selected = craft.app.request.getParam('attributeValue_' ~ loop.index) ?? null %}

    {% if selected is not null and selected != '' %}
      {% set selection = selection|merge([{(attribute.name): selected}]) %}
    {% endif %}
    <div>
      <label>
        {{ attribute.name }} - {{ selected }}
        <select name="attributeValue_{{ loop.index }}" @change="attributeChanged">
          <option value="" {{ selected is null or selected == '' ? 'selected' }}>Select an option</option>
          {% for value in attribute.values %}
            <option value="{{ value }}" {{ selected == value ? 'selected' }}>{{ value }}</option>
          {% endfor %}
        </select>
      </label>
    </div>
  {% endfor %}
</div>

<ul>
  {% for variant in craft.variants().productId(productId).variantAttributes(selection).all() %}
    <li>{{ variant.sku }} - {{ variant.price|commerceCurrency }}</li>
  {% endfor %}
</ul>

{% js %}
  function attributeChanged(event) {
    const queryParams = new URLSearchParams(window.location.search);
    queryParams.set(event.target.name, event.target.value);
    window.location.search = queryParams.toString();
  }
{% endjs %}
```

## Related

- [Template tags](../dev-guide/template-tags.md), the `getAttributeOptions` helper.
- [Querying variants](../dev-guide/twig-queries.md), filter shapes accepted by `variantAttributes()`.
- [Add to cart recipe](./add-to-cart.md), selecting one variant and posting to the cart.
