<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use JsonException;

final readonly class ReportFilterSet
{
    public array $values;

    public function __construct(array $values)
    {
        $this->values = self::canonicalize($values);
    }

    private static function canonicalize(array $values): array
    {
        try {
            return json_decode(CanonicalJson::encode($values), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \InvalidArgumentException('report_filter_values_invalid');
        }
    }
}
