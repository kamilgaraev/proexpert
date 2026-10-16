<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

interface BatchReportPublicationFeatureStore extends ReportPublicationFeatureStore
{
    public function currentMany(array $codes): array;
}
