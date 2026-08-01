<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;

interface ReportCatalogMetadataRegistry
{
    public function published(string $code): ReportCatalogMetadata;
}
