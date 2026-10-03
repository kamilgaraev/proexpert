<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

interface PublishedReportDefinitionCatalog extends ReportDefinitionRegistry
{
    public function publishedDefinitions(): array;
}
