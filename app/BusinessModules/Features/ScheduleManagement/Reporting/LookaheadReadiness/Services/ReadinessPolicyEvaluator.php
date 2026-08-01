<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvaluation;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessState;
use DateTimeImmutable;

final class ReadinessPolicyEvaluator
{
    public function evaluate(
        ReadinessPolicyDefinition $policy,
        string $taskClass,
        DateTimeImmutable $asOf,
        array $components,
        string $pinnedPolicyHash,
        string $scheduleRevisionHash,
    ): ReadinessEvaluation {
        $required = $policy->requiredPrerequisites($taskClass);
        $byCategory = [];
        $globalSchedulePinMismatch = false;
        $duplicateRequiredCategory = false;

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            if (isset($component['schedule_revision_hash'])
                && $component['schedule_revision_hash'] !== $scheduleRevisionHash) {
                $globalSchedulePinMismatch = true;
            }
            $category = $component['category'] ?? null;
            if (is_string($category) && isset($required[$category])) {
                if (isset($byCategory[$category])) {
                    $duplicateRequiredCategory = true;
                } else {
                    $byCategory[$category] = $component;
                }
            }
        }

        if (! hash_equals($policy->hash(), $pinnedPolicyHash) || $globalSchedulePinMismatch) {
            return new ReadinessEvaluation(
                ReadinessState::UNKNOWN,
                $this->unknownComponents($required, 'pin_mismatch'),
                ['policy_or_schedule_pin_mismatch'],
            );
        }
        if ($duplicateRequiredCategory) {
            return new ReadinessEvaluation(
                ReadinessState::UNKNOWN,
                $this->unknownComponents($required, 'contradictory_duplicate'),
                ['component_contradictory_or_duplicate'],
            );
        }

        $outcomes = [];
        $hasBlocked = false;
        $hasRisk = false;
        $hasUnknown = false;
        $hasNotApplicable = false;
        $reasons = [];

        foreach ($required as $category => $rule) {
            $component = $byCategory[$category] ?? null;
            $outcome = $component['outcome'] ?? 'missing';
            $normalized = ['category' => $category, 'outcome' => $outcome];

            if ($component === null) {
                $hasUnknown = true;
                $reasons[] = 'required_source_missing';
                $normalized['outcome'] = 'unknown';
            } elseif ($outcome === 'satisfied') {
                if (($rule['evidence_required'] ?? false)
                    && (! $this->validEvidence($policy, $component))) {
                    $hasUnknown = true;
                    $reasons[] = 'required_evidence_missing';
                    $normalized['outcome'] = 'unknown';
                }
            } elseif ($outcome === 'waived') {
                if (! $this->validWaiver($policy, $component, $asOf, $scheduleRevisionHash)) {
                    $hasBlocked = (bool) ($rule['hard'] ?? false) || $hasBlocked;
                    $hasRisk = ! ($rule['hard'] ?? false) || $hasRisk;
                    $reasons[] = 'waiver_invalid';
                    $normalized['outcome'] = 'unsatisfied';
                }
            } elseif ($outcome === 'unsatisfied') {
                $hasBlocked = (bool) ($rule['hard'] ?? false) || $hasBlocked;
                $hasRisk = ! ($rule['hard'] ?? false) || $hasRisk;
                $reasons[] = ($rule['hard'] ?? false)
                    ? 'hard_prerequisite_unsatisfied'
                    : 'soft_prerequisite_unsatisfied';
            } elseif ($outcome === 'expiring') {
                $hasRisk = true;
                $reasons[] = 'expiry_threshold_breached';
            } elseif ($outcome === 'not_applicable' && ($component['policy_declared'] ?? false) === true) {
                $hasNotApplicable = true;
                $normalized['policy_declared'] = true;
            } else {
                $hasUnknown = true;
                $reasons[] = 'component_contradictory_or_unknown';
                $normalized['outcome'] = 'unknown';
            }

            $outcomes[] = $normalized;
        }

        $state = match (true) {
            $hasUnknown => ReadinessState::UNKNOWN,
            $hasBlocked => ReadinessState::BLOCKED,
            $hasRisk => ReadinessState::AT_RISK,
            $hasNotApplicable => ReadinessState::NOT_APPLICABLE,
            default => ReadinessState::READY,
        };

        return new ReadinessEvaluation($state, $outcomes, array_values(array_unique($reasons)));
    }

    private function validEvidence(ReadinessPolicyDefinition $policy, array $component): bool
    {
        return in_array($component['evidence_type'] ?? null, $policy->evidenceTypes(), true)
            && is_string($component['evidence_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $component['evidence_hash']) === 1;
    }

    private function validWaiver(
        ReadinessPolicyDefinition $policy,
        array $component,
        DateTimeImmutable $asOf,
        string $scheduleRevisionHash,
    ): bool {
        $waiver = $policy->waiverPolicy();
        $validUntil = isset($component['valid_until']) && is_string($component['valid_until'])
            ? DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $component['valid_until'])
            : false;

        return in_array($component['category'] ?? null, $waiver['allowed_categories'], true)
            && ($component['approved_permission'] ?? null) === $waiver['approver_permission']
            && ($component['revoked'] ?? true) === false
            && $validUntil instanceof DateTimeImmutable
            && $validUntil >= $asOf
            && (($waiver['cross_schedule_revision'] ?? false)
                || ($component['schedule_revision_hash'] ?? null) === $scheduleRevisionHash)
            && $this->validEvidence($policy, $component)
            && is_string($component['waiver_event_id'] ?? null);
    }

    private function unknownComponents(array $required, string $reason): array
    {
        return array_map(
            static fn (string $category): array => [
                'category' => $category,
                'outcome' => 'unknown',
                'reason' => $reason,
            ],
            array_keys($required),
        );
    }
}
