<?php

namespace fostercommerce\variantmanager\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table as CraftTable;

/**
 * Revoke variant-manager:import and variant-manager:manage-attributes.
 *
 * Nothing replaces them. Catalog access moved to Commerce's commerce-saveProductType, which an admin grants per
 * product type, and variant-manager:manage now gates only clearing the activity log.
 */
class m260921_090000_collapse_permissions extends Migration
{
	private const REMOVED = [
		'variant-manager:import',
		'variant-manager:manage-attributes',
	];

	public function safeUp(): bool
	{
		// The permissionId foreign keys cascade, so this takes every user grant with it
		$this->delete(CraftTable::USERPERMISSIONS, [
			'name' => self::REMOVED,
		]);

		$projectConfig = Craft::$app->getProjectConfig();

		// A read-only install takes the revoked permissions from the yaml instead
		if ($projectConfig->readOnly) {
			return true;
		}

		// Project config stores a group's permissions, and Craft rebuilds the join table from it
		$groups = $projectConfig->get('users.groups');

		foreach (is_array($groups) ? $groups : [] as $uid => $group) {
			$permissions = is_array($group) ? (array) ($group['permissions'] ?? []) : [];
			$kept = array_values(array_diff($permissions, self::REMOVED));

			if ($kept !== $permissions) {
				$projectConfig->set('users.groups.' . $uid . '.permissions', $kept);
			}
		}

		return true;
	}
}
