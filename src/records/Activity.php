<?php

namespace fostercommerce\variantmanager\records;

use craft\db\ActiveRecord;
use craft\elements\User;
use craft\helpers\Db;
use fostercommerce\variantmanager\db\Table;

/**
 * @property int $id
 * @property string $message
 * @property string $type
 * @property string|null $dateCreated
 */
class Activity extends ActiveRecord
{
	final public const TABLE_NAME = Table::ACTIVITIES;

	public static function tableName(): string
	{
		return self::TABLE_NAME;
	}

	public static function log(?User $user, string $message, string $type = 'success'): void
	{
		$activity = new self([
			'userId' => $user?->id ?? 0,
			'username' => $user?->username ?? 'Unknown',
			'type' => $type,
			'message' => $message,
			'dateCreated' => Db::prepareDateForDb(new \DateTime()),
		]);
		$activity->save();
	}
}
