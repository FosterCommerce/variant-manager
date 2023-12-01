<?php

namespace fostercommerce\variantmanager\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\helpers\Json;

class Export extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('variant-manager', 'Export');
    }

    public function getTriggerHtml(): ?string
    {
        $type = Json::encode(static::class);
        $js = <<<EOT
(function()
{
    var trigger = new Craft.ElementActionTrigger({
        type: {$type},
        batch: true,
        activate: function(\$selectedItems)
        {
            Craft.redirectTo(Craft.getUrl(
                'variant-manager/export',
                {
                    ids: Craft.elementIndex.getSelectedElementIds().join('|'),
                    download: true,
                    format: 'csv',
                },
            ));
        }
    });
})();
EOT;

        Craft::$app->getView()->registerJs($js);

        return null;
    }
}
