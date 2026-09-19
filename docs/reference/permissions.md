# Permissions reference

| Handle | Description |
|--------|-------------|
| `accessPlugin-variant-manager` | Standard Craft permission. Required to see the **Variant Manager** CP section. |
| `variant-manager:import` | Upload CSVs from the dashboard. Allows creating new products and editing existing ones. Shows a CP warning because the default import behavior deletes variants not listed in the CSV. |
| `variant-manager:export` | Export products from the product edit page sidebar and from the **Export Variant Data** action at **Commerce -> Products**. |
| `variant-manager:manage` | Clear the activity log from the dashboard, and run the **Bulk edit field** action on the Variants index. |
| `variant-manager:manage-attributes` | View and edit variant attributes and their options, including each attribute's display type, and run the **Variant Attributes** utility. |
| `utility:variant-manager-attributes` | Standard Craft permission. Controls whether the **Variant Attributes** utility is listed under **Utilities**. |

Set permissions at **Users -> {group} -> Permissions** or **Users -> {user} -> Permissions**.

Admins bypass every check.

For who typically gets what, see [choosing what to grant](../user-guide/permissions.md).

The plugin settings screen at **Settings -> Plugins -> Variant Manager** requires an admin account. That covers the field layouts, Available Display Types, Default Display Type, and Variant Maker Product Types. Setting an attribute's display type on the attribute itself does not.
