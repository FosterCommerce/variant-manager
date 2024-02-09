# Variant Manager

A plugin for managing product variants.

## Requirements

This plugin requires at least Craft CMS 4.4.6, Craft Commerce 4.2, and PHP 8.1.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Variant Manager”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require fostercommerce/variant-manager

# tell Craft to install the plugin
./craft plugin/install variant-manager
```

#### With DDEV

Run the following command from DDEV:

```bash
ddev composer require fostercommerce/variant-manager -w && ddev exec php craft plugin/install variant-manager
```
