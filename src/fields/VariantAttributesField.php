<?php

namespace fostercommerce\variantmanager\fields;

use Craft;
use craft\base\ElementInterface;
use \yii\db\Schema;
use craft\helpers\Json;

class VariantAttributesField extends \craft\base\Field {

    public $columnType = Schema::TYPE_TEXT;

	public static function displayName(): string
	{

		return Craft::t('variant-manager', 'Variant Attributes');

	}

    public static function valueType(): string
    {

        return 'array|null';

    }

    protected function defineRules(): array
    {
        
        return array_merge(parent::defineRules(), [

        ]);

    }

    public static function hasContentColumn() : bool 
    {

        return true;

    }

    public function getContentColumnType(): string
    {
        return $this->columnType;
    }


    public function normalizeValue(mixed $value, ElementInterface $element = null) : mixed
    {

        if (is_string($value) && !empty($value)) {

            $value = Json::decodeIfJson($value);

        }

        return $value;

    }

    public function getSettingsHtml() : ?string
    {

        return null;

    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null) : string 
    {   
        
        $name = $this->handle;
        $id = craft\helpers\Html::id($name);
        $namespacedId = Craft::$app->view->namespaceInputId($id);

        $html = Craft::$app->getView()->renderTemplate('variant-manager/fields/variant_attributes', [
            'id' => $id,
            'namespacedId' => $namespacedId,
            'name' => $name,
            'attributes' => $value,
        ]);

        return $html;

    }

}