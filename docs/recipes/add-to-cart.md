# Recipe: select a variant and add to cart

A full storefront example: a product page with a select element for every attribute (Color, Size, Material), where changing a selection picks the matching variant and the form adds that variant to the cart on submit.

Replace `variantAttributes` with the handle of your Variant Attributes field.

## Twig

```twig
{% set attributeOptions = craft.variantManager.getAttributeOptions(product.id) %}
{% set selection = {} %}
{% for attribute in attributeOptions %}
  {# Find the selected option for the attribute using a kebab-case query param #}
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
      {{ variant.onPromotion ? variant.salePriceAsCurrency : variant.priceAsCurrency }}
      {% if variant.onPromotion %}
        <span>
          (was <s aria-label="{{ 'Reduced from {price}'|t('site', { price: variant.priceAsCurrency }) }}">{{ variant.priceAsCurrency }}</s>)
        </span>
      {% endif %}
    </div>

    {% for attribute in attributeOptions %}
      <div>
        <label>
          {{ attribute.name }}
          <select class="attribute-select" name="{{ attribute.name|kebab|ascii }}">
            {% for value in attribute.values %}
              <option value="{{ value }}" {{ selection[attribute.name] == value ? 'selected' }}>{{ value }}</option>
            {% endfor %}
          </select>
        </label>
      </div>
    {% endfor %}

    <button type="submit">{{ 'Add to cart'|t('site') }}</button>
  </div>
</form>

{% js %}
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.attribute-select').forEach(function (select) {
      select.addEventListener('change', attributeSelectionChanged);
    });
  });

  function attributeSelectionChanged(event) {
    const queryParams = new URLSearchParams(window.location.search);
    queryParams.set(event.target.name, event.target.value);
    window.location.search = queryParams.toString();
  }
{% endjs %}
```

## How it works

1. `getAttributeOptions` pulls the distinct attribute names and their possible values across the product's variants.
2. For each attribute, the selected value comes from a query parameter (kebab-cased name) or falls back to the first value.
3. `craft.variants().productId(product.id).variantAttributes(selection).one()` finds the single variant that matches every selection.
4. The form posts `purchasableId` to `commerce/cart/update-cart`, adding the matched variant to the cart.
5. Changing a `<select>` updates the URL's query string; the page reloads with the new selection in the URL, and the Twig at the top picks up the new value.

## Related

- [Template tags](../dev-guide/template-tags.md), the `getAttributeOptions` helper.
- [Querying variants](../dev-guide/twig-queries.md), filter shapes accepted by `variantAttributes()`.
- [Variant filter recipe](./variant-filter.md), filtering instead of selecting.
