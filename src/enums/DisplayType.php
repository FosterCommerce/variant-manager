<?php

namespace fostercommerce\variantmanager\enums;

use Craft;

enum DisplayType: string
{
	case Dropdown = 'dropdown';

	case RadioButtons = 'radioButtons';

	case TextButtons = 'textButtons';

	case ImageSwatches = 'imageSwatches';

	case ColorSwatches = 'colorSwatches';

	case Lightswitch = 'lightswitch';

	public function label(): string
	{
		return Craft::t('variant-manager', "displayTypes.{$this->value}");
	}

	/**
	 * @param list<self> $displayTypes
	 * @return array<int, array{label: string, value: string}>
	 */
	public static function options(array $displayTypes): array
	{
		return array_map(static fn (self $displayType): array => [
			'label' => $displayType->label(),
			'value' => $displayType->value,
		], $displayTypes);
	}
}
