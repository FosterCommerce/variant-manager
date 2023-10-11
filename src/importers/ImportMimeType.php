<?php

namespace fostercommerce\variantmanager\importers;

enum ImportMimeType: string
{
    case Csv = 'text/csv';
    case Json = 'application/json';

    public const DEFAULT = self::Csv;

    public static function default(): self
    {
        return self::DEFAULT;
    }
}
