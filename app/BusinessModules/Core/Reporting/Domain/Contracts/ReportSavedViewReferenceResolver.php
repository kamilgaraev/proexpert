<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;

interface ReportSavedViewReferenceResolver
{
    public function resolve(ReportExecutionContext $context, string $savedViewId): ReportSavedViewRef;

    public function assertCurrent(ReportExecutionContext $context, ReportSavedViewRef $reference): void;
}
