<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportResult
{
    public function __construct(
        public ReportResultMetadata $metadata,
        public array $totals,
        public ReportFreshnessStatus $freshness,
        public ReportQuality $quality,
        public ReportProvenance $provenance,
        public array $rowSchema,
        public array $capabilities,
    ) {
        if ($metadata->snapshot->sourceHash->value !== $provenance->sourceHash->value || !self::hasUniqueSchemaIds($rowSchema)) {
            throw new InvalidArgumentException('report_result_invalid');
        }

        try {
            CanonicalJson::encode($totals);
            CanonicalJson::encode($rowSchema);
            CanonicalJson::encode($capabilities);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('report_result_invalid', 0, $exception);
        }
    }

    private static function hasUniqueSchemaIds(array $rowSchema): bool
    {
        if (!array_is_list($rowSchema)) {
            return false;
        }

        $ids = [];
        foreach ($rowSchema as $column) {
            if (!is_array($column) || !array_key_exists('id', $column) || !is_string($column['id']) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $column['id']) !== 1 || isset($ids[$column['id']])) {
                return false;
            }

            $ids[$column['id']] = true;
        }

        return true;
    }
}
