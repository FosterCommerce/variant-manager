<?php

namespace fostercommerce\variantmanager\migrations;

use craft\db\Migration;
use fostercommerce\variantmanager\records\Activity;

/**
 * m260926_022417_widen_activity_message migration.
 */
class m260926_022417_widen_activity_message extends Migration
{
	public function safeUp(): bool
	{
		// Match Install, because Activity::log() throws on a message over 255 characters
		$this->alterColumn(Activity::TABLE_NAME, 'message', $this->text()->notNull());

		return true;
	}
}
