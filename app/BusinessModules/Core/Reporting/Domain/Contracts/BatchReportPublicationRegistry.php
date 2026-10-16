<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

interface BatchReportPublicationRegistry extends ReportPublicationRegistry
{
    public function currentMany(array $codes): array;
}
