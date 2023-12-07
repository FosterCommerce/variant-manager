<?php

namespace fostercommerce\variantmanager\migrations;

use craft\db\Migration;
use fostercommerce\variantmanager\records\Activity;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Activity::TABLE_NAME, [
            'id' => $this->primaryKey(),
            'message' => $this->string()->notNull(),
            'userId' => $this->integer()->notNull(),
            'username' => $this->string()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(null, Activity::TABLE_NAME, ['dateCreated'], false);

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(Activity::TABLE_NAME)) {
            $this->dropIndexIfExists(Activity::TABLE_NAME, ['dateCreated'], false);
            $this->dropTable(Activity::TABLE_NAME);
        }

        return true;
    }
}
