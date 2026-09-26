<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use fostercommerce\variantmanager\enums\DisplayType;
use fostercommerce\variantmanager\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SettingsController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	public function actionIndex(): Response
	{
		$this->requireAdmin(false);

		$settings = Plugin::getInstance()->getSettings();

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return $this->renderTemplate('variant-manager/settings/index', [
			'fieldSets' => Plugin::getInstance()->getFieldSets()->getAllFieldSets(),
			'settings' => $settings,
			'displayTypeOptions' => DisplayType::options(DisplayType::cases()),
			'defaultDisplayTypeOptions' => DisplayType::options($settings->getAvailableDisplayTypes()),
			'defaultDisplayType' => $settings->getDefaultDisplayType()->value,
			'productTypeOptions' => array_map(
				static fn (ProductType $productType): array => [
					'label' => $productType->name,
					'value' => $productType->handle,
				],
				$commerce->getProductTypes()->getAllProductTypes(),
			),
			'readOnly' => ! Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdmin();

		$settings = $this->request->getBodyParam('settings') ?: [];

		if (! is_array($settings)) {
			throw new BadRequestHttpException('settings must be an array.');
		}

		$plugin = Plugin::getInstance();

		if (! Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
			$this->setFailFlash(Craft::t('variant-manager', 'settings.saveFailed'));

			Craft::$app->getUrlManager()->setRouteParams([
				'settings' => $plugin->getSettings(),
			]);

			return null;
		}

		$this->setSuccessFlash(Craft::t('variant-manager', 'settings.saved'));

		return $this->redirectToPostedUrl();
	}
}
