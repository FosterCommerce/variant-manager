# Exporting Products and Variants from Craft Commerce

If you need to update a product's variant data already in Commerce, an easy way to do this is to export the data with
Variant Manager as a CSV file, open it in your spreadsheet program to make any edits and then
[import it with Variant Manager](importing.md) again back into Commerce.

To export a product's variant data and attributes, go to the product's entry form in Commerce and in the right-hand
column on the bottom there is an "Export Product" button.

![Screenshot](../../resources/img/export-product.png)

Click it, and Variant Manager will generate a CSV file of the current product's variant data and it will download
automatically to your computer.

The exported CSV includes columns for every entry in `productFieldMap` and `variantFieldMap`. If `productFieldMap` includes a `status` entry, the export writes `enabled` or `disabled` based on the current product status. See [Configuration](../getting-started/configuration.md) for the full column list.
