<?php

namespace fostercommerce\variantmanager\migrations;

use craft\db\Migration;
use fostercommerce\variantmanager\records\Activity;

/**
 * m240222_084504_add_activity_error_col migration.
 */
class m240222_084504_add_activity_error_col extends Migration
{
	public function safeUp(): bool
	{
		// Place migration code here...
		$this->addColumn(Activity::TABLE_NAME, 'type', $this->string()->after('message'));
		$this->update(Activity::TABLE_NAME, [
			'type' => 'success',
		], '', [], false);
		$this->alterColumn(Activity::TABLE_NAME, 'type', $this->string()->after('message')->notNull());

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropColumn(Activity::TABLE_NAME, 'type');

		return true;
	}
}
