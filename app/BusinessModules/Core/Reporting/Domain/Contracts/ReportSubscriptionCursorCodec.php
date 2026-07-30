<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionCursor;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;

interface ReportSubscriptionCursorCodec
{
    public function encode(ReportExecutionContext $context, ReportSubscriptionCursor $cursor): string;

    public function decode(ReportExecutionContext $context, ?ReportSubscriptionStatus $expectedStatusFilter, string $cursor): ReportSubscriptionCursor;
}
