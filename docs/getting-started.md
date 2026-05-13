# Getting started

This walks you from `composer require` to your first successful CSV import. By the end you will have a Commerce product whose variants came in from a spreadsheet, and you will know the round-trip flow for editing it.

## 1. Install

```sh
composer require fostercommerce/variant-manager
./craft plugin/install variant-manager
```

In the CP you should see a **Variant Manager** nav item with two subnav entries: **Dashboard** and **Variants**.

## 2. Configure

Create `config/variant-manager.php`:

```php
<?php

return [
    'emptyAttributeValue' => '',
    'attributePrefix' => 'Attribute: ',
    'inventoryPrefix' => 'Inventory',
    'activityLogRetention' => '30 days',
    'productFieldMap' => [
        '*' => [
            'title' => 'title',
            'slug' => 'slug',
            'status' => 'status',
        ],
    ],
    'variantFieldMap' => [
        '*' => [
            'title' => 'title',
            'sku' => 'sku',
            'inventoryTracked' => 'inventoryTracked',
            'price' => 'basePrice',
            'height' => 'height',
            'width' => 'width',
            'length' => 'length',
            'weight' => 'weight',
        ],
    ],
];
```

All keys have defaults; you can skip the file entirely and revisit it once you know which fields you want to import.

## 3. Add the Variant Attributes field

The plugin needs a place to store option name and value pairs (Color: Red, Size: Small) on each variant.

1. **Settings -> Fields -> New field**.
2. **Field Type**: **Variant Attributes**. Name it **Variant Attributes**, handle `variantAttributes`. No further settings needed.
3. **Commerce -> Settings -> Product Types -> {your product type} -> Variant Fields**. Drag the new field onto the layout. Save.

You only need one Variant Attributes field per product type's variant layout. Extras are ignored.

## 4. Build your first CSV

Create a file called `Demo Shirt.csv` with this content:

```csv
title,sku,basePrice[default],inventoryTracked[default],Attribute: Color,Attribute: Size
Demo Shirt,,,,,
,DEMO-RED-S,19.99,1,Red,Small
,DEMO-RED-M,19.99,1,Red,Medium
,DEMO-BLUE-S,19.99,1,Blue,Small
,DEMO-BLUE-M,19.99,1,Blue,Medium
```

The filename matters: `Demo Shirt.csv` tells Variant Manager you want to create a new product titled `Demo Shirt`. Row 2's first cell repeats the title; rows 3-6 are the four variants.

`basePrice[default]` is per-site; replace `default` with your site's handle if it is different. See [CSV format](./user-guide/csv-format.md) for the rest of the columns.

## 5. Upload

**Variant Manager -> Dashboard -> Upload Product**. Pick `Demo Shirt.csv`.

The modal opens with "Are you sure you want to create a new product?" and a **Product Type** dropdown. Pick the product type you added the Variant Attributes field to. Click **Create Product**.

You will see "File Demo Shirt.csv has been queued for processing" and the page refreshes. Once the queue runs the import job, the dashboard's activity log shows a green-dot row: "Imported new product Demo Shirt into {your product type}."

If your queue is not running automatically, run `./craft queue/run`.

## 6. Verify in Commerce

Click the product link in the activity log row. The product opens at **Commerce -> Products -> Demo Shirt**.

Check the variants tab:

- Four variants: DEMO-RED-S, DEMO-RED-M, DEMO-BLUE-S, DEMO-BLUE-M.
- Each has its price set to 19.99 and inventory tracking on.
- Open one variant. Scroll to the Variant Attributes field; you should see Color and Size with the value for that variant.

## 7. Round-trip: export, edit, reimport

This is the workflow you will use to bulk-edit existing products.

1. On the product edit page, click **Export Product** in the sidebar. A file called `{id}__demo-shirt.csv` downloads.
2. Open it in your spreadsheet app. You will see the row layout the import produced, with every Commerce column the plugin can write to.
3. Change something. Bump the price on DEMO-RED-S to 21.99.
4. Save the file. **Do not rename it.**
5. Back to **Variant Manager -> Dashboard -> Upload Product** and pick the same file.
6. The modal recognises the existing product and asks "Are you sure you want to edit an existing product named \"Demo Shirt\"?" with a **Refresh variants** radio group. Leave it on **Update and remove extra variants** (the default) and click **Edit Product**.
7. Activity log shows another green-dot row. Reopen the product; DEMO-RED-S is now 21.99.

## 8. Where to go next

For deeper reading:

- [CSV format](./user-guide/csv-format.md), every column the import recognises, with formatting rules.
- [Importing](./user-guide/importing.md), the upload flow in detail, including the choice between updating and replacing variants.
- [Exporting](./user-guide/exporting.md), single-product and bulk export.
- [Bulk import](./user-guide/bulk-import.md), uploading a zip of CSVs.
- [Troubleshooting](./user-guide/troubleshooting.md), when imports fail.
- [Variant Attributes field](./reference/field-type.md), how the attribute data is stored and read.
- [Template tags](./dev-guide/template-tags.md) and [recipes](./recipes/add-to-cart.md), using the attributes on the storefront.
