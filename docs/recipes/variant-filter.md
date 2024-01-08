# Variant filter

Filter variants based on a selection

## Filter by all selected values

### Sprig

```twig
{% set product = craft.products.id(productId).one() %}
{% set variantId = variantId ?? null %}
{% set selection={} %}

<h1>{{ product.title }}</h1>
<div>
  {% for attribute in craft.variantManager.getAttributeOptions(productId) %}
    {# Use _context to get a variable from a string #}
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
    <li>
      {{ variant.sku }} - {{ variant.price|commerceCurrency }}
    </li>
  {% endfor %}
</ul>
```

### Alpine.js

```twig
{% set product = craft.products.id(productId).one() %}

{% set attributeOptions = craft.variantManager.getAttributeOptions(productId) %}

{% set selection={} %}
{% for attribute in attributeOptions %}
  {% set selected = craft.app.request.getParam('attributeValue_' ~ loop.index) ?? attribute.values|first %}
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
        <select name="attributeValue_{{ loop.index }}" @change="attributeChanged">
          {% for value in attribute.values %}
            <option value="{{ value }}" {{ selection[attribute.name] == value ? 'selected' }}>{{ value }}</option>
          {% endfor %}
        </select>
      </label>
    </div>
  {% endfor %}
</div>


{% if variant is not null %}
  <form method="post" s-action="commerce/cart/update-cart">
    <input type="hidden" name="purchasableId" value="{{ variant.id }}">
    <input type="submit" value="Add to cart">
  </form>
{% else %}
  <p>No matching variant found</p>
{% endif %}

{% if success is defined %}
  {{ success ? flashes.notice : flashes.error }}
{% endif %}

{% endblock %}

{% js %}
  function attributeChanged(e) {
    let queryParams = new URLSearchParams(window.location.search)

    queryParams.set(e.target.name, e.target.value);
    window.location.search = queryParams.toString()
  }
{% endjs %}
```

## Filter by any of the selected values

**Note** that the difference between the previous section and this section is that the selection is an array of objects instead of an object.

### Sprig

```twig
{% set product = craft.products.id(productId).one() %}
{% set selection=[] %}

<h1>{{ product.title }}</h1>
<div>
  {% for attribute in craft.variantManager.getAttributeOptions(productId) %}
    {% set selected = _context['attributeValue_' ~ loop.index] ?? (selection[attribute.name] ?? null) %}

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
    <li>
      {{ variant.sku }} - {{ variant.price|commerceCurrency }}
    </li>
  {% endfor %}
</ul>
```

### Alpine.js

```twig
{% set product = craft.products.id(productId).one() %}
{% set selection=[] %}

<h1>{{ product.title }}</h1>
<div>
  {% for attribute in craft.variantManager.getAttributeOptions(productId) %}
    {% set selected = craft.app.request.getParam('attributeValue_' ~ loop.index) ?? (selection[attribute.name] ?? null) %}

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
    <li>
      {{ variant.sku }} - {{ variant.price|commerceCurrency }}
    </li>
  {% endfor %}
</ul>
{% endblock %}

{% js %}
  function attributeChanged(e) {
    let queryParams = new URLSearchParams(window.location.search)

    queryParams.set(e.target.name, e.target.value);
    window.location.search = queryParams.toString()
  }
{% endjs %}
```
