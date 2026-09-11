<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\web\Controller;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\enums\DisplayType;
use fostercommerce\variantmanager\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	public function actionIndex(): Response
	{
		$this->requireAdmin(false);

		$settings = Plugin::getInstance()->getSettings();

		return $this->renderTemplate('variant-manager/settings/index', [
			'attributes' => VariantAttribute::find()->all(),
			'settings' => $settings,
			'displayTypeOptions' => DisplayType::options(DisplayType::cases()),
			'defaultDisplayTypeOptions' => DisplayType::options($settings->getAvailableDisplayTypes($settings->defaultDisplayType)),
			'readOnly' => ! Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdmin();

		$settings = $this->request->getBodyParam('settings', []);
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
