<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

final readonly class CreateReportSavedViewData
{
    public function __construct(public string $reportCode, public string $name, public string $visibility, public ReportFilterSet $filters, public array $comparison, public ReportWindowSort $sort, public array $columns, public bool $isDefault) {}
}
