<?php

namespace fostercommerce\variantmanager\elements\deletionblockers;

use Craft;
use craft\elements\deletionblockers\BaseDeletionBlocker;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\Plugin;

/**
 * Reports the selected records that variants still store, which beforeDelete() refuses.
 *
 * @since 4.3.0
 */
class VariantsInUseBlocker extends BaseDeletionBlocker
{
	private int $inUseCount = 0;

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
				++$this->inUseCount;
			}
		}

		parent::init();
	}

	public function isActive(): bool
	{
		return $this->inUseCount !== 0;
	}

	public function getSummary(): string
	{
		return Craft::t('variant-manager', 'attributes.deleteBlocked', [
			'count' => $this->inUseCount,
		]);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function getActions(): array
	{
		// Only removing the value from every variant unblocks the delete, which no action here can do
		return [];
	}
}
