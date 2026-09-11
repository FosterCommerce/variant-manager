<?php

namespace fostercommerce\variantmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use fostercommerce\variantmanager\Plugin;

class PruneAttributeOrphans extends BaseJob
{
	public function execute($queue): void
	{
		Plugin::getInstance()->getVariantAttributes()->pruneOrphans();
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('variant-manager', 'jobs.pruneAttributeOrphans');
	}
}
