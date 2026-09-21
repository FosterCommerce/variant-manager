# Permissions

Which user groups and users can do what in Variant Manager. Set permissions at **Users -> {group} -> Permissions** or **Users -> {user} -> Permissions**.

For the full list, see [permissions reference](../reference/permissions.md).

## Choosing what to grant

- **Read-only**: `accessPlugin-variant-manager` alone. The dashboard and the activity log are visible, with no upload, export, or edit.
- **Reporting**: read-only, plus `variant-manager:export` to take data out without changing it.
- **Product team**: read-only, plus `variant-manager:export`, plus Commerce's `commerce-saveProductType` for each product type they work on. `commerce-saveProductType` covers importing, the Variant Maker, and creating and editing attributes and options.
- **Operations**: any of the above, plus `variant-manager:manage` to clear the activity log.

## Before you grant catalog access

A `commerce-saveProductType` holder can import. The default existing-product import deletes any variant whose SKU is not in the CSV. For what each variant-handling choice does, see [importing](./importing.md#existing-product-update-options).
