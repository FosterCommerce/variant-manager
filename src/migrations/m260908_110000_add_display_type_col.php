<?php

namespace fostercommerce\variantmanager\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\Db;
use fostercommerce\variantmanager\db\Table;

class m260908_110000_add_display_type_col extends Migration
{
	// The path these settings used before the field set migration moved them
	private const CONFIG_PATH = 'variant-manager.attributes';

	public function safeUp(): bool
	{
		$this->addColumn(
			Table::ATTRIBUTES,
			'displayType',
			$this->string()->notNull()->defaultValue('dropdown')->after('nameKey')
		);

		// Display types were project config before this release
		$configs = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH);

		if (! is_array($configs)) {
			return true;
		}

		foreach ($configs as $nameKey => $config) {
			$displayType = $config['displayType'] ?? null;

			if (is_string($displayType)) {
				Db::update(Table::ATTRIBUTES, [
					'displayType' => $displayType,
				], [
					'nameKey' => $nameKey,
				]);
			}
		}

		return true;
	}
}
