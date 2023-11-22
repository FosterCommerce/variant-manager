<?php

namespace fostercommerce\variantmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\commerce\elements\Variant;
use craft\events\ElementContentEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\services\Content;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use fostercommerce\variantmanager\fields\VariantAttributesField;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\models\Settings;
use fostercommerce\variantmanager\services\ProductVariants;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Variant Manager plugin
 *
 * @method static VariantManager getInstance()
 * @method Settings getSettings()
 * @author Foster Commerce <support@fostercomerce.com>
 * @copyright Foster Commerce
 * @license MIT
 *
 * @property-read Settings $settings
 * @property-read ProductVariants $productVariants
 * @property-read null|array $cpNavItem
 */
class VariantManager extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function(): void {
            $this->registerComponents();
            $this->attachEventHandlers();
        });
    }

    public function getCpNavItem(): ?array
    {
        $cpNavItem = parent::getCpNavItem();

        $cpNavItem['subnav'] = [
            'dashboard' => [
                'label' => 'Dashboard',
                'url' => 'plugin-handle',
            ],
        ];

        return $cpNavItem;
    }

    /**
     * @throws InvalidConfigException
     */
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
            }

            $this->registerPermissions();
            $this->registerTwig();
            $this->registerFields();
            $this->registerViewHooks();
        }

        // Work-around while waiting for https://github.com/craftcms/cms/pull/13955
        if (Craft::$app->getDb()->getIsPgsql()) {
            Event::on(
                Content::class,
                Content::EVENT_AFTER_SAVE_CONTENT,
                static function(ElementContentEvent $elementContentEvent): void {
                    $element = $elementContentEvent->element;
                    $variantAttributesField = FieldHelper::getFirstVariantAttributesField($element->getFieldLayout());

                    if ($variantAttributesField instanceof VariantAttributesField) {
                        $column = ElementHelper::fieldColumnFromField($variantAttributesField);
                        $value = $element->getFieldValue($variantAttributesField->handle);

                        Db::update(Craft::$app->content->contentTable, [
                            $column => $value,
                        ], [
                            'id' => $element->contentId,
                        ], [], true, Craft::$app->getDb());
                    }
                }
            );
        }
    }

    private function registerTwig(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
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
            static function(RegisterUrlRulesEvent $registerUrlRulesEvent): void {
                $registerUrlRulesEvent->rules['variant-manager/dashboard'] = 'variant-manager/dashboard';
                $registerUrlRulesEvent->rules['variant-manager/product-exists'] = 'variant-manager/product-variants/product-exists';
                $registerUrlRulesEvent->rules['variant-manager/upload'] = 'variant-manager/product-variants/upload';
                $registerUrlRulesEvent->rules['variant-manager/export/<id:[0-9]+>'] = 'variant-manager/product-variants/export';
            }
        );
    }

    private function registerFields(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $registerComponentTypesEvent): void {
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
        ]);
    }

    private function registerViewHooks(): void
    {
        Craft::$app->view->hook('cp.commerce.product.edit.details', static fn(array &$context) => Craft::$app->getView()->renderTemplate(
            'variant-manager/fields/product_export', [
                'id' => 'product-export',
                'namespacedId' => 'product-export',
                'name' => 'product-export',
                'product' => $context['product'],
            ]
        ));
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $registerUserPermissionsEvent): void {
            $registerUserPermissionsEvent->permissions[] = [
                'heading' => Craft::t('variant-manager', 'Variant Manager'),
                'permissions' => [
                    'variant-manager:import' => [
                        'label' => Craft::t('variant-manager', 'Import products and variants'),
                        'warning' => Craft::t('variant-manager', 'Imports can potentially overwrite existing variants.'),
                    ],
                    'variant-manager:export' => [
                        'label' => Craft::t('variant-manager', 'Export products and variants'),
                    ],
                ],
            ];
        });
    }
}
