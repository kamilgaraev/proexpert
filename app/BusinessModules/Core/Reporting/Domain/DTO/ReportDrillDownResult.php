<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportDrillDownResult
{
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
        public array $resourceLinks,
    ) {
        if (!self::hasUniqueRowKeys($rows) || !self::hasTypedLinks($resourceLinks)) {
            throw new InvalidArgumentException('report_drill_down_result_invalid');
        }

        try {
            CanonicalJson::encode($rows);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('report_drill_down_result_invalid', 0, $exception);
        }
    }

    private static function hasUniqueRowKeys(array $rows): bool
    {
        if (!array_is_list($rows)) {
            return false;
        }

        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row) || !isset($row['row_key']) || !is_string($row['row_key']) || trim($row['row_key']) === '' || isset($keys[$row['row_key']])) {
                return false;
            }

            $keys[$row['row_key']] = true;
        }

        return true;
    }

    private static function hasTypedLinks(array $resourceLinks): bool
    {
        if (!array_is_list($resourceLinks)) {
            return false;
        }

        foreach ($resourceLinks as $resourceLink) {
            if (!$resourceLink instanceof ReportResourceLink) {
                return false;
            }
        }

        return true;
    }
}
