<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEventProjection;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use DateTimeImmutable;
use LogicException;

final class ReadinessEventStateMachine
{
    private const TRANSITIONS = [
        'constraint_registered' => [],
        'constraint_evidence_attached' => [
            'constraint_registered',
            'constraint_evidence_attached',
            'constraint_reopened',
        ],
        'constraint_resolved' => [
            'constraint_registered',
            'constraint_evidence_attached',
            'constraint_reopened',
        ],
        'constraint_reopened' => ['constraint_resolved'],
        'waiver_requested' => [],
        'waiver_approved' => ['waiver_requested'],
        'waiver_rejected' => ['waiver_requested'],
        'waiver_expired' => ['waiver_approved'],
        'waiver_revoked' => ['waiver_approved'],
    ];

    public function project(
        ReadinessPolicyDefinition $policy,
        string $taskClass,
        DateTimeImmutable $asOf,
        array $events,
        string $scheduleRevisionHash,
    ): ReadinessEventProjection {
        $required = $policy->requiredPrerequisites($taskClass);
        usort($events, static fn (array $left, array $right): int => [
            $left['occurred_at_utc'] ?? '',
            $left['event_id'] ?? '',
        ] <=> [
            $right['occurred_at_utc'] ?? '',
            $right['event_id'] ?? '',
        ]);

        $aggregates = [];
        $consumedEventIds = [];
        foreach ($events as $event) {
            $eventId = $event['event_id'] ?? null;
            $eventType = $event['event_type'] ?? null;
            $aggregateId = $event['aggregate_id'] ?? null;
            $occurredAt = $this->instant($event['occurred_at_utc'] ?? null);
            if (! is_string($eventId)
                || ! is_string($eventType)
                || ! is_string($aggregateId)
                || $occurredAt > $asOf) {
                throw new LogicException('lookahead_readiness_event_causality_invalid');
            }
            if ($eventType === 'readiness_evaluated') {
                continue;
            }
            if (! array_key_exists($eventType, self::TRANSITIONS)) {
                throw new LogicException('lookahead_readiness_event_transition_invalid');
            }
            $expectedPrefix = str_starts_with($eventType, 'constraint_') ? 'constraint:' : 'waiver:';
            if (! str_starts_with($aggregateId, $expectedPrefix)) {
                throw new LogicException('lookahead_readiness_event_transition_invalid');
            }

            $payload = $event['payload'] ?? null;
            $category = is_array($payload) ? ($payload['category'] ?? null) : null;
            if (! is_string($category) || ! isset($required[$category])) {
                throw new LogicException('lookahead_readiness_event_transition_invalid');
            }
            $previous = $aggregates[$aggregateId] ?? null;
            $priorEventId = $event['prior_event_id'] ?? null;
            if ($previous === null) {
                if (self::TRANSITIONS[$eventType] !== [] || $priorEventId !== null) {
                    throw new LogicException('lookahead_readiness_event_transition_invalid');
                }
            } elseif (! in_array($previous['event_type'], self::TRANSITIONS[$eventType], true)
                || $priorEventId !== $previous['event_id']
                || $category !== $previous['category']
                || [$event['occurred_at_utc'], $eventId] <= [$previous['occurred_at_utc'], $previous['event_id']]) {
                throw new LogicException('lookahead_readiness_event_transition_invalid');
            }
            if ($eventType === 'constraint_evidence_attached' && ! $this->validEvidence($policy, $event['evidence'] ?? null)) {
                throw new LogicException('lookahead_readiness_event_evidence_invalid');
            }
            if ($eventType === 'waiver_approved'
                && (! $this->validEvidence($policy, $event['evidence'] ?? null)
                    || (($event['authorization_decision']['permission'] ?? null)
                        !== $policy->waiverPolicy()['approver_permission']))) {
                throw new LogicException('lookahead_readiness_waiver_invalid');
            }

            $aggregates[$aggregateId] = [
                ...$event,
                'category' => $category,
                'event_type' => $eventType,
                'evidence' => $event['evidence'] ?? ($previous['evidence'] ?? null),
            ];
            $consumedEventIds[] = $eventId;
        }

        $components = [];
        $blockerEventIds = [];
        $waiverEventIds = [];
        foreach ($required as $category => $rule) {
            $categoryConstraints = array_values(array_filter(
                $aggregates,
                static fn (array $aggregate, string $aggregateId): bool => str_starts_with($aggregateId, 'constraint:')
                    && $aggregate['category'] === $category,
                ARRAY_FILTER_USE_BOTH,
            ));
            $categoryWaivers = array_values(array_filter(
                $aggregates,
                static fn (array $aggregate, string $aggregateId): bool => str_starts_with($aggregateId, 'waiver:')
                    && $aggregate['category'] === $category,
                ARRAY_FILTER_USE_BOTH,
            ));
            $validWaiver = $this->latestValidWaiver(
                $policy,
                $categoryWaivers,
                $asOf,
                $scheduleRevisionHash,
            );
            if ($validWaiver !== null) {
                $waiverEventIds[] = $validWaiver['event_id'];
                $components[$category] = [
                    'category' => $category,
                    'outcome' => 'waived',
                    'waiver_event_id' => $validWaiver['event_id'],
                    'authorization_decision_valid' => true,
                    'valid_until' => $validWaiver['payload']['valid_until'],
                    'schedule_revision_hash' => $scheduleRevisionHash,
                    'revoked' => false,
                    'evidence_type' => $validWaiver['evidence']['type'],
                    'evidence_hash' => $validWaiver['evidence']['hash'],
                ];

                continue;
            }

            $openConstraints = array_values(array_filter(
                $categoryConstraints,
                static fn (array $constraint): bool => in_array($constraint['event_type'], [
                    'constraint_registered',
                    'constraint_evidence_attached',
                    'constraint_reopened',
                ], true),
            ));
            if ($openConstraints !== []) {
                foreach ($openConstraints as $constraint) {
                    $blockerEventIds[] = $constraint['event_id'];
                }
                $components[$category] = [
                    'category' => $category,
                    'outcome' => 'unsatisfied',
                ];

                continue;
            }

            $resolved = array_values(array_filter(
                $categoryConstraints,
                static fn (array $constraint): bool => $constraint['event_type'] === 'constraint_resolved',
            ));
            if ($resolved !== []) {
                $last = $resolved[array_key_last($resolved)];
                $evidence = $last['evidence'] ?? null;
                if (($rule['evidence_required'] ?? false) && ! $this->validEvidence($policy, $evidence)) {
                    $components[$category] = ['category' => $category, 'outcome' => 'unknown'];
                } else {
                    $components[$category] = [
                        'category' => $category,
                        'outcome' => 'satisfied',
                        'evidence_type' => $evidence['type'] ?? null,
                        'evidence_hash' => $evidence['hash'] ?? null,
                    ];
                }

                continue;
            }

            $absence = $rule['absence'] ?? 'unknown';
            $components[$category] = [
                'category' => $category,
                'outcome' => match ($absence) {
                    'blocked' => 'unsatisfied',
                    'not_applicable' => 'not_applicable',
                    default => 'unknown',
                },
            ];
        }

        return new ReadinessEventProjection(
            $components,
            $consumedEventIds,
            array_values(array_unique($blockerEventIds)),
            array_values(array_unique($waiverEventIds)),
            hash('sha256', implode("\n", $consumedEventIds)),
        );
    }

