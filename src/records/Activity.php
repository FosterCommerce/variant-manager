<?php

namespace fostercommerce\variantmanager\records;

use Craft;
use craft\db\ActiveRecord;
use craft\helpers\Db;

/**
 * @property int $id
 * @property string $message
 * @property string $type
 * @property string|null $dateCreated
 */
class Activity extends ActiveRecord
{
    final public const TABLE_NAME = '{{%variant_manager_activities}}';

    public static function tableName(): string
    {
        return self::TABLE_NAME;
    }

    public static function log(string $message, string $type = 'success'): void
    {
        $currentUser = Craft::$app->getUser()->identity;
        $activity = new self([
            'userId' => $currentUser->id,
            'username' => $currentUser->username,
            'type' => $type,
            'message' => $message,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
        ]);
        $activity->save();
    }
}
