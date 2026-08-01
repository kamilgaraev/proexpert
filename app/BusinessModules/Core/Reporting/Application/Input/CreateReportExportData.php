<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Input;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CreateReportExportData
{
    public function __construct(
        public string $format,
        public array $columns,
        public ReportWindowSort $sort,
        public string $locale,
        public DateTimeZone $timezone,
    ) {
        if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)
            || !array_is_list($columns)
            || $columns === []
            || count($columns) !== count(array_unique($columns, SORT_REGULAR))
            || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) !== 1
            || !in_array($timezone->getName(), DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('create_report_export_data_invalid');
        }

        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $column) !== 1) {
                throw new InvalidArgumentException('create_report_export_data_invalid');
            }
        }
    }
}
