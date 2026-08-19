<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class VisualArbitrationPolicy
{
    private const KNOWN_REASONS = [
        'accepted_fixture', 'conditional_furniture', 'conflicting_observation', 'evidence_required',
        'explicit_area', 'explicit_note', 'explicit_note_confirms_visual_observation',
        'explicit_note_over_visual_similarity', 'fixture_requires_specification',
        'foundation_condition_missing', 'manual_review_required', 'minority_evidence_preserved',
        'minority_without_explicit_source_evidence', 'missing_component', 'needs_confirmation',
        'quantity_unconfirmed', 'SCALE_CONFIRMED', 'source_conflict', 'three_observers_agree',
        'unique_professional_observation', 'unsafe_conflict', 'visible_fixture', 'visual_candidate',
        'visual_inventory_scope_confirmation', 'arbitration_status_unknown', 'arbitration_reason_unknown',
        'dimensioned_façade_evidence_missing', 'observer_claim_invalid', 'observer_claim_value_invalid',
        'observer_evidence_invalid', 'observer_scope_invalid', 'unrelated_evidence',
        'visual_object_evidence_invalid', 'visual_object_invalid',
    ];

    /** @param array<string, mixed> $decision @return array{status:string,reason_code:string,limitation_code?:string,supporting_claim_ids:list<string>,evidence_refs:list<string>} */
    public function normalize(array $decision): array
    {
        $rawStatus = is_string($decision['status'] ?? null) ? $decision['status'] : null;
        $status = $rawStatus === null ? null : VisualArbitrationStatus::tryFrom($rawStatus);
        if ($status === null) {
            return [
                'status' => VisualArbitrationStatus::Unresolved->value,
                'reason_code' => 'arbitration_status_unknown',
                'limitation_code' => 'arbitration_status_unknown',
                'supporting_claim_ids' => $this->strings($decision['supporting_claim_ids'] ?? null),
                'evidence_refs' => $this->strings($decision['evidence_refs'] ?? null),
            ];
        }

        $reason = is_string($decision['reason_code'] ?? null) ? $decision['reason_code'] : null;
        if (! $this->isKnownReason($reason)) {
            return [
                'status' => $status === VisualArbitrationStatus::Accepted
                    ? VisualArbitrationStatus::Conditional->value
                    : $status->value,
                'reason_code' => 'arbitration_reason_unknown',
                'limitation_code' => 'arbitration_reason_unknown',
                'supporting_claim_ids' => $this->strings($decision['supporting_claim_ids'] ?? null),
                'evidence_refs' => $this->strings($decision['evidence_refs'] ?? null),
            ];
        }

        $status = $this->statusRequiredByReason($status, $reason);
        $normalized = [
            'status' => $status->value,
            'reason_code' => $reason,
            'supporting_claim_ids' => $this->strings($decision['supporting_claim_ids'] ?? null),
            'evidence_refs' => $this->strings($decision['evidence_refs'] ?? null),
        ];
        if (is_string($decision['limitation_code'] ?? null)
            && in_array($decision['limitation_code'], [
                'arbitration_evidence_conflict',
                'arbitration_status_unknown',
                'arbitration_reason_unknown',
            ], true)) {
            $normalized['limitation_code'] = $decision['limitation_code'];
        }

        return $normalized;
    }

    /** @param list<array<string, mixed>> $decisions @return array{status:string,reason_code:string,limitation_code?:string,supporting_claim_ids:list<string>,evidence_refs:list<string>} */
    public function reduce(array $decisions): array
    {
        $normalized = array_map(fn (array $decision): array => $this->normalize($decision), $decisions);
        if ($normalized === []) {
            return $this->normalize([
                'status' => 'conditional',
                'reason_code' => 'minority_evidence_preserved',
            ]);
        }

        usort($normalized, function (array $left, array $right): int {
            $leftStatus = VisualArbitrationStatus::from($left['status']);
            $rightStatus = VisualArbitrationStatus::from($right['status']);
            $rank = $rightStatus->precedence() <=> $leftStatus->precedence();

            return $rank !== 0 ? $rank : $this->canonicalJson($left) <=> $this->canonicalJson($right);
        });
        $primary = $normalized[0];
        $statuses = array_values(array_unique(array_column($normalized, 'status')));
        $supportingClaimIds = [];
        $evidenceRefs = [];
        foreach ($normalized as $decision) {
            $supportingClaimIds = [...$supportingClaimIds, ...$decision['supporting_claim_ids']];
            $evidenceRefs = [...$evidenceRefs, ...$decision['evidence_refs']];
        }
        $primary['supporting_claim_ids'] = $this->uniqueSorted($supportingClaimIds);
        $primary['evidence_refs'] = $this->uniqueSorted($evidenceRefs);
        if (count($statuses) > 1) {
            $primary['limitation_code'] = 'arbitration_evidence_conflict';
        }

        return $primary;
    }

    private function isKnownReason(?string $reason): bool
    {
        return $reason !== null && (
            in_array($reason, self::KNOWN_REASONS, true)
            || preg_match('/^(?:arbiter_reason|canonical_consensus)_[0-9a-f]{16}$/D', $reason) === 1
        );
    }

    private function statusRequiredByReason(
        VisualArbitrationStatus $status,
        string $reason,
    ): VisualArbitrationStatus {
        $minimum = match ($reason) {
            'unsafe_conflict' => VisualArbitrationStatus::Rejected,
            'conflicting_observation' => VisualArbitrationStatus::Ambiguous,
            'source_conflict' => VisualArbitrationStatus::Unresolved,
            'needs_confirmation', 'minority_evidence_preserved',
            'minority_without_explicit_source_evidence' => VisualArbitrationStatus::Conditional,
            'fixture_requires_specification', 'visual_candidate' => VisualArbitrationStatus::Candidate,
            default => VisualArbitrationStatus::Accepted,
        };

        return $status->precedence() >= $minimum->precedence() ? $status : $minimum;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return $this->uniqueSorted(array_values(array_filter(is_array($value) ? $value : [], 'is_string')));
    }

    /** @param list<string> $values @return list<string> */
    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private function canonicalJson(array $value): string
    {
        ksort($value, SORT_STRING);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
