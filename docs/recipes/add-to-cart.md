# Variant switcher

Select a variant based on a it's attributes and add it to the cart.

## Twig

```twig
{% set attributeOptions = craft.variantManager.getAttributeOptions(product.id) %}
{% set selection={} %}
{% for attribute in attributeOptions %}
  {# Find the selected option for the attribute using the kebab case of the attribute name #}
  {% set selected = craft.app.request.getParam(attribute.name|kebab|ascii) ?? attribute.values|first %}
  {% set selection = selection|merge({(attribute.name): selected}) %}
{% endfor %}
{% set variant = craft.variants().productId(product.id).variantAttributes(selection).one() %}

<form method="post">
  {{ csrfInput() }}
  {{ actionInput('commerce/cart/update-cart') }}
  {{ hiddenInput('purchasableId', variant.id) }}

  <div>
      <h1>{{ product.title }}</h1>
      <div>
          <div>{{ variant.onPromotion ? variant.salePriceAsCurrency : variant.priceAsCurrency }}
          {% if variant.onPromotion %}
            <span>
              (was <s aria-label="{{ 'Reduced from {price}'|t('site', { price: variant.priceAsCurrency }) }}" class="block mt-1 text-xs text-gray-500">{{ variant.priceAsCurrency }}</s>)
            </span>
          {% endif %}
          </div>
      </div>

    {# Show variant selections #}
    {% for attribute in attributeOptions %}
      <div>
        <label>
				  {# Use kebab cased attribute names for cleanly formatted querystring parameters #}
          <select class="attribute-select" name="{{ attribute.name|kebab|ascii }}">
            {% for value in attribute.values %}
              <option value="{{ value }}" {{ selection[attribute.name] == value ? 'selected' }}>{{ value }}</option>
            {% endfor %}
          </select>
        </label>
      </div>
    {% endfor %}

    {# Show add to cart button #}
    <button type="submit">
      {{- 'Add to cart'|t('site') -}}
    </button>
  </div>
</form>

{% js %}
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.attribute-select').forEach(function(select) {
			// When a selection changes, update the page location with a query param for that selection
      select.addEventListener('change', attributeSelectionChanged);
    });
  });

  function attributeSelectionChanged(e) {
    let queryParams = new URLSearchParams(window.location.search)
    queryParams.set(e.target.name, e.target.value);
    window.location.search = queryParams.toString()
  }
{% endjs %}
```