    private function latestValidWaiver(
        ReadinessPolicyDefinition $policy,
        array $waivers,
        DateTimeImmutable $asOf,
        string $scheduleRevisionHash,
    ): ?array {
        $waiverPolicy = $policy->waiverPolicy();
        for ($index = count($waivers) - 1; $index >= 0; $index--) {
            $waiver = $waivers[$index];
            $validUntil = $this->instant($waiver['payload']['valid_until'] ?? null, false);
            if ($waiver['event_type'] === 'waiver_approved'
                && $validUntil instanceof DateTimeImmutable
                && $asOf < $validUntil
                && ($waiver['payload']['schedule_revision_hash'] ?? null) === $scheduleRevisionHash
                && (($waiver['authorization_decision']['permission'] ?? null)
                    === $waiverPolicy['approver_permission'])
                && $this->validEvidence($policy, $waiver['evidence'] ?? null)) {
                return $waiver;
            }
        }

        return null;
    }

    private function validEvidence(ReadinessPolicyDefinition $policy, mixed $evidence): bool
    {
        return is_array($evidence)
            && in_array($evidence['type'] ?? null, $policy->evidenceTypes(), true)
            && is_string($evidence['locator'] ?? null)
            && $evidence['locator'] !== ''
            && is_string($evidence['version'] ?? null)
            && $evidence['version'] !== ''
            && preg_match('/^[a-f0-9]{64}$/D', $evidence['hash'] ?? '') === 1;
    }

    private function instant(mixed $value, bool $throw = true): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            if ($throw) {
                throw new LogicException('lookahead_readiness_event_causality_invalid');
            }

            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            if ($throw) {
                throw new LogicException('lookahead_readiness_event_causality_invalid');
            }

            return null;
        }
    }
}
