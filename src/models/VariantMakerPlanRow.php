<?php

namespace fostercommerce\variantmanager\models;

use craft\base\Model;

class VariantMakerPlanRow extends Model
{
	public const STATUS_CREATE = 'create';

	public const STATUS_UPDATE = 'update';

	public const STATUS_UNCHANGED = 'unchanged';

	public const STATUS_DELETE = 'delete';

	/**
	 * @var list<array{attributeName: string, attributeValue: string}>
	 */
	public array $pairs = [];

	/**
	 * @var string Normalized and sorted, so the same combination in a different order is one row
	 */
	public string $combinationKey = '';

	/**
	 * @phpstan-var self::STATUS_*
	 */
	public string $status = self::STATUS_CREATE;

	public ?int $variantId = null;

	public ?string $sku = null;

	public ?string $currentSku = null;

	/**
	 * @var string|null Why Commerce would reject this row's SKU, since it validates the whole run as one save
	 */
	public ?string $skuIssue = null;

	/**
	 * @var string|null Null where the product type generates variant titles, since Commerce overwrites ours
	 */
	public ?string $title = null;

	public ?string $currentTitle = null;

	public ?string $price = null;

	public ?string $currentPrice = null;

	/**
	 * @var array<string, bool|int|null> purchasable properties this row would write, by the maker's apply rules
	 */
	public array $properties = [];

	public function property(string $propertyName): bool|int|null
	{
		return $this->properties[$propertyName] ?? null;
	}
}
