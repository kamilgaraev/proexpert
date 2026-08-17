<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
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

        $projected = $this->repairObserverRepresentation(
            $this->projectObserverEvidence($payload, $input),
        );
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
            'evidence' => is_array($projected['evidence'] ?? null) ? $projected['evidence'] : [],
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
            if (! is_array($item)) {
                continue;
            }
            $embeddedReference = $item['key'] ?? null;
            $localReference = is_string($embeddedReference)
                ? $embeddedReference
                : (! array_is_list($evidence) && is_string($outerKey) ? $outerKey : '');
            if (! array_is_list($evidence) && is_string($embeddedReference)
                && (! is_string($outerKey) || ! hash_equals($outerKey, $localReference))) {
                return $payload;
            }
            if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/D', $localReference) !== 1) {
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
            $locator = $this->locator($input);
            $providerLocator = is_array($item['locator'] ?? null) ? $item['locator'] : [];
            if (is_bool($providerLocator['explicit'] ?? null)) {
                $locator['explicit'] = $providerLocator['explicit'];
            }
            $projectedEvidence[] = ['key' => $serverReference, 'locator' => $locator];
        }
        $payload['evidence'] = $projectedEvidence;
        $analysis = $payload['project_sheet_analysis'] ?? null;
        if (is_array($analysis) && is_array($analysis['facts'] ?? null) && array_is_list($analysis['facts'])) {
            $analysis['facts'] = array_values(array_filter(
                $analysis['facts'],
                static function (mixed $fact) use ($references, $input): bool {
                    $reference = is_array($fact) ? ($fact['evidenceRef'] ?? null) : null;

                    return is_string($reference)
                        && (isset($references[$reference]) || in_array($reference, $input->nativeReferences, true));
                },
            ));
            $payload['project_sheet_analysis'] = $analysis;
        }

        return $this->replaceEvidenceReferences($payload, $references);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function repairObserverRepresentation(array $payload): array
    {
        $elements = $payload['elements'] ?? null;
        if (is_array($elements) && ! array_is_list($elements)) {
            $normalized = [];
            foreach ($elements as $outerKey => $element) {
                if (! is_array($element)) {
                    $normalized[] = $element;

                    continue;
                }
                $embeddedKey = $element['key'] ?? null;
                if (! is_string($embeddedKey) && is_string($outerKey)
                    && preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/D', $outerKey) === 1) {
                    $element['key'] = $outerKey;
                } elseif (is_string($embeddedKey) && (! is_string($outerKey) || ! hash_equals($outerKey, $embeddedKey))) {
                    $element['canonical_key_mismatch'] = true;
                }
                $normalized[] = $element;
            }
            $payload['elements'] = $normalized;
        }

        $analysis = $payload['project_sheet_analysis'] ?? null;
        if (! is_array($analysis) || ! is_array($analysis['facts'] ?? null)
            || ! array_is_list($analysis['facts']) || count($analysis['facts']) > 500) {
            return $payload;
        }
        if (! VisionAnalysisData::isSupportedSheetType($payload['sheet_type'] ?? null)) {
            $analysis['role'] = 'unknown';
        }
        foreach ($analysis['facts'] as $index => $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $value = $fact['value'] ?? null;
            if (! is_array($value)) {
                continue;
            }
            if (count($value) === 3 && array_diff(array_keys($value), ['type', 'data', 'unit']) === []) {
                $nestedUnit = $value['unit'];
                if (array_key_exists('unit', $fact) && $fact['unit'] !== $nestedUnit) {
                    continue;
                }
                unset($value['unit']);
                $fact['unit'] = $this->canonicalUnit($nestedUnit, $fact['factType'] ?? null);
            }
            if (count($value) === 2 && array_diff(array_keys($value), ['type', 'data']) === []
                && ($value['type'] ?? null) === 'number') {
                $canonicalDecimal = $this->canonicalDecimal($value['data'] ?? null);
                if ($canonicalDecimal !== null) {
                    $value['data'] = $canonicalDecimal;
                }
            }
            $fact['value'] = $value;
            $analysis['facts'][$index] = $fact;
        }
        $payload['project_sheet_analysis'] = $analysis;

        return $payload;
    }

    private function canonicalUnit(mixed $unit, mixed $factType): mixed
    {
        if (! is_string($unit)) {
            return $unit;
        }
        $normalized = mb_strtolower(trim($unit));
        if ($factType === 'level' && in_array($normalized, ['этаж', 'этажа', 'этажей', 'floor', 'floors'], true)) {
            return 'pcs';
        }

        return match ($normalized) {
            'м', 'm' => 'm',
            'м2', 'м²', 'm2', 'm²', 'кв. м' => 'm2',
            'м3', 'м³', 'm3', 'm³', 'куб. м' => 'm3',
            'шт', 'шт.', 'pcs' => 'pcs',
            'кг', 'kg' => 'kg',
            'т', 't' => 't',
            'ч', 'h' => 'h',
            default => $unit,
        };
    }

    private function canonicalDecimal(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }
        if (is_float($value) && ! is_finite($value)) {
            return null;
        }
        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        return is_string($encoded)
            && strlen($encoded) <= 80
            && preg_match('~^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$~D', $encoded) === 1
            && preg_match('~^-0(?:\.0+)?$~D', $encoded) !== 1
                ? $encoded
                : null;
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
