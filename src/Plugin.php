<?php

namespace fostercommerce\variantmanager;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Product;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use fostercommerce\variantmanager\elements\actions\Export;
use fostercommerce\variantmanager\fields\VariantAttributesField;
use fostercommerce\variantmanager\models\Settings;
use fostercommerce\variantmanager\services\ActivityLogs;
use fostercommerce\variantmanager\services\Csv;
use fostercommerce\variantmanager\services\ProductVariants;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
use yii\di\Instance;
use yii\queue\Queue;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @author Foster Commerce <support@fostercomerce.com>
 * @copyright Foster Commerce
 * @license MIT
 *
 * @property-read Settings $settings
 * @property-read ProductVariants $productVariants
 * @property-read Csv $csv
 * @property-read ActivityLogs $activityLogs
 * @property-read null|array $cpNavItem
 */
class Plugin extends BasePlugin
{
	public string $schemaVersion = '1.0.0';

	public bool $hasCpSettings = false;

	public bool $hasCpSection = true;

	/**
	 * The queue to use for running jobs.
	 *
	 * @see [craft-blitz](https://github.com/putyourlightson/craft-blitz/blob/a7dc7b3d1f547e141d165c71c9ad6290a9dc2792/src/Blitz.php#L131)
	 * @see [Custom Queues](https://putyourlightson.com/plugins/blitz#custom-queues)
	 */
	public Queue|array|string $queue = 'queue';

	public function init(): void
	{
		parent::init();

		Craft::$app->onInit(function (): void {
			$this->registerComponents();
			$this->registerQueue();
			$this->attachEventHandlers();
		});
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	/**
	 * @throws SyntaxError
	 * @throws RuntimeError
	 * @throws Exception
	 * @throws LoaderError
	 */
	protected function settingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('variantmanager/_settings', [
			'plugin' => $this,
			'settings' => $this->getSettings(),
		]);
	}

	private function attachEventHandlers(): void
	{
		if (! Craft::$app->getRequest()->getIsConsoleRequest()) {
			if (Craft::$app->getRequest()->getIsCpRequest()) {
				$this->registerCpRoutes();
				$this->registerActions();
			}

			$this->registerPermissions();
			$this->registerTwig();
			$this->registerFields();
			$this->registerViewHooks();
		}

		$this->registerEvents();
	}

	private function registerQueue(): void
	{
		$this->queue = Instance::ensure($this->queue, Queue::class);
	}

	private function registerActions(): void
	{
		Event::on(
			Product::class,
			Element::EVENT_REGISTER_ACTIONS,
			static function (RegisterElementActionsEvent $event): void {
				$event->actions[] = Export::class;
			}
		);
	}

	private function registerTwig(): void
	{
		Event::on(
			CraftVariable::class,
			CraftVariable::EVENT_INIT,
			static function (Event $event): void {
				$variable = $event->sender;
				$variable->set('variantManager', ProductVariants::class);
			}
		);
	}

	private function registerCpRoutes(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			static function (RegisterUrlRulesEvent $registerUrlRulesEvent): void {
				$registerUrlRulesEvent->rules['variant-manager/dashboard'] = 'variant-manager/dashboard';
				$registerUrlRulesEvent->rules['variant-manager/product-exists'] = 'variant-manager/product-variants/product-exists';
				$registerUrlRulesEvent->rules['variant-manager/export'] = 'variant-manager/product-variants/export';
				$registerUrlRulesEvent->rules['variant-manager/save-variant-attributes/<variantId:\d+>'] = 'variant-manager/product-variants/save-variant-attributes';
			}
		);
	}

	private function registerFields(): void
	{
		Event::on(
			Fields::class,
			Fields::EVENT_REGISTER_FIELD_TYPES,
			static function (RegisterComponentTypesEvent $registerComponentTypesEvent): void {
				Craft::debug(
					'Fields::EVENT_REGISTER_FIELD_TYPES',
					__METHOD__
				);
				$registerComponentTypesEvent->types[] = VariantAttributesField::class;
			}
		);
	}

	private function registerComponents(): void
	{
		if (Craft::$app->getRequest()->getIsConsoleRequest()) {
			$this->controllerNamespace = 'fostercommerce\\variantmanager\\console\\controllers';
		} else {
			$this->controllerNamespace = 'fostercommerce\\variantmanager\\controllers';
		}

		$this->setComponents([
			'productVariants' => ProductVariants::class,
			'csv' => Csv::class,
			'activityLogs' => ActivityLogs::class,
		]);
	}

	private function registerViewHooks(): void
	{
		Craft::$app->view->hook('cp.commerce.product.edit.details', static fn (array &$context) => Craft::$app->getView()->renderTemplate(
			'variant-manager/fields/product_export',
			[
				'id' => 'product-export',
				'namespacedId' => 'product-export',
				'name' => 'product-export',
				'product' => $context['product'],
			]
		));

		Event::on(
			Product::class,
			Element::EVENT_DEFINE_SIDEBAR_HTML,
			static function (DefineHtmlEvent $event): void {
				$entry = $event->sender ?? null;

				$html = Craft::$app->getView()->renderTemplate(
					'variant-manager/fields/product_export',
					[
						'id' => 'product-export',
						'namespacedId' => 'product-export',
						'name' => 'product-export',
						'product' => $entry,
					]
				);

				$event->html .= $html;
			}
		);
	}

	private function registerEvents(): void
	{
		Event::on(
			Gc::class,
			Gc::EVENT_RUN,
			function (Event $_event): void {
				$this->activityLogs->removeExpiredActivityLogs();
			},
		);
	}

	private function registerPermissions(): void
	{
		Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function (RegisterUserPermissionsEvent $registerUserPermissionsEvent): void {
			$registerUserPermissionsEvent->permissions[] = [
				'heading' => Craft::t('variant-manager', 'Variant Manager'),
				'permissions' => [
					'variant-manager:import' => [
						'label' => Craft::t('variant-manager', 'Import/edit products and variants'),
						'warning' => Craft::t('variant-manager', 'Imports can potentially overwrite existing variants.'),
					],
					'variant-manager:export' => [
						'label' => Craft::t('variant-manager', 'Export products and variants'),
					],
					'variant-manager:manage' => [
						'label' => Craft::t('variant-manager', 'Manage'),
					],
				],
			];
		});
	}
}
