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
            && $this->approvedAt <= $at
            && $this->retainedUntil > $at;
    }

    public function snapshotWatermarks(): array
    {
        $watermarks = array_map(static fn (BudgetingReportSourceWatermark $watermark): array => $watermark->toArray(), $this->sourceWatermarks);
        usort($watermarks, static fn (array $left, array $right): int => $left['source'] <=> $right['source']);

        return [
            'close_id' => $this->closeId,
            'approved_at' => $this->approvedAt->format(DATE_ATOM),
            'retained_until' => $this->retainedUntil->format(DATE_ATOM),
            'identity' => $this->identity->toArray(),
            'formula_version' => $this->formulaVersion,
            'content_hash' => $this->contentHash,
            'source_manifest' => $this->sourceManifest,
            'source_watermarks' => $watermarks,
        ];
    }
}
