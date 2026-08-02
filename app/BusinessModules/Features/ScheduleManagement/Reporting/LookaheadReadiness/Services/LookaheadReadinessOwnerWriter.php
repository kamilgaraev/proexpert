<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessAuthorizer;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessSourceStore;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\CommitmentDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
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
        $contentHash = $this->scheduleRevisionFactory->contentHash($draft);
        $approvedAtUtc = $approvedAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');

        return $this->store->transaction(function () use (
            $draft,
            $contentHash,
            $actorId,
            $approvedAtUtc,
            $idempotencyKey,
        ): SourceWriteReceipt {
            $authorizationDecision = $this->authorizer->authorize(
                $actorId,
                LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION,
                $draft->organizationId,
                $draft->projectId,
            );

            return $this->store->approveScheduleRevision(
                $draft,
                $contentHash,
                $authorizationDecision,
                $approvedAtUtc,
                $idempotencyKey,
            );
        });
    }

    public function publishPolicy(
        ReadinessPolicyDefinition $policy,
        int $actorId,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        return $this->store->transaction(function () use ($policy, $actorId, $idempotencyKey): SourceWriteReceipt {
            $authorizationDecision = $this->authorizer->authorize(
                $actorId,
                LookaheadReadinessAbility::PUBLISH_POLICY,
                $policy->organizationId,
                0,
            );

            return $this->store->publishPolicy($policy, $authorizationDecision, $idempotencyKey);
        });
    }

    public function transitionScheduleRevision(
        int $scheduleRevisionId,
        int $organizationId,
        int $projectId,
        string $targetState,
        DateTimeImmutable $effectiveAt,
        int $actorId,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $effectiveAtUtc = $effectiveAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');

        return $this->store->transaction(function () use (
            $scheduleRevisionId,
            $organizationId,
            $projectId,
            $targetState,
            $effectiveAtUtc,
            $actorId,
            $idempotencyKey,
        ): SourceWriteReceipt {
            $decision = $this->authorizer->authorize(
                $actorId,
                LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION,
                $organizationId,
                $projectId,
            );

            return $this->store->transitionScheduleRevision(
                $scheduleRevisionId,
                $organizationId,
                $projectId,
                $targetState,
                $effectiveAtUtc,
                $decision,
                $idempotencyKey,
            );
        });
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
            $authorizationDecision = $this->authorizer->authorize(
                $commitment->publishedByUserId,
                LookaheadReadinessAbility::PUBLISH_COMMITMENT,
                $commitment->organizationId,
                $commitment->projectId,
            );
            $receipt = $this->store->publishCommitment(
                $commitment,
                $scheduleRevisionId,
                $policyId,
                $authorizationDecision,
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
                'aggregate_id' => 'commitment:'.$receipt->entityId,
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
            $this->store->appendEvent($event, $authorizationDecision);

            return $receipt;
        });
    }

    public function appendEvent(ReadinessEvent $event): SourceWriteReceipt
    {
        if ($event->eventType === ReadinessEventType::READINESS_EVALUATED) {
            throw new \RuntimeException('lookahead_readiness_owner_materialization_required');
        }
        $permission = match ($event->eventType) {
            ReadinessEventType::WAIVER_APPROVED,
            ReadinessEventType::WAIVER_REJECTED,
            ReadinessEventType::WAIVER_REVOKED,
            ReadinessEventType::WAIVER_EXPIRED => LookaheadReadinessAbility::APPROVE_WAIVER,
            ReadinessEventType::COMMITMENT_PUBLISHED,
            ReadinessEventType::COMMITMENT_SUPERSEDED,
            ReadinessEventType::COMMITMENT_WITHDRAWN => LookaheadReadinessAbility::PUBLISH_COMMITMENT,
            default => LookaheadReadinessAbility::MANAGE_CONSTRAINTS,
        };

        return $this->store->transaction(function () use ($event, $permission): SourceWriteReceipt {
            $authorizationDecision = $this->authorizer->authorize(
                $event->actorId,
                $permission,
                $event->organizationId,
                $event->projectId,
            );

            return $this->store->appendEvent($event, $authorizationDecision);
        });
    }

    public function materializeReadiness(
        int $organizationId,
        int $projectId,
        int $scheduleId,
        int $commitmentRevisionId,
        int $commitmentTaskId,
        DateTimeImmutable $asOf,
        int $actorId,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $asOfUtc = $asOf
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');

        return $this->store->transaction(function () use (
            $organizationId,
            $projectId,
            $scheduleId,
            $commitmentRevisionId,
            $commitmentTaskId,
            $asOfUtc,
            $actorId,
            $idempotencyKey,
        ): SourceWriteReceipt {
            $authorizationDecision = $this->authorizer->authorize(
                $actorId,
                LookaheadReadinessAbility::SEAL_EVALUATION,
                $organizationId,
                $projectId,
            );

            return $this->store->materializeReadiness([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'schedule_id' => $scheduleId,
                'commitment_revision_id' => $commitmentRevisionId,
                'commitment_task_id' => $commitmentTaskId,
                'as_of_utc' => $asOfUtc,
                'actor_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
            ], $authorizationDecision);
        });
    }
}
