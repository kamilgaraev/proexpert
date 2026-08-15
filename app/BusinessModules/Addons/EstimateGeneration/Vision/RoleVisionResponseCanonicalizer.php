<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;

final class RoleVisionResponseCanonicalizer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalize(array $payload, VisionDocumentInput $input): RoleVisionResponseCanonicalization
    {
        $auxiliaryMetadata = $input->auxiliaryMetadata;
        $isObserver = isset($auxiliaryMetadata['observer']);
        $isArbitration = is_array($auxiliaryMetadata['arbitration'] ?? null);
        $isGeometry = is_array($auxiliaryMetadata['geometry_expert'] ?? null);
        if (! $isObserver && ! $isArbitration && ! $isGeometry) {
            return new RoleVisionResponseCanonicalization($payload);
        }

        if ($isArbitration || $isGeometry) {
            $facts = $payload['decisions']
                ?? $payload['interpretations']
                ?? ($payload['project_sheet_analysis']['facts'] ?? []);
            if (! is_array($facts) || ! array_is_list($facts) || count($facts) > 64) {
                $facts = [];
            }

            return new RoleVisionResponseCanonicalization([
                'schema_version' => 3,
                'sheet_type' => is_string($payload['sheet_type'] ?? null) ? $payload['sheet_type'] : 'unknown',
                'evidence' => [[
                    'key' => 'server_page_evidence',
                    'locator' => $this->locator($input),
                ]],
                'elements' => [],
                'scale_candidates' => [],
                'warnings' => ['scale_missing'],
                'visual_attributes' => [],
                'project_sheet_analysis' => [
                    'contractVersion' => ProjectSheetAnalysisData::CONTRACT_VERSION,
                    'role' => 'unknown',
                    'facts' => $facts,
                ],
            ]);
        }

        $projected = $this->projectObserverEvidence($payload, $input);
        $projectSheetAnalysis = is_array($projected['project_sheet_analysis'] ?? null)
            ? $projected['project_sheet_analysis']
            : [
                'contractVersion' => ProjectSheetAnalysisData::CONTRACT_VERSION,
                'role' => 'unknown',
                'facts' => [],
            ];
        $canonical = [
            'schema_version' => is_array($projected['analysis_routing'] ?? null) ? 4 : 3,
            'sheet_type' => is_string($projected['sheet_type'] ?? null) ? $projected['sheet_type'] : 'unknown',
            'evidence' => is_array($projected['evidence'] ?? null) && $projected['evidence'] !== []
                ? $projected['evidence']
                : [['key' => 'server_page_evidence', 'locator' => $this->locator($input)]],
            'elements' => is_array($projected['elements'] ?? null) ? $projected['elements'] : [],
            'scale_candidates' => is_array($projected['scale_candidates'] ?? null) ? $projected['scale_candidates'] : [],
            'warnings' => is_array($projected['warnings'] ?? null) ? $projected['warnings'] : [],
            'visual_attributes' => is_array($projected['visual_attributes'] ?? null) ? $projected['visual_attributes'] : [],
            'project_sheet_analysis' => $projectSheetAnalysis,
        ];
        if (is_array($projected['analysis_routing'] ?? null)) {
            $canonical['analysis_routing'] = $projected['analysis_routing'];
        }

        return new RoleVisionResponseCanonicalization($canonical);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function projectObserverEvidence(array $payload, VisionDocumentInput $input): array
    {
        $evidence = $payload['evidence'] ?? null;
        if (! is_array($evidence) || count($evidence) > 256) {
            return $payload;
        }
        $references = [];
        $projectedEvidence = [];
        foreach ($evidence as $outerKey => $item) {
            if (! is_array($item) || ! is_string($item['key'] ?? null)) {
                continue;
            }
            $localReference = trim($item['key']);
            if (! array_is_list($evidence) && (! is_string($outerKey) || ! hash_equals($outerKey, $localReference))) {
                return $payload;
            }
            if ($localReference === '' || mb_strlen($localReference) > 200) {
                continue;
            }
            if (isset($references[$localReference])) {
                continue;
            }
            $serverReference = 'evidence:'.substr(hash('sha256', implode('|', [
                $input->organizationId,
                $input->projectId,
                $input->sessionId,
                $input->documentId,
                $input->pageId,
                $input->processingUnitId,
                $input->sourceVersion,
                $localReference,
            ])), 0, 32);
            $references[$localReference] = $serverReference;
            $projectedEvidence[] = ['key' => $serverReference, 'locator' => $this->locator($input)];
        }
        $payload['evidence'] = $projectedEvidence;

        return $this->replaceEvidenceReferences($payload, $references);
    }

    /** @param array<string,string> $references */
    private function replaceEvidenceReferences(mixed $value, array $references, ?string $key = null): mixed
    {
        if (is_string($value) && in_array($key, ['evidence_ref', 'evidenceRef'], true)) {
            return $references[$value] ?? $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (in_array($key, ['evidence_refs', 'evidenceRefs'], true) && array_is_list($value)) {
            return array_map(
                static fn (mixed $reference): mixed => is_string($reference)
                    ? ($references[$reference] ?? $reference)
                    : $reference,
                $value,
            );
        }
        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = $this->replaceEvidenceReferences(
                $childValue,
                $references,
                is_string($childKey) ? $childKey : null,
            );
        }

        return $value;
    }

    /** @return array{page_id:int,page_number:int,processing_unit_id:int,source_version:string,coordinate_space:string} */
    private function locator(VisionDocumentInput $input): array
    {
        return [
            'page_id' => $input->pageId,
            'page_number' => $input->pageNumber,
            'processing_unit_id' => $input->processingUnitId,
            'source_version' => $input->sourceVersion,
            'coordinate_space' => 'normalized_derivative_v1',
        ];
    }
}
