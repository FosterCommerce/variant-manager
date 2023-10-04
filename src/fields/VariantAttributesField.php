<?php

namespace fostercommerce\variantmanager\fields;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Html;
use craft\helpers\Json;
use yii\db\Schema;

class VariantAttributesField extends \craft\base\Field
{
    public $columnType = Schema::TYPE_TEXT;

    public static function displayName(): string
    {
        return Craft::t('variant-manager', 'Variant Attributes');
    }

    public static function valueType(): string
    {
        return 'array|null';
    }

    public static function hasContentColumn(): bool
    {
        return true;
    }

    public function getContentColumnType(): string
    {
        return $this->columnType;
    }

    public function normalizeValue(mixed $value, ?\craft\base\ElementInterface $element = null): mixed
    {
        if (is_string($value) && $value !== '') {
            return Json::decodeIfJson($value);
        }

        return $value;
    }

    public function getSettingsHtml(): ?string
    {
        return null;
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $name = $this->handle;
        $id = Html::id($name);
        $namespacedId = Craft::$app->view->namespaceInputId($id);

        return Craft::$app->getView()->renderTemplate('variant-manager/fields/variant_attributes', [
            'id' => $id,
            'namespacedId' => $namespacedId,
            'name' => $name,
            'attributes' => $value,
        ]);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [

        ]);
    }
}
