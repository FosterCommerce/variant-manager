<?php

namespace fostercommerce\variantmanager;

use Craft;
use craft\base\conditions\BaseCondition;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\conditions\ElementCondition;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fieldlayoutelements\TitleField;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\actions\BulkEditField;
use fostercommerce\variantmanager\elements\actions\Export;
use fostercommerce\variantmanager\elements\conditions\VariantAttributeConditionRule;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\elements\VariantAttributeOption;
use fostercommerce\variantmanager\elements\VariantManagerVariant;
use fostercommerce\variantmanager\fields\VariantAttributesField;
use fostercommerce\variantmanager\models\Settings;
use fostercommerce\variantmanager\services\ActivityLogs;
use fostercommerce\variantmanager\services\AttributeConfigs;
use fostercommerce\variantmanager\services\Csv;
use fostercommerce\variantmanager\services\ProductVariants;
use fostercommerce\variantmanager\services\VariantAttributes;
use fostercommerce\variantmanager\utilities\AttributesUtility;
use yii\base\Event;
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
 * @property-read VariantAttributes $variantAttributes
 * @property-read AttributeConfigs $attributeConfigs
 * @property-read null|array $cpNavItem
 */
class Plugin extends BasePlugin
{
	public string $schemaVersion = '1.4.0';

	public bool $hasCpSettings = true;

