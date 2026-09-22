<?php

namespace fostercommerce\variantmanager\elements\deletionblockers;

use Craft;
use craft\elements\deletionblockers\BaseDeletionBlocker;
use craft\helpers\Cp;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\Plugin;

/**
 * Reports the selected records that variants still use.
 *
 * @since 4.2.1
 */
class VariantsInUseBlocker extends BaseDeletionBlocker
{
	/**
	 * @var list<VariantAttribute>
	 */
	private array $inUseElements = [];

	public function init(): void
	{
		$variantAttributes = Plugin::getInstance()->getVariantAttributes();

		foreach ($this->elements as $element) {
			if (! $element instanceof VariantAttribute) {
				continue;
			}

			$inUse = $element->isOption()
				? $variantAttributes->isOptionInUse($element)
				: $variantAttributes->isAttributeInUse($element);

			if ($inUse) {
				$this->inUseElements[] = $element;
			}
		}

		parent::init();
	}

	public function isActive(): bool
	{
		return $this->inUseElements !== [];
	}

	public function getSummary(): string
	{
		return Craft::t('variant-manager', 'attributes.deleteBlocked', [
			'count' => count($this->inUseElements),
		]);
	}

	public function getDetails(): ?string
	{
		return Cp::elementPreviewHtml($this->inUseElements);
	}

	/**
	 * An action would have to resolve the blocker, and only removing the value from every variant does that.
	 *
	 * @return array<array-key, mixed>
	 */
	public function getActions(): array
	{
		return [];
	}
}
