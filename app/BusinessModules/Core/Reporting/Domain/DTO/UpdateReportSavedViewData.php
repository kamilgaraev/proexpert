<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

final readonly class UpdateReportSavedViewData
{
    public function __construct(public array $changes) {}
}
