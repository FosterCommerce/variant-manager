# Permissions reference

| Handle | Description |
|--------|-------------|
| `accessPlugin-variant-manager` | Standard Craft permission. Required to see the **Variant Manager** CP section. |
| `variant-manager:manage` | Clear the activity log from the dashboard. |
| `variant-manager:export` | Export products from the product edit page sidebar and from the **Export Variant Data** action at **Commerce -> Products**. |
| `commerce-saveProductType:{uid}` | Commerce's own permission. Everything that changes catalog data: uploading CSVs, Variant Maker, **Bulk edit field**, the **Variant Attributes** section, creating and editing attributes and options, the prune, and the backfill. Variant Maker and each uploaded CSV check the product type being written. The rest accept any product type the user can save. |
| `utility:variant-manager-attributes` | Standard Craft permission. Controls whether the **Variant Attributes** utility is listed under **Utilities**. |
| An admin account | The plugin settings screen at **Variant Manager -> Settings**: field sets and their field layouts, the field set assigned to each attribute, Available Display Types, Default Display Type, and Variant Maker Product Types. An attribute's own display type is not covered. Of these, only the field set assignment can be changed where `allowAdminChanges` is off. |

Set permissions at **Users -> {group} -> Permissions** or **Users -> {user} -> Permissions**.

Admins bypass every check.

For who typically gets what, see [choosing what to grant](../user-guide/permissions.md).

