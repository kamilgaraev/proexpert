<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;

final readonly class VisionAnalysisData
{
    public const SCHEMA_VERSION = 1;

    private const SHEET_TYPES = ['floor_plan', 'elevation', 'section', 'detail', 'site_plan', 'schedule', 'sketch', 'photo', 'unknown'];

    private const WARNINGS = ['scale_missing', 'scale_conflict', 'low_confidence', 'perspective_confirmation_required', 'geometry_incomplete', 'text_uncertain'];

    /** @param list<VisionEvidenceData> $evidence @param list<VisionElementData> $elements @param list<VisionScaleCandidateData> $scaleCandidates @param list<string> $warnings */
    public function __construct(
        public string $sheetType,
        public array $evidence,
        public array $elements,
        public array $scaleCandidates,
        public array $warnings,
        public string $provider,
        public string $requestedModel,
        public string $reportedModel,
        public string $modelVersion,
        public string $usageStatus,
        public ?int $inputTokens,
        public ?int $outputTokens,
    ) {
        if (! in_array($sheetType, self::SHEET_TYPES, true) || $evidence === [] || count($evidence) > 256 || count($elements) > 500 || count($scaleCandidates) > 32
            || array_diff($warnings, self::WARNINGS) !== [] || count($warnings) !== count(array_unique($warnings))
            || preg_match('/^[a-z0-9._-]{1,80}$/', $provider) !== 1
            || preg_match('#^[A-Za-z0-9._/-]{1,160}$#', $requestedModel) !== 1 || $reportedModel !== $requestedModel
            || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $modelVersion) !== 1
            || ! in_array($usageStatus, ['measured', 'unavailable'], true)
            || ($usageStatus === 'unavailable') !== ($inputTokens === null && $outputTokens === null)
            || ($inputTokens !== null && $inputTokens < 0) || ($outputTokens !== null && $outputTokens < 0)) {
            throw new VisionContractException('invalid_analysis_metadata');
        }
        $evidenceKeys = array_map(static fn (VisionEvidenceData $item): string => $item->key, $evidence);
        $elementKeys = array_map(static fn (VisionElementData $item): string => $item->key, $elements);
        if (count($evidenceKeys) !== count(array_unique($evidenceKeys)) || count($elementKeys) !== count(array_unique($elementKeys))) {
            throw new VisionContractException('duplicate_keys');
        }
        foreach ([...$elements, ...$scaleCandidates] as $item) {
            if (! in_array($item->evidenceRef, $evidenceKeys, true)) {
                throw new VisionContractException('dangling_evidence');
            }
        }
        $hasScaleMissing = in_array('scale_missing', $warnings, true);
        $hasScaleConflict = in_array('scale_conflict', $warnings, true);
        if (($scaleCandidates === []) !== $hasScaleMissing) {
            throw new VisionContractException('scale_missing_warning_mismatch');
        }
        $materialConflict = false;
        if (count($scaleCandidates) > 1) {
            $scaleValues = array_map(static fn (VisionScaleCandidateData $item): float => $item->metersPerUnit, $scaleCandidates);
            for ($left = 0; $left < count($scaleValues) && ! $materialConflict; $left++) {
                for ($right = $left + 1; $right < count($scaleValues); $right++) {
                    $a = $scaleValues[$left];
                    $b = $scaleValues[$right];
                    if (abs($a - $b) > max(1.0e-9, 0.02 * min($a, $b))) {
                        $materialConflict = true;
                        break;
                    }
                }
            }
        }
        if ($materialConflict !== $hasScaleConflict) {
            throw new VisionContractException('unreported_scale_conflict');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromProviderArray(array $data, string $provider, string $requestedModel, string $reportedModel, string $modelVersion, string $usageStatus, ?int $inputTokens, ?int $outputTokens, int $maxElements): self
    {
        if (! self::hasExactKeys($data, ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings'])
            || $data['schema_version'] !== self::SCHEMA_VERSION || ! is_string($data['sheet_type'])
            || ! is_array($data['evidence']) || ! is_array($data['elements']) || ! is_array($data['scale_candidates']) || ! is_array($data['warnings'])
            || count($data['elements']) > $maxElements) {
            throw new VisionContractException('invalid_analysis_schema');
        }
        $evidence = array_map(static fn (mixed $item): VisionEvidenceData => is_array($item) ? VisionEvidenceData::fromArray($item) : throw new VisionContractException('invalid_evidence'), $data['evidence']);
        $elements = array_map(static fn (mixed $item): VisionElementData => is_array($item) ? VisionElementData::fromArray($item) : throw new VisionContractException('invalid_element'), $data['elements']);
        $scales = array_map(static fn (mixed $item): VisionScaleCandidateData => is_array($item) ? VisionScaleCandidateData::fromArray($item) : throw new VisionContractException('invalid_scale_candidate'), $data['scale_candidates']);
        foreach ($data['warnings'] as $warning) {
            if (! is_string($warning)) {
                throw new VisionContractException('invalid_warning');
            }
        }

        return new self($data['sheet_type'], $evidence, $elements, $scales, array_values($data['warnings']), $provider, $requestedModel, $reportedModel, $modelVersion, $usageStatus, $inputTokens, $outputTokens);
    }

    public function mapPolygonsToSource(ProjectiveTransformData $transform): self
    {
        $mapped = array_map(static function (VisionElementData $element) use ($transform): VisionElementData {
            $polygon = array_map($transform->toSource(...), $element->polygon);

            return new VisionElementData($element->key, $element->type, $element->label, $polygon, $element->confidence, $element->evidenceRef, $element->geometry);
        }, $this->elements);

        $evidence = array_map(static fn (VisionEvidenceData $item): VisionEvidenceData => $item->toSourceSpace(), $this->evidence);

        return new self(
            $this->sheetType, $evidence, $mapped, $this->scaleCandidates, $this->warnings,
            $this->provider, $this->requestedModel, $this->reportedModel, $this->modelVersion,
            $this->usageStatus, $this->inputTokens, $this->outputTokens,
        );
    }

    public function assertProvenance(VisionDocumentInput $input, string $coordinateSpace): self
    {
        if ($this->evidence === []) {
            throw new VisionContractException('evidence_required');
        }
        foreach ($this->evidence as $evidence) {
            $evidence->assertMatches($input, $coordinateSpace);
        }

        return $this;
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }
}
