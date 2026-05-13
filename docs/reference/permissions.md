# Permissions reference

| Handle | Description |
|--------|-------------|
| `accessPlugin-variant-manager` | Standard Craft permission. Required to see the **Variant Manager** CP section. |
| `variant-manager:import` | Upload CSVs from the dashboard. Allows creating new products and editing existing ones. Carries a CP warning because the default import behaviour deletes variants not listed in the CSV. |
| `variant-manager:export` | Export products from the product edit page sidebar and from the **Export Variant Data** element action on the Variants index. |
| `variant-manager:manage` | Clear the activity log from the dashboard. |

Set permissions at **Users -> {group} -> Permissions** or **Users -> {user} -> Permissions**.

Admins bypass every check.

See [user-guide/permissions](../user-guide/permissions.md) for who typically gets what.
