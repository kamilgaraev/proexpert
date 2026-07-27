<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

interface ReportDefinitionRegistry
{
    public function published(string $code): PublishedReportDefinition;

    public function publishedCodes(): array;

    public function manifestSha256(): Sha256Hash;
}
