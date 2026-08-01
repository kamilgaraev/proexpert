<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewVersionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersion;

interface ReportSavedViewVersionStore
{
    public function append(CreateReportSavedViewVersionData $data): ReportSavedViewVersion;

    public function find(string $savedViewId, int $revision): ?ReportSavedViewVersion;
}
