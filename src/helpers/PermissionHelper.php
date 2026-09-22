<?php

namespace fostercommerce\variantmanager\helpers;

use Craft;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use yii\web\ForbiddenHttpException;

abstract class PermissionHelper
{
	public static function canSaveProductType(ProductType $productType, ?User $user = null): bool
	{
		$user ??= Craft::$app->getUser()->getIdentity();

		return $user?->can("commerce-saveProductType:{$productType->uid}") === true;
	}

	/**
	 * @throws ForbiddenHttpException
	 */
	public static function requireSaveAnyProductType(): void
	{
		if (! self::canSaveAnyProductType()) {
			throw new ForbiddenHttpException('User not authorized to manage variants.');
		}
	}

	/**
	 * Whether the user can save any product type at all, for the screens that are not scoped to one.
	 */
	public static function canSaveAnyProductType(?User $user = null): bool
	{
		if (! $user instanceof User && Craft::$app->getRequest()->getIsConsoleRequest()) {
			return true;
		}

		$user ??= Craft::$app->getUser()->getIdentity();

		// An admin has every permission, and a store with no product type would exit the loop unmatched
		if ($user?->admin === true) {
			return true;
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		foreach ($commerce->getProductTypes()->getAllProductTypes() as $productType) {
			if (self::canSaveProductType($productType, $user)) {
				return true;
			}
		}

		return false;
	}
}
