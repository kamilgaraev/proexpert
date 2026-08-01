<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportPage
{
    public function __construct(
        public array $rows,
        public array $totals,
        public ReportFreshnessStatus $freshness,
        public ReportQuality $quality,
        public ?string $nextCursor,
        public int $limit,
        public bool $hasMore,
        public ReportWindowSort $sort,
    ) {
        if ($limit < 1 || $limit > 100 || !self::hasUniqueRowKeys($rows)) {
            throw new InvalidArgumentException('report_page_invalid');
        }

        try {
            CanonicalJson::encode($rows);
            CanonicalJson::encode($totals);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('report_page_invalid', 0, $exception);
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
}
