<?php

namespace fostercommerce\variantmanager;

use Craft;
use craft\base\conditions\BaseCondition;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\console\Controller as ConsoleController;
use craft\console\controllers\ResaveController;
use craft\elements\conditions\ElementCondition;
use craft\events\CreateFieldLayoutFormEvent;
use craft\events\DefineConsoleActionsEvent;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\MoveElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fieldlayoutelements\TitleField;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Structures;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\actions\BulkEditField;
use fostercommerce\variantmanager\elements\actions\Export;
use fostercommerce\variantmanager\elements\conditions\VariantAttributeConditionRule;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\elements\VariantManagerVariant;
use fostercommerce\variantmanager\fieldlayoutelements\SystemNameField;
use fostercommerce\variantmanager\fieldlayoutelements\VariantMakerTab;
use fostercommerce\variantmanager\fields\VariantAttributesField;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\helpers\PermissionHelper;
use fostercommerce\variantmanager\models\Settings;
use fostercommerce\variantmanager\services\ActivityLogs;
use fostercommerce\variantmanager\services\Csv;
use fostercommerce\variantmanager\services\FieldSets;
use fostercommerce\variantmanager\services\ProductVariants;
use fostercommerce\variantmanager\services\VariantAttributes;
use fostercommerce\variantmanager\services\VariantMaker;
use fostercommerce\variantmanager\utilities\AttributesUtility;
use yii\base\Event;
use yii\di\Instance;
use yii\queue\Queue;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 *
 * @property-read Settings $settings
 * @property-read ProductVariants $productVariants
 * @property-read Csv $csv
 * @property-read ActivityLogs $activityLogs
 * @property-read VariantAttributes $variantAttributes
 * @property-read VariantMaker $variantMaker
 * @property-read FieldSets $fieldSets
 * @property-read null|array $cpNavItem
 */
class Plugin extends BasePlugin
{
	private const VARIANT_MAKER_TAB_UID = 'f05d5b7a-9a3e-4a2f-9f4e-6b1c2d3e4f50';

	public string $schemaVersion = '1.10.0';

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
			$this->getFieldSets()->registerOverriddenFieldHandles();
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

		if (PermissionHelper::canSaveAnyProductType()) {
			$nav['subnav']['attributes'] = [
				'label' => Craft::t('variant-manager', 'attributes.attributes'),
				'url' => 'variant-manager/attributes',
			];
		}

		if (Craft::$app->getUser()->getIsAdmin()) {
			$nav['subnav']['settings'] = [
				// Craft's own Settings item sits in the same sidebar, so name the section this one opens
				'ariaLabel' => Craft::t('variant-manager', 'settings.navAriaLabel'),
				'label' => Craft::t('app', 'Settings'),
				'url' => 'variant-manager/settings',
			];
		}

