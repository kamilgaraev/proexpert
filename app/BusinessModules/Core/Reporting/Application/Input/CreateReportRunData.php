<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Input;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CreateReportRunData
{
    public function __construct(
        public string $reportCode,
        public ReportFilterSet $filters,
        public array $comparison,
        public DateTimeImmutable $asOf,
        public string $locale,
        public ?string $savedViewId,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $reportCode) !== 1
            || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) !== 1
            || ($savedViewId !== null && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $savedViewId) !== 1)) {
            throw new InvalidArgumentException('create_report_run_data_invalid');
        }

        try {
            CanonicalJson::encode($comparison);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('create_report_run_data_invalid');
        }
    }
}
