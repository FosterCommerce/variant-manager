# Getting started

This walks you from `composer require` to a Commerce product whose variants each store their attributes as name and value pairs, built from a spreadsheet. By the end you will also know how to export them, edit the spreadsheet, and reimport.

To build a product's variants in the control panel instead, see [Variant Maker](./user-guide/variant-maker.md).

## 1. Install

```sh
composer require fostercommerce/variant-manager
./craft plugin/install variant-manager
```

In the CP you see a **Variant Manager** nav item. As an admin you get all three entries: **Dashboard**, **Variants**, and **Variant Attributes**.

## 2. Configure

The default `productFieldMap` and `variantFieldMap` already cover every column this walkthrough uses, so there is no column to add. Create `config/variant-manager.php` when you want to map a custom field or rename a column. For every key, see the [configuration reference](./reference/configuration.md).

## 3. Add the Variant Attributes field

This is the field the rest of the plugin works through. It stores each variant's attributes as name and value pairs (Color: Red, Size: Small). Create a **Variant Attributes** field with the handle `variantAttributes` and add it to your product type's variant field layout. For the steps, see [installation](./installation.md#add-the-variant-attributes-field).

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

`Demo Shirt.csv` has no product ID at the start, so the import creates a new product. Its title comes from the first cell of row 2, not from the filename; rows 3-6 are the four variants.

`basePrice[default]` is per-site; replace `default` with your site's handle if it is different. For the rest of the columns, see [CSV format](./user-guide/csv-format.md).

## 5. Upload

**Variant Manager -> Dashboard -> Upload Product**. Pick `Demo Shirt.csv`.

The modal opens with "Are you sure you want to create a new product?" and a **Product Type** dropdown. Pick the product type you added the Variant Attributes field to. Click **Create Product**.

You see "File Demo Shirt.csv has been queued for processing" and the page refreshes. Once the queue runs the import job, the dashboard's activity log shows a green-dot row: "Imported new product Demo Shirt into {your product type}."

If your queue is not running automatically, run `./craft queue/run`.

## 6. Verify in Commerce

Click the product link in the activity log row. The product opens at **Commerce -> Products -> Demo Shirt**.

Check the variants tab:

- Four variants: DEMO-RED-S, DEMO-RED-M, DEMO-BLUE-S, DEMO-BLUE-M.
- Each has its price set to 19.99 and inventory tracking on.
- Open one variant. Scroll to the Variant Attributes field to see Color and Size with the value for that variant.

## 7. See the attributes the import registered

**Variant Manager -> Variant Attributes**. The import created `Color` and `Size`, each with its options nested under it: `Red` and `Blue` under `Color`, `Small` and `Medium` under `Size`.

Open `Red`. Its **System Name** is read-only; its **Display Name** is not. The **Used by** count shows how many variants store `Red`. Rename the display name to `Crimson` and save. No variant changed, and a template reading `option.title` now shows `Crimson`.

See [variant attributes](./user-guide/variant-attributes.md).

## 8. Round-trip: export, edit, reimport

Use this to change many variants at once.

1. On the product edit page, click **Export Product** in the sidebar. A file called `{id}__demo-shirt.csv` downloads.
2. Open it in your spreadsheet app. You see the row layout the import produced, with every Commerce column the plugin can write to.
3. Change a value. Bump the price on DEMO-RED-S to 21.99.
4. Save the file. **Do not rename it.**
5. Back to **Variant Manager -> Dashboard -> Upload Product** and pick the same file.
6. The modal reads the ID prefix and asks "Are you sure you want to edit an existing product named \"Demo Shirt\"?" with two variant-handling radios. Leave the default, **Update & remove extra variants**, and click **Edit Product**.
7. Activity log shows another green-dot row. Reopen the product; DEMO-RED-S is now 21.99.

## Where to go next

- [Variant Maker](./user-guide/variant-maker.md), building a product's variants from attributes and options, without a CSV.
- [CSV format](./user-guide/csv-format.md), every column the import reads, with formatting rules.
- [Importing](./user-guide/importing.md), the upload steps in detail, including the choice between updating and replacing variants.
- [Exporting](./user-guide/exporting.md), single-product and bulk export.
- [Bulk import](./user-guide/bulk-import.md), uploading a zip of CSVs.
- [Troubleshooting](./user-guide/troubleshooting.md), when imports fail.
- [Variant Attributes field](./reference/field-type.md), how the attribute data is stored and read.
- [Variant attributes](./user-guide/variant-attributes.md), attaching swatches and notes to attribute values.
- [Template tags](./dev-guide/template-tags.md) and [the add-to-cart recipe](./recipes/add-to-cart.md), using the attributes on the storefront.
- [Upgrading](./upgrade.md), if you are coming from 2.x or 3.x.
