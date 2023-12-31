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
    final public const ACTIVITY_LIMIT = 50;

    final public const TABLE_NAME = '{{%activities}}';

    public static function tableName(): string
    {
        return self::TABLE_NAME;
    }
}
