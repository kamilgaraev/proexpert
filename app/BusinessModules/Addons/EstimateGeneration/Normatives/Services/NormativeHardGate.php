<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\RejectedNormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

final class NormativeHardGate
{
    /** @param list<NormativeCandidateData> $candidates */
    public function filter(WorkIntentData $workItem, array $candidates): NormativeCandidateSetData
    {
        $accepted = [];
        $rejected = [];
        foreach ($candidates as $candidate) {
            $reasons = $this->reasons($workItem, $candidate);
            if ($reasons === []) {
                $accepted[] = $candidate;

                continue;
            }
            $rejected[] = new RejectedNormativeCandidateData($candidate, $reasons, [
                'work_item' => $workItem->sourceEvidence,
                'candidate' => $candidate->sourceEvidence,
            ]);
        }

        return new NormativeCandidateSetData(
            $workItem->organizationId, $workItem->projectId, $workItem->sessionId, $workItem->workItemId,
            $workItem->datasetVersion, NormativeScoring::VERSION,
            $accepted[0]->semanticIndexVersion ?? null, $accepted, $rejected,
            $accepted === [] ? 'review_required' : 'retrieval_only',
            $accepted === [] ? ['normative_not_found'] : [],
        );
    }

    /** @return list<string> */
    private function reasons(WorkIntentData $intent, NormativeCandidateData $candidate): array
    {
        $reasons = [];
        if ($candidate->canonicalUnit === null || $candidate->canonicalUnit === '') {
            $reasons[] = 'unit_unknown';
        } elseif (! NormativeUnitNormalizer::compatible($intent->canonicalUnit, $candidate->canonicalUnit)) {
            $reasons[] = 'unit_mismatch';
        }
        if ($candidate->unitDimension !== null && $candidate->unitDimension !== ''
            && $intent->unitDimension !== '' && $candidate->unitDimension !== $intent->unitDimension) {
            $reasons[] = 'unit_dimension_mismatch';
        }
        foreach ([
            ['material', 'material'], ['technology', 'technology'], ['structure', 'structure'],
        ] as [$property, $code]) {
            if ($candidate->{$property} !== null && $candidate->{$property} !== ''
                && $intent->{$property} !== '' && $candidate->{$property} !== $intent->{$property}) {
                $reasons[] = $code.'_mismatch';
            }
        }
        if ($candidate->normativeSection !== null && $candidate->normativeSection !== ''
            && $intent->normativeSection !== ''
            && ! $this->sectionCompatible($candidate->normativeSection, $intent->normativeSection)) {
            $reasons[] = 'normative_section_mismatch';
        }
        if ($candidate->objectType !== null && $candidate->objectType !== '' && $intent->objectType !== ''
            && ! ObjectTypeSignalClassifier::compatible($candidate->objectType, $intent->objectType)) {
            $reasons[] = 'object_type_mismatch';
        }
        foreach ([['datasetVersion', 'dataset_version'], ['datasetStatus', 'dataset_status']] as [$property, $code]) {
            if ($candidate->{$property} === '') {
                $reasons[] = $code.'_unknown';
            } elseif ($candidate->{$property} !== $intent->{$property}) {
                $reasons[] = $code.'_mismatch';
            }
        }
        if ($intent->regionCode !== null && $candidate->regionCode !== null && $candidate->regionCode !== $intent->regionCode) {
            $reasons[] = 'region_mismatch';
        }
        if (($candidate->validFrom !== null && $candidate->validFrom > $intent->applicabilityDate)
            || ($candidate->validTo !== null && $candidate->validTo < $intent->applicabilityDate)) {
            $reasons[] = 'applicability_date_mismatch';
        }

        return $reasons;
    }

    private function sectionCompatible(string $candidate, string $preferred): bool
    {
        if (str_starts_with($candidate, $preferred)) {
            return true;
        }
        $candidateParts = array_values(array_map('intval', array_filter(
            preg_split('/\D+/', $candidate) ?: [],
            static fn (string $part): bool => $part !== '',
        )));
        $preferredParts = array_values(array_map('intval', array_filter(
            preg_split('/\D+/', $preferred) ?: [],
            static fn (string $part): bool => $part !== '',
        )));

        return $preferredParts !== []
            && count($candidateParts) >= count($preferredParts)
            && array_slice($candidateParts, 0, count($preferredParts)) === $preferredParts;
    }
}
