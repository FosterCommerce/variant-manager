<?php

namespace fostercommerce\variantmanager\console\controllers;

use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\console\Controller;
use craft\helpers\Console;
use fostercommerce\variantmanager\models\VariantMakerPlanRow;
use fostercommerce\variantmanager\models\VariantMakerProperty;
use fostercommerce\variantmanager\models\VariantMakerSettings;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\services\VariantMaker;
use yii\console\ExitCode;

class VariantMakerController extends Controller
{
	/**
	 * @var string Attributes and values to combine, as "Name=A,B;Other Name=C"
	 */
	public string $select = '';

	/**
	 * @var string One of add, update or replace
	 */
	public string $mode = VariantMaker::MODE_ADD;

	/**
	 * @var string|null SKU format, with a {Attribute Name} token per attribute
	 */
	public ?string $skuFormat = null;

	/**
	 * @var string|null Base price every combination starts from, before its options' modifiers
	 */
	public ?string $basePrice = null;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'select', 'mode', 'skuFormat', 'basePrice'];
	}

	/**
	 * Prints what generating the given combinations would do to a product's variants, without saving anything.
	 */
	public function actionPlan(int $productId): int
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$product = $commerce->getProducts()->getProductById($productId);

		if (! $product instanceof Product) {
			$this->stderr("No product with ID {$productId}." . PHP_EOL, Console::FG_RED);
			return ExitCode::DATAERR;
		}

		$settings = new VariantMakerSettings([
			'mode' => $this->mode,
			'properties' => [
				VariantMakerSettings::PROPERTY_SKU => new VariantMakerProperty([
					'include' => true,
					'value' => $this->skuFormat,
				]),
				VariantMakerSettings::PROPERTY_PRICE => new VariantMakerProperty([
					'include' => true,
					'value' => $this->basePrice,
				]),
				VariantMakerSettings::PROPERTY_TITLE => new VariantMakerProperty([
					'include' => true,
				]),
			],
		]);

		$rows = Plugin::getInstance()->getVariantMaker()->plan($product, $this->parseSelection(), $settings);

		if ($rows === []) {
			$this->stdout('Nothing to plan. Check that --select names registered attributes and values.' . PHP_EOL);
			return ExitCode::OK;
		}

		$countsByStatus = [];

		foreach ($rows as $row) {
			$combination = implode(' / ', array_map(static fn (array $pair): string => $pair['attributeValue'], $row->pairs));
			$countsByStatus[$row->status] = ($countsByStatus[$row->status] ?? 0) + 1;

			$this->stdout(sprintf(
				'%-10s %-40s %-32s %s' . PHP_EOL,
				$row->status,
				$combination,
				$this->change($row->currentSku, $row->sku, $row->status),
				$this->change($row->currentPrice, $row->price, $row->status),
			));

			if ($row->skuIssue !== null) {
				$this->stdout(sprintf('%-10s %s' . PHP_EOL, '', $row->skuIssue), Console::FG_RED);
			}
		}

		$this->stdout(PHP_EOL);

		foreach ($countsByStatus as $status => $count) {
			$this->stdout("{$status}: {$count}" . PHP_EOL);
		}

		return ExitCode::OK;
	}

	/**
	 * Only an update applies the proposed value, so only an update shows the arrow.
	 */
	private function change(?string $current, ?string $proposed, string $status): string
	{
		if ($proposed === null && $current === null) {
			return $status === VariantMakerPlanRow::STATUS_CREATE ? 'default' : 'kept';
		}

		if ($status !== VariantMakerPlanRow::STATUS_UPDATE || $proposed === null || $current === $proposed) {
			return (string) ($current ?? $proposed);
		}

		return $current === null ? $proposed : "{$current} -> {$proposed}";
	}

	/**
	 * @return array<string, list<string>>
	 */
	private function parseSelection(): array
	{
		$valuesByName = [];

		foreach (explode(';', $this->select) as $group) {
			if (! str_contains($group, '=')) {
				continue;
			}

			[$attributeName, $values] = explode('=', $group, 2);
			$valuesByName[trim($attributeName)] = array_values(array_filter(array_map('trim', explode(',', $values))));
		}

		return $valuesByName;
	}
}
