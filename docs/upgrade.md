# Upgrading to 3.x

Existing templates keep working. This page is the setup the update needs, plus one change to the Variant Attributes field.

## Editing in the Variant Attributes field moved

The field showed an editable box per value with a **Save Attributes** button. Both are gone, along with the `variant-manager/product-variants/save-variant-attributes` action and its route.

Names and values are now chips. Click one to open the attribute or option in a slideout and edit it there, including any custom fields you have added.

Editing there is global: renaming an option changes what every product using that value shows, with no import and no change to any variant. To change the value the CSV writes, edit the CSV and reimport.

## After updating

Run migrations and project config changes:

```sh
./craft up
```

Then run the backfill once:

```sh
./craft variant-manager/attributes/backfill
```

It reads every variant and registers the names and values already stored on them. Safe to re-run.

On a site that imports CSVs this is a catch-up for data that predates 3.x. On a site that does not import, it is the only thing that populates the registry, so it is the setup step.

## Grant the new permission

Existing user groups do not have `variant-manager:manage-attributes`. Grant it at **Users -> {group} -> Permissions** to anyone who should see the **Variant Attributes** and **Attribute Options** sections or run the utility. See [permissions](./reference/permissions.md).

## Set field layouts in development

An attribute's two field layouts are project config. Set them in your development environment and deploy them. The screen is read-only where `allowAdminChanges` is off, so they cannot be set in production directly.
