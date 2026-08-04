<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationFeatureConfiguration;

interface ReportPublicationFeatureStore
{
    public function current(string $code): ?ReportPublicationFeatureConfiguration;

}
