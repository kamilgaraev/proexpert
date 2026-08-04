<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationRecord;

interface ReportPublicationRegistry
{
    public function current(string $code): ?PublishedReportDefinition;

    public function currentRecord(string $code): ?ReportPublicationRecord;

    /** @return string[] */
    public function publishedCodes(): array;

}
