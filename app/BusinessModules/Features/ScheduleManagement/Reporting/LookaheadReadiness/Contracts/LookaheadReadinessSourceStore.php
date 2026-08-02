<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\AuthorizationDecision;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\PublishedCommitment;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\SourceWriteReceipt;

interface LookaheadReadinessSourceStore
{
    public function transaction(callable $operation): mixed;

    public function approveScheduleRevision(
        ScheduleRevisionDraft $draft,
        string $contentHash,
        AuthorizationDecision $authorizationDecision,
        string $approvedAtUtc,
        string $idempotencyKey,
    ): SourceWriteReceipt;

    public function publishPolicy(
        ReadinessPolicyDefinition $policy,
        AuthorizationDecision $authorizationDecision,
        string $idempotencyKey,
    ): SourceWriteReceipt;

    public function transitionScheduleRevision(
        int $scheduleRevisionId,
        int $organizationId,
        int $projectId,
        string $targetState,
        string $effectiveAtUtc,
        AuthorizationDecision $authorizationDecision,
        string $idempotencyKey,
    ): SourceWriteReceipt;

    public function publishCommitment(
        PublishedCommitment $commitment,
        int $scheduleRevisionId,
        int $policyId,
        AuthorizationDecision $authorizationDecision,
        string $idempotencyKey,
    ): SourceWriteReceipt;

    public function appendEvent(ReadinessEvent $event, AuthorizationDecision $authorizationDecision): SourceWriteReceipt;

    public function materializeReadiness(array $command, AuthorizationDecision $authorizationDecision): SourceWriteReceipt;
}
