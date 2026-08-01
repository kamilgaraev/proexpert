<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

final readonly class ReportDispatchPublishSummary
{
    public function __construct(
        public int $scanned,
        public int $claimed,
        public int $published,
        public int $retryScheduled,
        public int $deadLettered,
        public int $skipped,
    ) {}
}
