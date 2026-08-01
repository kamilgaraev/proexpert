<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

final readonly class SourceWriteReceipt
{
    public function __construct(
        public int|string $entityId,
        public int $revisionNumber,
        public string $contentHash,
        public bool $replay,
    ) {}
}
