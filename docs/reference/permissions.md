# Permissions reference

| Handle | Description |
|--------|-------------|
| `accessPlugin-variant-manager` | Standard Craft permission. Required to see the **Variant Manager** CP section. |
| `variant-manager:import` | Upload CSVs from the dashboard. Allows creating new products and editing existing ones. Carries a CP warning because the default import behavior deletes variants not listed in the CSV. |
| `variant-manager:export` | Export products from the product edit page sidebar and from the **Export Variant Data** action at **Commerce -> Products**. |
| `variant-manager:manage` | Clear the activity log from the dashboard, and run the **Bulk edit field** action on the Variants index. |
| `variant-manager:manage-attributes` | View and edit variant attributes and their options, and run the **Variant Attributes** utility. |

Set permissions at **Users -> {group} -> Permissions** or **Users -> {user} -> Permissions**.

Admins bypass every check.

See [user-guide/permissions](../user-guide/permissions.md) for who typically gets what.

Field layouts at **Settings -> Plugins -> Variant Manager** require an admin account. `variant-manager:manage-attributes` covers the attribute and option elements, including an attribute's display type, and Craft's own `utility:variant-manager-attributes` permission controls whether the utility is listed.
