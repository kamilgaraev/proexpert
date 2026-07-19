<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;

final readonly class VisionAnalysisData
{
    public const SCHEMA_VERSION = 1;

    public const CURRENT_SCHEMA_VERSION = 2;

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
        public array $visualAttributes = [],
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
        $evidenceByKey = array_combine($evidenceKeys, $evidence);
        $elementKeys = array_map(static fn (VisionElementData $item): string => $item->key, $elements);
        if (count($evidenceKeys) !== count(array_unique($evidenceKeys)) || count($elementKeys) !== count(array_unique($elementKeys))) {
            throw new VisionContractException('duplicate_keys');
        }
        foreach ([...$elements, ...$scaleCandidates] as $item) {
            if (! in_array($item->evidenceRef, $evidenceKeys, true)) {
                throw new VisionContractException('dangling_evidence');
            }
        }
        if ($visualAttributes !== []) {
            $roofType = $visualAttributes['roof_type'] ?? null;
            if (array_keys($visualAttributes) !== ['roof_type']
                || ! is_array($roofType)
                || array_keys($roofType) !== ['value', 'confidence', 'evidence_ref']
                || ! in_array($roofType['value'] ?? null, ['flat', 'pitched', 'gable', 'hip', 'unknown'], true)
                || (! is_float($roofType['confidence'] ?? null) && ! is_int($roofType['confidence'] ?? null))
                || ! is_finite((float) $roofType['confidence'])
                || (float) $roofType['confidence'] < 0
                || (float) $roofType['confidence'] > 1
                || ! is_string($roofType['evidence_ref'] ?? null)
                || ! in_array($roofType['evidence_ref'], $evidenceKeys, true)) {
                throw new VisionContractException('invalid_visual_attributes');
            }
        }
        foreach ($elements as $element) {
            $space = $evidenceByKey[$element->evidenceRef]->locator['coordinate_space'];
            if (str_starts_with($space, 'normalized_')) {
                foreach ($element->polygon as $point) {
                    if ($point[0] > 1.0 || $point[1] > 1.0) {
                        throw new VisionContractException('invalid_normalized_polygon');
                    }
                }
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
        $schemaVersion = $data['schema_version'] ?? null;
        $expectedKeys = $schemaVersion === self::CURRENT_SCHEMA_VERSION
            ? ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings', 'visual_attributes']
            : ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings'];
        if (! self::hasExactKeys($data, $expectedKeys)
            || ! in_array($schemaVersion, [self::SCHEMA_VERSION, self::CURRENT_SCHEMA_VERSION], true)
            || ! is_string($data['sheet_type'])
            || ! is_array($data['evidence']) || ! is_array($data['elements']) || ! is_array($data['scale_candidates']) || ! is_array($data['warnings'])
            || ($schemaVersion === self::CURRENT_SCHEMA_VERSION && ! is_array($data['visual_attributes']))
            || count($data['elements']) > $maxElements) {
            throw new VisionContractException('invalid_analysis_schema');
        }
        $evidencePayload = self::normalizeEvidencePayload($data['evidence']);
        $evidence = array_map(static fn (mixed $item): VisionEvidenceData => is_array($item) ? VisionEvidenceData::fromArray($item) : throw new VisionContractException('invalid_evidence'), $evidencePayload);
        $elements = array_map(static fn (mixed $item): VisionElementData => is_array($item) ? VisionElementData::fromArray($item) : throw new VisionContractException('invalid_element'), $data['elements']);
        $scales = array_map(static fn (mixed $item): VisionScaleCandidateData => is_array($item) ? VisionScaleCandidateData::fromArray($item) : throw new VisionContractException('invalid_scale_candidate'), $data['scale_candidates']);
        foreach ($data['warnings'] as $warning) {
            if (! is_string($warning)) {
                throw new VisionContractException('invalid_warning');
            }
        }

        return new self(
            $data['sheet_type'],
            $evidence,
            $elements,
            $scales,
            array_values($data['warnings']),
            $provider,
            $requestedModel,
            $reportedModel,
            $modelVersion,
            $usageStatus,
            $inputTokens,
            $outputTokens,
            is_array($data['visual_attributes'] ?? null) ? $data['visual_attributes'] : [],
        );
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
            $this->visualAttributes,
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

    public function toArray(): array
    {
        $payload = [
            'schema_version' => $this->visualAttributes === [] ? self::SCHEMA_VERSION : self::CURRENT_SCHEMA_VERSION,
            'sheet_type' => $this->sheetType,
            'evidence' => array_map(static fn (VisionEvidenceData $item): array => $item->toArray(), $this->evidence),
            'elements' => array_map(static fn (VisionElementData $item): array => $item->toArray(), $this->elements),
            'scale_candidates' => array_map(static fn (VisionScaleCandidateData $item): array => $item->toArray(), $this->scaleCandidates),
            'warnings' => $this->warnings,
            'provider' => $this->provider,
            'requested_model' => $this->requestedModel,
            'reported_model' => $this->reportedModel,
            'model_version' => $this->modelVersion,
            'usage' => ['status' => $this->usageStatus, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens],
        ];
        if ($this->visualAttributes !== []) {
            $payload['visual_attributes'] = $this->visualAttributes;
        }

        return $payload;
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }

    /** @param array<mixed> $evidence @return array<mixed> */
    private static function normalizeEvidencePayload(array $evidence): array
    {
        if (array_is_list($evidence)) {
            return $evidence;
        }

        $normalized = [];
        foreach ($evidence as $key => $item) {
            if (! is_string($key) || ! is_array($item) || ! self::hasExactKeys($item, ['locator'])) {
                throw new VisionContractException('invalid_evidence');
            }
            $normalized[] = ['key' => $key, 'locator' => $item['locator']];
        }

        return $normalized;
    }
}
