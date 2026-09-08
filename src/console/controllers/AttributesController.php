<?php

namespace fostercommerce\variantmanager\console\controllers;

use craft\commerce\elements\Variant;
use craft\console\Controller;
use craft\helpers\Console;
use fostercommerce\variantmanager\Plugin;
use yii\console\ExitCode;

class AttributesController extends Controller
{
	/**
	 * Number of variants to read per batch.
	 */
	public int $batchSize = 500;

	/**
	 * Delete the orphans instead of only listing them.
	 */
	public bool $prune = false;

	public function options($actionID): array
	{
		$options = [...parent::options($actionID), 'batchSize'];

		if ($actionID === 'orphans') {
			$options[] = 'prune';
		}

		return $options;
	}

	/**
	 * Creates a registry row for every attribute name and option value already stored on a variant.
	 */
	public function actionBackfill(): int
	{
		$variantAttributes = Plugin::getInstance()->getVariantAttributes();
		$variantCount = 0;

		foreach (Variant::find()->status(null)->batch($this->batchSize) as $variants) {
			$variantCount += count($variants);
			$variantAttributes->ensureFromAttributePairs(array_values($variantAttributes->attributePairs($variants)));

			$this->stdout('.');
		}

		$this->stdout(PHP_EOL);
		$this->stdout("Read {$variantCount} variants. New attributes and options are listed in the activity log." . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}

	/**
	 * Lists every attribute and option whose name or value is no longer stored on a variant.
	 */
	public function actionOrphans(): int
	{
		$variantAttributes = Plugin::getInstance()->getVariantAttributes();
		$orphans = $variantAttributes->findOrphans($this->batchSize);

		foreach ($orphans['options'] as $option) {
			$this->stdout("option    {$option->getVariantAttribute()?->name} / {$option->value}" . PHP_EOL);
		}

		foreach ($orphans['attributes'] as $attribute) {
			$this->stdout("attribute {$attribute->name}" . PHP_EOL);
		}

		$optionCount = count($orphans['options']);
		$attributeCount = count($orphans['attributes']);

		if ($optionCount === 0 && $attributeCount === 0) {
			$this->stdout('No orphans.' . PHP_EOL, Console::FG_GREEN);
			return ExitCode::OK;
		}

		if (! $this->prune) {
			$this->stdout("{$optionCount} options and {$attributeCount} attributes are orphaned. Re-run with --prune to delete them." . PHP_EOL, Console::FG_YELLOW);
			return ExitCode::OK;
		}

		$deleted = $variantAttributes->pruneOrphans($this->batchSize, $orphans);
		$this->stdout("Deleted {$deleted['options']} options and {$deleted['attributes']} attributes." . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}
}
