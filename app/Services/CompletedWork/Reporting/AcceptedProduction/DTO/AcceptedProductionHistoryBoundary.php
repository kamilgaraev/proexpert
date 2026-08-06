<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AcceptedProductionHistoryBoundary
{
    public function __construct(
        public DateTimeImmutable $completedAt,
        public int $performanceActWatermarkId,
        public int $ownerVersionWatermarkId,
        public int $ownerMemberWatermarkId,
        public int $eventWatermarkId,
        public int $backfillLedgerWatermarkId,
        public string $sourceHash,
    ) {
        if ($performanceActWatermarkId < 0
            || $ownerVersionWatermarkId < 0
            || $ownerMemberWatermarkId < 0
            || $eventWatermarkId < 0
            || $backfillLedgerWatermarkId < 0
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
        ) {
            throw new InvalidArgumentException('accepted_production_history_boundary_invalid');
        }
    }

    public function coverageStartDay(DateTimeZone $timezone): string
    {
        return $this->completedAt
            ->setTimezone($timezone)
            ->setTime(0, 0)
            ->modify('+1 day')
            ->format('Y-m-d');
    }

    public function coversOwner(int $id, DateTimeInterface $effectiveAt): bool
    {
        return $id > $this->ownerVersionWatermarkId
            && DateTimeImmutable::createFromInterface($effectiveAt) >= $this->completedAt;
    }

    public function coversMember(int $id): bool
    {
        return $id > $this->ownerMemberWatermarkId;
    }

    public function coversEvent(int $id, DateTimeInterface $recognizedAt): bool
    {
        return $id > $this->eventWatermarkId
            && DateTimeImmutable::createFromInterface($recognizedAt) >= $this->completedAt;
    }

    public function coversLedger(int $id, DateTimeInterface $recordedAt): bool
    {
        return $id > $this->backfillLedgerWatermarkId
            && DateTimeImmutable::createFromInterface($recordedAt) >= $this->completedAt;
    }

    public function canonicalIdentity(): array
    {
        return [
            'completed_at' => $this->completedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'performance_act_watermark_id' => $this->performanceActWatermarkId,
            'owner_version_watermark_id' => $this->ownerVersionWatermarkId,
            'owner_member_watermark_id' => $this->ownerMemberWatermarkId,
            'event_watermark_id' => $this->eventWatermarkId,
            'backfill_ledger_watermark_id' => $this->backfillLedgerWatermarkId,
            'source_hash' => $this->sourceHash,
        ];
    }
}
