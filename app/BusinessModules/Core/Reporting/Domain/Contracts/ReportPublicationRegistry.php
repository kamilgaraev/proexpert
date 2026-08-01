<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\EligibleReportPublication;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;

interface ReportPublicationRegistry
{
    public function current(string $code): ?PublishedReportDefinition;

    /** @return string[] */
    public function publishedCodes(): array;

    public function promote(EligibleReportPublication $publication): PublishedReportDefinition;

    public function disable(string $publicationId, string $reason, string $actorIdentity): void;

    public function history(string $code): iterable;
}