		return $nav;
	}

	public function getVariantMaker(): VariantMaker
	{
		/** @var VariantMaker */
		return $this->get('variantMaker');
	}

	public function getVariantAttributes(): VariantAttributes
	{
		/** @var VariantAttributes */
		return $this->get('variantAttributes');
	}

	public function getCsv(): Csv
	{
		/** @var Csv */
		return $this->get('csv');
	}

	public function getFieldSets(): FieldSets
	{
		/** @var FieldSets */
		return $this->get('fieldSets');
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
		if (Craft::$app->getRequest()->getIsConsoleRequest()) {
			$this->registerResaveCommand();
		} else {
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
		$this->registerVariantMakerTab();
		$this->registerVariantMakerSettings();
		$this->registerUtilities();
		$this->registerEvents();
	}

	private function registerResaveCommand(): void
	{
		Event::on(
			ResaveController::class,
			ConsoleController::EVENT_DEFINE_ACTIONS,
			static function (DefineConsoleActionsEvent $defineConsoleActionsEvent): void {
				$defineConsoleActionsEvent->actions['variant-attributes'] = [
					'action' => static function (): int {
						/** @var ResaveController $controller */
						$controller = Craft::$app->controller;
						return $controller->resaveElements(VariantAttribute::class);
					},
					'helpSummary' => 'Re-saves variant attributes and their options.',
				];
			}
		);
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
				if (Craft::$app->getUser()->checkPermission('variant-manager:export')) {
					$event->actions[] = Export::class;
				}
			}
		);

		Event::on(
			VariantManagerVariant::class,
			Element::EVENT_REGISTER_ACTIONS,
			static function (RegisterElementActionsEvent $event): void {
				if (
					BulkEditField::hasEditableField()
					&& PermissionHelper::canSaveAnyProductType()
				) {
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
					'variant-manager/attributes/<attributeId:\d+>/settings' => 'variant-manager/attributes/settings',
					'variant-manager/field-sets/new' => 'variant-manager/field-sets/edit',
					'variant-manager/field-sets/<fieldSetUid:[^\/]+>' => 'variant-manager/field-sets/edit',
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
			'variantMaker' => VariantMaker::class,
			'fieldSets' => FieldSets::class,
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
			}
		);
	}

	private function registerVariantMakerSettings(): void
	{
		Event::on(
			Product::class,
			Product::EVENT_AFTER_VALIDATE,
			static function (Event $_event): void {
				$product = $_event->sender;

				if (! $product instanceof Product) {
					return;
				}

				$settings = self::isProductSave() ? Plugin::getInstance()->getVariantMaker()->postedSettings() : null;

				if ($settings === null || $settings->validate()) {
					return;
				}

				foreach ($settings->getErrors() as $errors) {
					foreach ($errors as $error) {
						$product->addError('variantMaker', $error);
					}
				}
			}
		);

		Event::on(
			Elements::class,
			Elements::EVENT_AFTER_SAVE_ELEMENT,
			static function (ElementEvent $elementEvent): void {
				$product = $elementEvent->element;

				// An autosaved draft would otherwise store the builder on every keystroke
				if (! $product instanceof Product || ElementHelper::isDraftOrRevision($product)) {
					return;
				}

				$settings = Plugin::getInstance()->getVariantMaker()->postedSettings();

				if ($settings !== null) {
					Plugin::getInstance()->getVariantMaker()->saveSettings($product, $settings);
				}
			}
		);
	}

	/**
	 * Craft validates a clone of the canonical product to create a draft, so an invalid builder would block
	 * every autosave rather than the save the merchant asked for.
	 */
	private static function isProductSave(): bool
	{
		$request = Craft::$app->getRequest();

		// Skip a console request. Only a web request routes by action segments.
		if ($request->getIsConsoleRequest()) {
			return false;
		}

		return in_array(implode('/', $request->getActionSegments() ?? []), [
			'elements/save',
			'elements/apply-draft',
		], true);
	}

	private function registerVariantMakerTab(): void
	{
		Event::on(
			FieldLayout::class,
			FieldLayout::EVENT_CREATE_FORM,
			static function (CreateFieldLayoutFormEvent $createFieldLayoutFormEvent): void {
				$product = $createFieldLayoutFormEvent->element;

				if (! $product instanceof Product) {
					return;
				}

				// Skip a revision. A snapshot has no live variants to generate against.
				if ($product->getIsRevision()) {
					return;
				}

				$productType = $product->getType();

				if (! Plugin::getInstance()->getSettings()->offersVariantMaker($productType->handle)) {
					return;
				}

				// A product type with no attributes field gives the generated variants nowhere to store a combination
				if (FieldHelper::getFirstVariantAttributesField($productType->getVariantFieldLayout()) === null) {
					return;
				}

				// Configure the tab's layout before its elements. setElements() reads the layout.
				// Give the tab a stable uid, or the element editor's re-render mismaps every tab
				$createFieldLayoutFormEvent->tabs[] = new FieldLayoutTab([
					'layout' => $createFieldLayoutFormEvent->sender,
					'uid' => self::VARIANT_MAKER_TAB_UID,
					'name' => Craft::t('variant-manager', 'variantMaker.name'),
					'sortOrder' => count($createFieldLayoutFormEvent->tabs) + 1,
					'elements' => [new VariantMakerTab()],
				]);
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

				// Add a Title field, because Craft does not supply one for these element types
				if ($fieldLayout->type === VariantAttribute::class) {
					$defineFieldLayoutFieldsEvent->fields[] = [
						'class' => TitleField::class,
						'label' => Craft::t('variant-manager', 'attributes.displayName'),
					];
					$defineFieldLayoutFieldsEvent->fields[] = SystemNameField::class;
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
			Elements::class,
			Elements::EVENT_AFTER_SAVE_ELEMENT,
			static function (ElementEvent $elementEvent): void {
				$element = $elementEvent->element;

				// Skip a propagated save. It repeats the first site's values.
				// Skip a draft or revision. Its values might never be published.
				if (! $element instanceof Variant || $element->propagating || ElementHelper::isDraftOrRevision($element)) {
					return;
				}

				// Nothing else registers a value written outside an import or the backfill
				$variantAttributes = Plugin::getInstance()->getVariantAttributes();
				$variantAttributes->ensureFromAttributePairs(array_values($variantAttributes->attributePairs([$element])));
			},
		);

		Event::on(
			Structures::class,
			Structures::EVENT_BEFORE_UPDATE_ELEMENT,
			static function (MoveElementEvent $moveElementEvent): void {
				$element = $moveElementEvent->element;

				if (! $element instanceof VariantAttribute) {
					return;
				}

				$target = $moveElementEvent->getTargetElement();

				$newAttributeId = match (true) {
					! $target instanceof VariantAttribute => 0,
					in_array($moveElementEvent->action, [Structures::ACTION_PREPEND, Structures::ACTION_APPEND], true) => (int) $target->id,
					default => $target->attributeId,
				};

				// Refuse a move to another attribute, because the key is attributeId plus nameKey
				$moveElementEvent->isValid = $newAttributeId === $element->attributeId;
			},
		);

		Event::on(
			Gc::class,
			Gc::EVENT_RUN,
			static function (Event $_event): void {
				Plugin::getInstance()->activityLogs->gc();

				$garbageCollector = Craft::$app->getGc();
				$garbageCollector->deletePartialElements(VariantAttribute::class, Table::ATTRIBUTES, 'id');
			},
		);
	}

	private function registerPermissions(): void
	{
		Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function (RegisterUserPermissionsEvent $registerUserPermissionsEvent): void {
			$registerUserPermissionsEvent->permissions[] = [
				'heading' => Craft::t('variant-manager', 'Variant Manager'),
				'permissions' => [
					'variant-manager:manage' => [
						'label' => Craft::t('variant-manager', 'permissions.manage'),
					],
					'variant-manager:export' => [
						'label' => Craft::t('variant-manager', 'permissions.export'),
					],
				],
			];
		});
	}
}
