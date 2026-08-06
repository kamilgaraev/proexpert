<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ChangeClaimHistoryBoundary
{
    public function __construct(
        public DateTimeImmutable $completedAt,
        public int $changeRequestWatermarkId,
        public int $versionWatermarkId,
        public int $workflowEventWatermarkId,
        public int $claimLinkWatermarkId,
        public int $ledgerWatermarkId,
        public int $unprojectableLegacyCount,
        public string $sourceHash,
    ) {
        if ($changeRequestWatermarkId < 0
            || $versionWatermarkId < 0
            || $workflowEventWatermarkId < 0
            || $claimLinkWatermarkId < 0
            || $ledgerWatermarkId < 0
            || $unprojectableLegacyCount < 0
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
        ) {
            throw new InvalidArgumentException('change_claim_history_boundary_invalid');
        }
    }

    public function hasLegacyGaps(): bool
    {
        return $this->unprojectableLegacyCount > 0;
    }

    public function covers(DateTimeInterface $asOf): bool
    {
        return DateTimeImmutable::createFromInterface($asOf) >= $this->completedAt;
    }

    public function canonicalIdentity(): array
    {
        return [
            'completed_at' => $this->completedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'change_request_watermark_id' => $this->changeRequestWatermarkId,
            'version_watermark_id' => $this->versionWatermarkId,
            'workflow_event_watermark_id' => $this->workflowEventWatermarkId,
            'claim_link_watermark_id' => $this->claimLinkWatermarkId,
            'ledger_watermark_id' => $this->ledgerWatermarkId,
            'unprojectable_legacy_count' => $this->unprojectableLegacyCount,
            'source_hash' => $this->sourceHash,
        ];
    }
}
