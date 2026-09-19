# Variant Manager documentation

A Craft CMS plugin for managing Craft Commerce variants as combinations of **attributes and options**.

## Install and upgrade

[Installation](./installation.md). Coming from 2.x or 3.x? See [upgrading](./upgrade.md).

## Where to go

**Getting started:** [a walkthrough](./getting-started.md) from install to your first import.

**User guide:**

- [Variant attributes](./user-guide/variant-attributes.md), the field every other feature works through, and the swatches and custom fields you can attach to it.
- [Importing](./user-guide/importing.md), creating and updating products and variants from a spreadsheet.
- [Variant Maker](./user-guide/variant-maker.md), generating variants in the control panel from attributes and options you pick.
- [Exporting](./user-guide/exporting.md), getting variants out as a CSV you can edit and reimport.
- [Variants index](./user-guide/variants-index.md), one listing of every variant, with filters and bulk edit.
- [Troubleshooting](./user-guide/troubleshooting.md), when an import does not behave the way you expected.

**Dev guide:**

- [Template tags](./dev-guide/template-tags.md), reading attributes and their metadata in Twig.
- [Querying variants](./dev-guide/twig-queries.md), the three filter shapes for finding variants by attribute.
- [Custom queue](./dev-guide/custom-queue.md), running imports on a dedicated queue.

**Reference:**

- [Configuration](./reference/configuration.md), every config key with its default.
- [Console commands](./reference/console-commands.md), every command, argument, and flag.
- [Variant Attributes field](./reference/field-type.md), what the field stores and how to query it.
- [Permissions](./reference/permissions.md), every handle and what it gates.

**Recipes:**

- [Add to cart](./recipes/add-to-cart.md), a variant picker that posts to the cart.
- [Variant filter](./recipes/variant-filter.md), filtering a product's variants on the storefront.
- [Field maps for many product types](./recipes/field-maps-for-many-product-types.md), organizing `productFieldMap` on a large catalog.

**Roadmap:** [ideas under consideration](./roadmap.md), not commitments.
