<?php

namespace fostercommerce\variantmanager\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $message
 * @property string|null $dateCreated
 */
class Activity extends ActiveRecord
{
    final public const TABLE_NAME = '{{%variant_manager_activities}}';

    public static function tableName(): string
    {
        return self::TABLE_NAME;
    }
}
