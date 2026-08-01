<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\PublishedCommitment;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\SourceWriteReceipt;

interface LookaheadReadinessSourceStore
{
    public function transaction(callable $operation): mixed;

    public function approveScheduleRevision(
        ScheduleRevisionDraft $draft,
        string $contentHash,
        int $actorId,
        string $approvedAtUtc,
        string $idempotencyKey,
    ): SourceWriteReceipt;

    public function publishPolicy(
        ReadinessPolicyDefinition $policy,
        int $actorId,
        string $idempotencyKey,
    ): SourceWriteReceipt;

    public function publishCommitment(
        PublishedCommitment $commitment,
        int $scheduleRevisionId,
        int $policyId,
        string $idempotencyKey,
    ): SourceWriteReceipt;

    public function appendEvent(ReadinessEvent $event): SourceWriteReceipt;

    public function sealSnapshot(ReadinessSnapshot $snapshot, string $idempotencyKey): SourceWriteReceipt;
}
