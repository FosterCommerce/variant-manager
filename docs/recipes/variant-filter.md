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
  {# 
    NOTE: The "variantAttributes" below in this example should be the handle of the
    Variant Attributes field you created and have added to your variant fields layout
  #}
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
{% set variantId = variantId ?? null %}
{% set selection={} %}

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
  {# 
    NOTE: The "variantAttributes" below in this example should be the handle of the
    Variant Attributes field you created and have added to your variant fields layout
  #}
  {% for variant in craft.variants().productId(productId).variantAttributes(selection).all() %}
    <li>
      {{ variant.sku }} - {{ variant.price|commerceCurrency }}
    </li>
  {% endfor %}
</ul>

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
  {# 
    NOTE: The "variantAttributes" below in this example should be the handle of the
    Variant Attributes field you created and have added to your variant fields layout
  #}
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
  {# 
    NOTE: The "variantAttributes" below in this example should be the handle of the
    Variant Attributes field you created and have added to your variant fields layout
  #}
  {% for variant in craft.variants().productId(productId).variantAttributes(selection).all() %}
    <li>
      {{ variant.sku }} - {{ variant.price|commerceCurrency }}
    </li>
  {% endfor %}
</ul>
{% js %}
  function attributeChanged(e) {
    let queryParams = new URLSearchParams(window.location.search)

    queryParams.set(e.target.name, e.target.value);
    window.location.search = queryParams.toString()
  }
{% endjs %}
```
