<?php

namespace fostercommerce\variantmanager\exporters;

enum ExportType: string
{
    case Csv = 'csv';
    case Json = 'json';

    public const DEFAULT = self::Csv;

    public static function default(): self
    {
        return self::DEFAULT;
    }
}
