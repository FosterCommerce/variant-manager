<?php

namespace fostercommerce\variantmanager\jobs;

use Craft;
use craft\base\Batchable;
use craft\commerce\elements\Variant;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedElementJob;
use fostercommerce\variantmanager\Plugin;

class BackfillAttributes extends BaseBatchedElementJob
{
	public int $batchSize = 500;

	/**
	 * @var array<string, array{attributeName: string, attributeValue: string}>
	 */
	private array $pairs = [];

	protected function loadData(): Batchable
	{
		$query = Variant::find()
			->status(null)
			->offset(null)
			->limit(null)
			->orderBy([
				'elements.id' => SORT_ASC,
			]);

		return new QueryBatcher($query);
	}

	protected function processItem(mixed $variant): void
	{
		$this->pairs = [
			...$this->pairs,
			...Plugin::getInstance()->getVariantAttributes()->attributePairs([$variant]),
		];
	}

	/**
	 * Register the batch's distinct pairs.
	 *
	 * Registering per variant would run three element queries for each one.
	 */
	protected function afterBatch(): void
	{
		if ($this->pairs !== []) {
			Plugin::getInstance()->getVariantAttributes()->ensureFromAttributePairs(array_values($this->pairs));
			$this->pairs = [];
		}

		parent::afterBatch();
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('variant-manager', 'jobs.backfillAttributes');
	}
}
