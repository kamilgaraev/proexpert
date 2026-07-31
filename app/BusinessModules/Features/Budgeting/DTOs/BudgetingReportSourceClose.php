<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use DateTimeImmutable;

final readonly class BudgetingReportSourceClose
{
    /** @param list<BudgetingReportSourceWatermark> $sourceWatermarks */
    public function __construct(
        public string $closeId,
        public BudgetingReportSourceCloseIdentity $identity,
        public array $sourceWatermarks,
        public string $formulaVersion,
        public array $sourceManifest,
        public string $contentHash,
        public int $approvedBy,
        public DateTimeImmutable $approvedAt,
        public DateTimeImmutable $retainedUntil,
        public BudgetingReportSourceCloseStatus $status,
        public ?string $restatesCloseId,
    ) {
    }

    public function isAvailableAt(DateTimeImmutable $at): bool
    {
        return $this->status->isAvailableForReporting()
            && $this->retainedUntil > $at;
    }
}
