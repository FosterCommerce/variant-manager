<?php

/**
 * Variant Manager en Translation
 *
 * Returns an array with the string to be translated (as passed to `Craft::t('variant-manager', '...')`) as
 * the key, and the translation as the value.
 *
 * http://www.yiiframework.com/doc-2.0/guide-tutorial-i18n.html
 *
 * @author    Foster Commerce
 * @package   Variant Manager
 * @since     1.0.0
 */
return [
	'Variant Manager plugin loaded' => 'Variant Manager plugin loaded',
	'Variant Manager' => 'Variant Manager',
	'Export Product' => 'Export Product',
	'Export request failed with status {status}' => 'Export request failed with status {status}',
	'Bulk edit field' => 'Bulk edit field',
	'Field' => 'Field',
	'Value' => 'Value',
	'Update' => 'Update',
	'Variants updated.' => 'Variants updated.',
	'Could not update one or more variants.' => 'Could not update one or more variants.',
	'That field cannot be bulk edited.' => 'That field cannot be bulk edited.',
	'You do not have permission to bulk edit variants.' => 'You do not have permission to bulk edit variants.',

	// Importing
	'import.missingSkuColumn' => 'The CSV has no “sku” column.',

	// Variant attributes
	'attributes.attribute' => 'Variant Attribute',
	'attributes.attributeLower' => 'variant attribute',
	'attributes.attributes' => 'Variant Attributes',
	'attributes.attributesLower' => 'variant attributes',
	'attributes.allAttributes' => 'All attributes',
	'attributes.name' => 'CSV Name',
	'attributes.noAttributes' => 'No attributes yet. They appear here once an import or the backfill utility has run.',
	'attributes.notFound' => 'Attribute not found.',
	'attributes.settingsIntro' => 'Each attribute is a group of options sharing one display and one set of fields, such as paint chips or shirt sizes. Choose how a storefront renders it, and add any fields its options need.',
	'attributes.filterLabel' => 'Variant Attribute: {attribute}',
	'attributes.activityCreated' => 'Created attribute {name}',
	'attributes.deleteInUse' => 'Variants still use this attribute. Remove it from your CSV and reimport, then delete it.',
	'options.activityCreated' => 'Created option {value} under {attribute}',
	'options.deleteInUse' => 'Variants still use this value. Remove it from your CSV and reimport, then delete it.',
	'attributes.displayType' => 'Display Type',
	'attributes.attributeLayout' => 'Attribute Fields',
	'attributes.attributeLayoutInstructions' => 'Fields on this attribute, such as a note covering all of its options.',
	'attributes.optionLayout' => 'Option Fields',
	'attributes.optionLayoutInstructions' => 'Fields on each of this attribute’s options, such as a spec sheet.',
	'settings.availableDisplayTypes' => 'Available Display Types',
	'settings.availableDisplayTypesIntro' => 'Display types an attribute can be set to.',
	'settings.defaultDisplayType' => 'Default Display Type',
	'settings.defaultDisplayTypeIntro' => 'Display type given to an attribute the first time an import or the backfill registers it.',
	'settings.emptyVariantFieldMap' => 'No variant fields are mapped. Set “variantFieldMap” in config/variant-manager.php.',
	'settings.saved' => 'Settings saved.',
	'settings.saveFailed' => 'Couldn’t save settings.',
	'settings.overriddenByConfig' => 'This is being overridden by the {setting} setting in config/variant-manager.php.',
	'attributes.settingsSaved' => 'Attribute settings saved.',
	'attributes.settingsSaveFailed' => 'Couldn’t save attribute settings.',
	'attributes.utilityTitle' => 'Variant Attributes',
	'attributes.backfillTitle' => 'Backfill',
	'attributes.backfillIntro' => 'Reads every variant and registers any attribute name or option value that has no row yet. Runs in the queue and can be run again at any time.',
	'attributes.backfillStart' => 'Start backfill',
	'attributes.backfillQueued' => 'Variant attribute backfill queued.',
	'attributes.pruneTitle' => 'Prune orphans',
	'attributes.pruneIntro' => 'Deletes any attribute or option whose name or value is no longer stored on a variant, along with its fields. Deleting an attribute also removes its display type and field layouts.',
	'attributes.pruneStart' => 'Prune orphans',
	'attributes.pruneConfirm' => 'Delete every attribute and option no longer stored on a variant, along with their fields? This can’t be undone.',
	'attributes.pruneQueued' => 'Variant attribute orphan prune queued.',

	// Jobs
	'jobs.backfillAttributes' => 'Backfilling variant attributes',
	'jobs.pruneAttributeOrphans' => 'Pruning orphaned variant attributes',

	// Variant attribute options
	'options.option' => 'Attribute Option',
	'options.optionLower' => 'attribute option',
	'options.options' => 'Attribute Options',
	'options.optionsLower' => 'attribute options',
	'options.allOptions' => 'All options',
	'options.value' => 'CSV Value',
	'options.attribute' => 'Attribute',
	'options.usedBy' => 'Used by',
	'options.variantCount' => '{count, plural, =0{No variants} =1{1 variant} other{# variants}}',

	// Display types
	'displayTypes.dropdown' => 'Dropdown',
	'displayTypes.radioButtons' => 'Radio buttons',
	'displayTypes.textButtons' => 'Text buttons',
	'displayTypes.imageSwatches' => 'Image swatches',
	'displayTypes.colorSwatches' => 'Color swatches',
	'displayTypes.lightswitch' => 'Lightswitch',

	// Permissions
	'permissions.manageAttributes' => 'Manage variant attributes',
];
