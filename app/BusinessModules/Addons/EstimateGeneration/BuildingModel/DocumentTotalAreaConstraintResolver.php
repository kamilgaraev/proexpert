<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentEvidencePolicy;

final readonly class DocumentTotalAreaConstraintResolver
{
    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array{total_area_m2: float, floor_count: int, sources: list<array{document_id: int, source_version: string, confidence: float}>}|null
     */
    public function resolve(array $documents): ?array
    {
        $sources = [];
        $areas = [];
        $floorCounts = [];
        foreach ($documents as $document) {
            $summary = is_array($document['facts_summary'] ?? null) ? $document['facts_summary'] : [];
            $hasAreaClaim = array_key_exists('total_area_m2', $summary) || array_key_exists('floor_count', $summary);
            if (! $hasAreaClaim) {
                continue;
            }
            $area = $summary['total_area_m2'] ?? null;
            $floorCount = filter_var($summary['floor_count'] ?? null, FILTER_VALIDATE_INT);
            $policyDocument = [
                'status' => $document['status'] ?? null,
                'quality' => ['level' => $document['quality_level'] ?? null],
                'facts_summary' => $summary,
            ];
            $documentId = filter_var($document['id'] ?? null, FILTER_VALIDATE_INT);
            $sourceVersion = $document['source_version'] ?? null;
            if (! DocumentEvidencePolicy::isTrusted($policyDocument)
                || ! DocumentEvidencePolicy::canUseQuantityEvidence($policyDocument)
                || ! is_numeric($area) || (float) $area <= 0 || ! is_finite((float) $area)
                || $floorCount === false || $floorCount < 1
                || $documentId === false || $documentId < 1
                || ! is_string($sourceVersion) || preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1) {
                return null;
            }
            $areas[] = round((float) $area, 6);
            $floorCounts[] = $floorCount;
            $sources[] = [
                'document_id' => $documentId,
                'source_version' => $sourceVersion,
                'confidence' => max(0.0, min(1.0, (float) ($document['quality_score'] ?? 0.0))),
            ];
        }
        if ($sources === []) {
            return null;
        }
        usort($sources, static fn (array $left, array $right): int => $left['document_id'] <=> $right['document_id']);
        $area = $areas[0];
        $floorCount = $floorCounts[0];
        foreach ($areas as $candidate) {
            if (abs($candidate - $area) > 0.01) {
                return null;
            }
        }
        foreach ($floorCounts as $candidate) {
            if ($candidate !== $floorCount) {
                return null;
            }
        }

        return ['total_area_m2' => $area, 'floor_count' => $floorCount, 'sources' => $sources];
    }

    /**
     * @param  array{total_area_m2: float, floor_count: int, sources: list<array{document_id: int, source_version: string, confidence: float}>}  $constraint
     * @param  array<string, mixed>  $evidence
     */
    public function matchesEvidence(array $constraint, array $evidence): bool
    {
        if (($evidence['invalidated_at'] ?? null) !== null
            || ($evidence['type'] ?? null) !== 'source_fact'
            || ($evidence['source_type'] ?? null) !== 'document'
            || ($evidence['producer_name'] ?? null) !== 'pipeline'
            || ($evidence['producer_version'] ?? null) !== 'pipeline:v2') {
            return false;
        }
        $locator = is_array($evidence['locator'] ?? null) ? $evidence['locator'] : [];
        $value = is_array($evidence['value'] ?? null) ? $evidence['value'] : [];
        $documentId = filter_var($locator['document_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = $value['fact_value'] ?? null;
        if ($documentId === false || ! is_numeric($amount)
            || ($value['fact_key'] ?? null) !== 'area' || ($value['unit'] ?? null) !== 'm2'
            || abs((float) $amount - $constraint['total_area_m2']) > 0.01) {
            return false;
        }
        foreach ($constraint['sources'] as $source) {
            if ($source['document_id'] === $documentId
                && is_string($evidence['source_version'] ?? null)
                && hash_equals($source['source_version'], $evidence['source_version'])) {
                return true;
            }
        }

        return false;
    }
}
