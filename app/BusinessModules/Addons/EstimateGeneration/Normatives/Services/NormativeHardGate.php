<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\RejectedNormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;

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
        $checks = [
            ['canonicalUnit', 'unit'], ['unitDimension', 'unit_dimension'], ['material', 'material'],
            ['technology', 'technology'], ['structure', 'structure'], ['normativeSection', 'normative_section'],
            ['objectType', 'object_type'], ['datasetVersion', 'dataset_version'], ['datasetStatus', 'dataset_status'],
        ];
        $reasons = [];
        foreach ($checks as [$property, $code]) {
            if ($candidate->{$property} === null || $candidate->{$property} === '') {
                $reasons[] = $code.'_unknown';
            } elseif ($candidate->{$property} !== $intent->{$property}) {
                $reasons[] = $code.'_mismatch';
            }
        }
        if ($intent->regionCode !== null && $candidate->regionCode === null) {
            $reasons[] = 'region_unknown';
        } elseif ($intent->regionCode !== null && $candidate->regionCode !== $intent->regionCode) {
            $reasons[] = 'region_mismatch';
        }
        if ($candidate->validFrom === null) {
            $reasons[] = 'applicability_date_unknown';
        } elseif ($candidate->validFrom > $intent->applicabilityDate || ($candidate->validTo !== null && $candidate->validTo < $intent->applicabilityDate)) {
            $reasons[] = 'applicability_date_mismatch';
        }

        return $reasons;
    }
}