	public bool $hasReadOnlyCpSettings = true;

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
			$this->getAttributeConfigs()->registerOverriddenFieldHandles();
			$this->registerQueue();
			$this->attachEventHandlers();
		});
	}

	public function getCpNavItem(): ?array
	{
		$nav = parent::getCpNavItem();

		$nav['subnav']['dashboard'] = [
			'label' => 'Dashboard',
			'url' => 'variant-manager/dashboard',
		];


		$nav['subnav']['variants'] = [
			'label' => 'Variants',
			'url' => 'variant-manager/variants',
		];

		if (Craft::$app->getUser()->checkPermission('variant-manager:manage-attributes')) {
			$nav['subnav']['attributes'] = [
				'label' => Craft::t('variant-manager', 'attributes.attributes'),
				'url' => 'variant-manager/attributes',
			];

			$nav['subnav']['attribute-options'] = [
				'label' => Craft::t('variant-manager', 'options.options'),
				'url' => 'variant-manager/attribute-options',
			];
		}

		return $nav;
	}

	public function getVariantAttributes(): VariantAttributes
	{
		/** @var VariantAttributes */
		return $this->get('variantAttributes');
	}

	public function getAttributeConfigs(): AttributeConfigs
	{
		/** @var AttributeConfigs */
		return $this->get('attributeConfigs');
	}

	public function getSettingsResponse(): mixed
	{
		return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('variant-manager/settings'));
	}

	public function getReadOnlySettingsResponse(): mixed
	{
		return $this->getSettingsResponse();
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
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

		$this->registerConditionRules();
		$this->registerElements();
		$this->registerNativeFields();
		$this->registerUtilities();
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

		Event::on(
			VariantManagerVariant::class,
			Element::EVENT_REGISTER_ACTIONS,
			static function (RegisterElementActionsEvent $event): void {
				if (Plugin::getInstance()->getSettings()->bulkEditableVariantFields !== []) {
					$event->actions[] = BulkEditField::class;
				}
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
				$registerUrlRulesEvent->rules = [
					...$registerUrlRulesEvent->rules,
					'variant-manager/dashboard' => 'variant-manager/dashboard',
					'variant-manager/product-exists' => 'variant-manager/product-variants/product-exists',
					'variant-manager/export' => 'variant-manager/product-variants/export',
					'variant-manager/variants' => [
						'template' => 'variant-manager/variants/index.twig',
					],
					'variant-manager/settings' => 'variant-manager/settings/index',
					'variant-manager/attributes' => [
						'template' => 'variant-manager/attributes/index.twig',
					],
					'variant-manager/attributes/<elementId:\d+>' => 'elements/edit',
					'variant-manager/attribute-options' => [
						'template' => 'variant-manager/attribute-options/index.twig',
					],
					'variant-manager/attribute-options/<elementId:\d+>' => 'elements/edit',
					'variant-manager/attributes/<attributeId:\d+>/settings' => 'variant-manager/attributes/settings',
				];
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
			'variantAttributes' => VariantAttributes::class,
			'attributeConfigs' => AttributeConfigs::class,
		]);
	}

	private function registerConditionRules(): void
	{
		Event::on(
			ElementCondition::class,
			BaseCondition::EVENT_REGISTER_CONDITION_RULES,
			static function (RegisterConditionRulesEvent $registerConditionRulesEvent): void {
				/** @var ElementCondition $condition */
				$condition = $registerConditionRulesEvent->sender;
				$elementType = $condition->elementType;

				if ($elementType === null || (! is_a($elementType, Variant::class, true) && ! is_a($elementType, Product::class, true))) {
					return;
				}

				foreach (Plugin::getInstance()->getVariantAttributes()->getAllAttributes() as $attribute) {
					$registerConditionRulesEvent->conditionRules[] = [
						'class' => VariantAttributeConditionRule::class,
						'attributeId' => $attribute->id,
					];
				}
			}
		);
	}

	private function registerElements(): void
	{
		Event::on(
			Elements::class,
			Elements::EVENT_REGISTER_ELEMENT_TYPES,
			static function (RegisterComponentTypesEvent $registerComponentTypesEvent): void {
				$registerComponentTypesEvent->types[] = VariantAttribute::class;
				$registerComponentTypesEvent->types[] = VariantAttributeOption::class;
			}
		);
	}

	private function registerNativeFields(): void
	{
		Event::on(
			FieldLayout::class,
			FieldLayout::EVENT_DEFINE_NATIVE_FIELDS,
			static function (DefineFieldLayoutFieldsEvent $defineFieldLayoutFieldsEvent): void {
				/** @var FieldLayout $fieldLayout */
				$fieldLayout = $defineFieldLayoutFieldsEvent->sender;

				// Add a Title field, since Craft doesn't supply one for these element types
				if (in_array($fieldLayout->type, [VariantAttribute::class, VariantAttributeOption::class], true)) {
					$defineFieldLayoutFieldsEvent->fields[] = TitleField::class;
				}
			}
		);
	}

	private function registerUtilities(): void
	{
		Event::on(
			Utilities::class,
			Utilities::EVENT_REGISTER_UTILITIES,
			static function (RegisterComponentTypesEvent $registerComponentTypesEvent): void {
				$registerComponentTypesEvent->types[] = AttributesUtility::class;
			}
		);
	}

	private function registerViewHooks(): void
	{
		Event::on(
			Product::class,
			Element::EVENT_DEFINE_SIDEBAR_HTML,
			static function (DefineHtmlEvent $event): void {
				/** @var Product|null $product */
				$product = $event->sender ?? null;

				$view = Craft::$app->getView();
				$view->registerAssetBundle(ProductExportAssetBundle::class);
				$view->registerTranslations('variant-manager', [
					'Export request failed with status {status}',
				]);

				$event->html .= $view->renderTemplate(
					'variant-manager/fields/product_export',
					[
						'product' => $product,
					]
				);
			}
		);
	}

	private function registerEvents(): void
	{
		Event::on(
			Gc::class,
			Gc::EVENT_RUN,
			static function (Event $_event): void {
				Plugin::getInstance()->activityLogs->gc();

				$garbageCollector = Craft::$app->getGc();
				$garbageCollector->deletePartialElements(VariantAttribute::class, Table::ATTRIBUTES, 'id');
				$garbageCollector->deletePartialElements(VariantAttributeOption::class, Table::ATTRIBUTE_OPTIONS, 'id');

				Plugin::getInstance()->getAttributeConfigs()->removeOrphaned();
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
					'variant-manager:manage-attributes' => [
						'label' => Craft::t('variant-manager', 'permissions.manageAttributes'),
					],
				],
			];
		});
	}
}
