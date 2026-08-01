<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessAuthorizer;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessSourceStore;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\CommitmentDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\SourceWriteReceipt;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessEventType;
use DateTimeImmutable;
use DateTimeZone;

final readonly class LookaheadReadinessOwnerWriter
{
    public function __construct(
        private LookaheadReadinessSourceStore $store,
        private LookaheadReadinessAuthorizer $authorizer,
        private ScheduleRevisionFactory $scheduleRevisionFactory,
        private CommitmentFactory $commitmentFactory = new CommitmentFactory,
    ) {}

    public function approveScheduleRevision(
        ScheduleRevisionDraft $draft,
        int $actorId,
        DateTimeImmutable $approvedAt,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->authorizer->assertAllowed(
            $actorId,
            LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION,
            $draft->organizationId,
            $draft->projectId,
        );
        $contentHash = $this->scheduleRevisionFactory->contentHash($draft);
        $approvedAtUtc = $approvedAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');

        return $this->store->transaction(fn (): SourceWriteReceipt => $this->store->approveScheduleRevision(
            $draft,
            $contentHash,
            $actorId,
            $approvedAtUtc,
            $idempotencyKey,
        ));
    }

    public function publishPolicy(
        ReadinessPolicyDefinition $policy,
        int $actorId,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->authorizer->assertAllowed(
            $actorId,
            LookaheadReadinessAbility::PUBLISH_POLICY,
            $policy->organizationId,
            0,
        );

        return $this->store->transaction(
            fn (): SourceWriteReceipt => $this->store->publishPolicy($policy, $actorId, $idempotencyKey),
        );
    }

    public function publishCommitment(
        CommitmentDraft $draft,
        ScheduleRevisionDraft $scheduleRevision,
        int $scheduleRevisionId,
        string $scheduleRevisionHash,
        ReadinessPolicyDefinition $policy,
        int $policyId,
        int $actorId,
        DateTimeImmutable $publishedAt,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->authorizer->assertAllowed(
            $actorId,
            LookaheadReadinessAbility::PUBLISH_COMMITMENT,
            $draft->organizationId,
            $draft->projectId,
        );
        $commitment = $this->commitmentFactory->publish(
            $draft,
            $scheduleRevision,
            $scheduleRevisionHash,
            $policy,
            $actorId,
            $publishedAt,
        );

        return $this->store->transaction(function () use (
            $commitment,
            $scheduleRevisionId,
            $policyId,
            $idempotencyKey,
            $policy,
            $publishedAt,
        ): SourceWriteReceipt {
            $receipt = $this->store->publishCommitment(
                $commitment,
                $scheduleRevisionId,
                $policyId,
                $idempotencyKey,
            );
            $eventIdempotencyKey = $idempotencyKey.':published-event';
            $event = ReadinessEvent::fromArray([
                'event_id' => LookaheadReadinessEventIdentity::fromIdempotency(
                    $commitment->organizationId,
                    $eventIdempotencyKey,
                ),
                'idempotency_key' => $eventIdempotencyKey,
                'organization_id' => $commitment->organizationId,
                'project_id' => $commitment->projectId,
                'schedule_id' => $commitment->scheduleId,
                'commitment_revision_id' => (int) $receipt->entityId,
                'commitment_task_id' => null,
                'event_type' => ReadinessEventType::COMMITMENT_PUBLISHED->value,
                'occurred_at' => $publishedAt->format(DateTimeImmutable::ATOM),
                'actor_id' => $commitment->publishedByUserId,
                'payload' => [
                    'commitment_content_hash' => $commitment->contentHash,
                    'policy_hash' => $commitment->policyHash,
                    'schedule_revision_hash' => $commitment->scheduleRevisionHash,
                    'task_count' => count($commitment->tasks),
                    'window_end' => $commitment->windowEnd,
                    'window_start' => $commitment->windowStart,
                ],
                'evidence' => null,
                'prior_event_id' => null,
            ], $policy);
            $this->store->appendEvent($event);

            return $receipt;
        });
    }

    public function appendEvent(ReadinessEvent $event): SourceWriteReceipt
    {
        $permission = match ($event->eventType) {
            ReadinessEventType::WAIVER_APPROVED => LookaheadReadinessAbility::APPROVE_WAIVER,
            ReadinessEventType::COMMITMENT_PUBLISHED,
            ReadinessEventType::COMMITMENT_SUPERSEDED,
            ReadinessEventType::COMMITMENT_WITHDRAWN => LookaheadReadinessAbility::PUBLISH_COMMITMENT,
            default => LookaheadReadinessAbility::MANAGE_CONSTRAINTS,
        };
        $this->authorizer->assertAllowed(
            $event->actorId,
            $permission,
            $event->organizationId,
            $event->projectId,
        );

        return $this->store->transaction(
            fn (): SourceWriteReceipt => $this->store->appendEvent($event),
        );
    }

    public function sealSnapshot(
        ReadinessSnapshot $snapshot,
        int $actorId,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->authorizer->assertAllowed(
            $actorId,
            LookaheadReadinessAbility::SEAL_EVALUATION,
            $snapshot->organizationId,
            $snapshot->projectId,
        );

        return $this->store->transaction(
            fn (): SourceWriteReceipt => $this->store->sealSnapshot($snapshot, $idempotencyKey),
        );
    }
}
