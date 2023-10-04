<?php

namespace fostercommerce\variantmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use fostercommerce\variantmanager\models\Settings;
use yii\base\Event;

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

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('variantmanager/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        $this->registerRoutes();

        if (! Craft::$app->getRequest()->getIsConsoleRequest()) {
            Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, static function(RegisterTemplateRootsEvent $registerTemplateRootsEvent): void {
                $registerTemplateRootsEvent->roots['variant-manager'] = __DIR__ . '/templates';
            });

            $this->registerTwig();
            $this->registerCPRoutes();
            $this->registerFields();
            $this->registerViewHooks();
        }
    }

    private function registerTwig(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                $variable = $event->sender;
                $variable->set('variantManager', \fostercommerce\variantmanager\services\ProductVariants::class);
            }
        );
    }

    private function registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $registerUrlRulesEvent): void {
                $registerUrlRulesEvent->rules['api/product-variants/upload'] = 'variant-manager/product-variants/upload';
                $registerUrlRulesEvent->rules['api/product-variants/apply-upload'] = 'variant-manager/product-variants/apply-upload';
                $registerUrlRulesEvent->rules['api/product-variants/export/<id:[0-9]+>'] = 'variant-manager/product-variants/export';
                $registerUrlRulesEvent->rules['variant-manager/examples/test/<slug>'] = 'variant-manager/examples/test';
            }
        );
    }

    private function registerCPRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $registerUrlRulesEvent): void {
                $registerUrlRulesEvent->rules['variant-manager/dashboard'] = 'variant-manager/dashboard';
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
                $registerComponentTypesEvent->types[] = \fostercommerce\variantmanager\fields\VariantAttributesField::class;
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
            'productVariants' => \fostercommerce\variantmanager\services\ProductVariants::class,
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
}
