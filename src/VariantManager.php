<?php

namespace fostercommerce\variantmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\Fields;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use fostercommerce\variantmanager\fields\Variants;
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
 */
class VariantManager extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [

            ],
        ];
    }

    public function init()
    {
        parent::init();

        Craft::$app->onInit(function() {
            $this->registerComponents();
            $this->attachEventHandlers();
        });
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

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {

            Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function (RegisterTemplateRootsEvent $event) {
                $event->roots['variant-manager'] = __DIR__ . '/templates';
            });

            $this->registerTwig();
            $this->registerCPRoutes();
            $this->registerFields();
            $this->registerViewHooks();

        }

    }

    private function registerTwig() : void 
    {

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $e) {

                $variable = $e->sender;
    
                $variable->set('variantManager', \fostercommerce\variantmanager\services\ProductVariants::class);

            }
        );

    }

    private function registerRoutes() : void 
    {

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {

                $event->rules['api/product-variants/upload'] = 'variant-manager/product-variants/upload';
                $event->rules['api/product-variants/apply-upload'] = 'variant-manager/product-variants/apply-upload';

                $event->rules['api/product-variants/export/<id:[0-9]+>'] = 'variant-manager/product-variants/export';

                $event->rules['variant-manager/examples/test/<slug>'] = 'variant-manager/examples/test';

            }
        );

    }

    private function registerCPRoutes() : void 
    {

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {

                $event->rules['variant-manager/dashboard'] = 'variant-manager/dashboard';

            }
        );

    }

    private function registerFields() : void
    {

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                Craft::debug(
                    'Fields::EVENT_REGISTER_FIELD_TYPES',
                    __METHOD__
                );
                $event->types[] = \fostercommerce\variantmanager\fields\VariantAttributesField::class;
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

    private function registerViewHooks() : void
    {

        Craft::$app->view->hook('cp.commerce.product.edit.details', function(array &$context) {

            return Craft::$app->getView()->renderTemplate(
                'variant-manager/fields/product_export', [
                        "id" => "product-export",
                        "namespacedId" => "product-export",
                        "name" => "product-export",
                        "product" => $context['product']
                ]
            );

        });

    }

    public function getCpNavItem() : ?array
    {
        
        $item = parent::getCpNavItem();

        $item['subnav'] = [
            'dashboard' => ['label' => 'Dashboard', 'url' => 'plugin-handle'],
        ];

        return $item;

    }



}
