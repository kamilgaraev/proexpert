<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSavedView
{
    public function __construct(
        public string $id,
        public string $reportCode,
        public string $contractVersion,
        public string $name,
        public string $visibility,
        public ReportFilterSet $filters,
        public array $comparison,
        public ReportWindowSort $sort,
        public array $columns,
        public string $status,
        public bool $isDefault,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $id) !== 1
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1
            || trim($contractVersion) === ''
            || trim($name) === ''
            || mb_strlen($name) > 120
            || !in_array($visibility, ['private', 'organization'], true)
            || !in_array($status, ['active', 'needs_migration'], true)
            || !self::validColumns($columns)
            || $createdAt > $updatedAt) {
            throw new InvalidArgumentException('report_saved_view_invalid');
        }
    }

    private static function validColumns(array $columns): bool
    {
        if (!array_is_list($columns) || $columns === []) {
            return false;
        }
        $seen = [];
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1 || isset($seen[$column])) {
                return false;
            }
            $seen[$column] = true;
        }

        return true;
    }
}
