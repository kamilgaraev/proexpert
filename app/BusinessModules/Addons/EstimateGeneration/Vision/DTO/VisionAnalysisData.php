<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\ProjectSheetAnalysisData;

final readonly class VisionAnalysisData
{
    public const MAX_RAW_OBSERVATION_BYTES = 131_072;

    public const SCHEMA_VERSION = 1;

    public const CURRENT_SCHEMA_VERSION = 2;

    public const PROJECT_SHEET_SCHEMA_VERSION = 3;

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
        public ?ProjectSheetAnalysisData $projectSheetAnalysis = null,
        public array $quarantinedItems = [],
        public array $rawObserverFacts = [],
    ) {
        if (! in_array($sheetType, self::SHEET_TYPES, true) || $evidence === [] || count($evidence) > 256 || count($elements) > 500 || count($scaleCandidates) > 32
            || array_diff($warnings, self::WARNINGS) !== [] || count($warnings) !== count(array_unique($warnings))
            || preg_match('/^[a-z0-9._-]{1,80}$/', $provider) !== 1
            || preg_match('#^[A-Za-z0-9._/-]{1,160}$#', $requestedModel) !== 1 || $reportedModel !== $requestedModel
            || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $modelVersion) !== 1
            || ! in_array($usageStatus, ['measured', 'unavailable'], true)
            || ($usageStatus === 'unavailable') !== ($inputTokens === null && $outputTokens === null)
            || ($inputTokens !== null && $inputTokens < 0) || ($outputTokens !== null && $outputTokens < 0)
            || count($rawObserverFacts) > 64
            || strlen(json_encode($rawObserverFacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) > self::MAX_RAW_OBSERVATION_BYTES) {
            throw new VisionContractException('invalid_analysis_metadata');
        }
        foreach ($rawObserverFacts as $fact) {
            if (! is_array($fact)) {
                throw new VisionContractException('invalid_analysis_metadata');
            }
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

    /** @param array<string, mixed> $data @param list<string> $nativeReferences */
    public static function fromProviderArray(array $data, string $provider, string $requestedModel, string $reportedModel, string $modelVersion, string $usageStatus, ?int $inputTokens, ?int $outputTokens, int $maxElements, ?int $maxFacts = null, array $nativeReferences = []): self
    {
        $maxFacts ??= $maxElements;
        $schemaVersion = $data['schema_version'] ?? null;
        $expectedKeys = match ($schemaVersion) {
            self::PROJECT_SHEET_SCHEMA_VERSION => ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings', 'visual_attributes', 'project_sheet_analysis'],
            self::CURRENT_SCHEMA_VERSION => ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings', 'visual_attributes'],
            default => ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings'],
        };
        if (! self::hasExactKeys($data, $expectedKeys)
            || ! in_array($schemaVersion, [self::SCHEMA_VERSION, self::CURRENT_SCHEMA_VERSION, self::PROJECT_SHEET_SCHEMA_VERSION], true)
            || ! is_string($data['sheet_type'])
            || ! is_array($data['evidence']) || ! is_array($data['elements']) || ! is_array($data['scale_candidates']) || ! is_array($data['warnings'])
            || ($schemaVersion === self::CURRENT_SCHEMA_VERSION && ! is_array($data['visual_attributes']))
            || ($schemaVersion === self::PROJECT_SHEET_SCHEMA_VERSION && (! is_array($data['visual_attributes']) || ! is_array($data['project_sheet_analysis'])))
            || $maxElements < 1 || $maxElements > 500
            || $maxFacts < 1 || $maxFacts > 500
            || count($data['elements']) > $maxElements) {
            throw new VisionContractException('invalid_analysis_schema');
        }
        $evidencePayload = self::normalizeEvidencePayload($data['evidence']);
        $evidence = array_map(static fn (mixed $item): VisionEvidenceData => is_array($item) ? VisionEvidenceData::fromArray($item) : throw new VisionContractException('invalid_evidence'), $evidencePayload);
        $evidenceKeys = array_map(static fn (VisionEvidenceData $item): string => $item->key, $evidence);
        if (count($evidenceKeys) !== count(array_unique($evidenceKeys))) {
            throw new VisionContractException('duplicate_keys');
        }
        [$elements, $elementQuarantine] = self::providerElements($data['elements'], $evidenceKeys);
        [$scales, $scaleQuarantine] = self::providerScales($data['scale_candidates'], $evidenceKeys, $elements);
        $semanticQuarantine = [];
        $projectSheetAnalysis = null;
        if ($schemaVersion === self::PROJECT_SHEET_SCHEMA_VERSION) {
            try {
                $projectSheetAnalysis = ProjectSheetAnalysisData::fromProviderArray($data['project_sheet_analysis'], $evidenceKeys, $maxFacts, $nativeReferences);
                $semanticQuarantine = $projectSheetAnalysis->quarantinedItems;
            } catch (VisionContractException $exception) {
                $projectSheetAnalysis = ProjectSheetAnalysisData::fromProviderArray([
                    'contractVersion' => ProjectSheetAnalysisData::CONTRACT_VERSION,
                    'role' => 'unknown',
                    'facts' => [],
                ], $evidenceKeys, $maxFacts, $nativeReferences);
                $semanticQuarantine[] = ['section' => 'project_sheet_analysis', 'index' => 0, 'reason' => $exception->reason];
            }
        }
        foreach ($data['warnings'] as $warning) {
            if (! is_string($warning)) {
                throw new VisionContractException('invalid_warning');
            }
        }
        $warnings = array_values($data['warnings']);
        if ($scales === [] && ! in_array('scale_missing', $warnings, true)) {
            $warnings[] = 'scale_missing';
        }
        if (self::hasMaterialScaleConflict($scales) && ! in_array('scale_conflict', $warnings, true)) {
            $warnings[] = 'scale_conflict';
        }
        if (! self::hasMaterialScaleConflict($scales)) {
            $warnings = array_values(array_diff($warnings, ['scale_conflict']));
        }
        [$visualAttributes, $visualQuarantine] = self::providerVisualAttributes(
            is_array($data['visual_attributes'] ?? null) ? $data['visual_attributes'] : [],
            $evidenceKeys,
        );

        return new self(
            $data['sheet_type'],
            $evidence,
            $elements,
            $scales,
            $warnings,
            $provider,
            $requestedModel,
            $reportedModel,
            $modelVersion,
            $usageStatus,
            $inputTokens,
            $outputTokens,
            $visualAttributes,
            $projectSheetAnalysis,
            [...$elementQuarantine, ...$scaleQuarantine, ...$visualQuarantine, ...$semanticQuarantine],
            self::boundedRawObserverFacts($data['project_sheet_analysis']['facts'] ?? []),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromStoredArray(array $data): self
    {
        unset($data['contract_repairs']);
        $schemaVersion = $data['schema_version'] ?? null;
        $contractKeys = match ($schemaVersion) {
            self::PROJECT_SHEET_SCHEMA_VERSION => ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings', 'provider', 'requested_model', 'reported_model', 'model_version', 'usage', 'visual_attributes', 'project_sheet_analysis'],
            self::CURRENT_SCHEMA_VERSION => ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings', 'provider', 'requested_model', 'reported_model', 'model_version', 'usage', 'visual_attributes'],
            self::SCHEMA_VERSION => ['schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings', 'provider', 'requested_model', 'reported_model', 'model_version', 'usage'],
            default => [],
        };
        $usage = $data['usage'] ?? null;
        if ($contractKeys === [] || ! self::hasExactKeys($data, $contractKeys)
            || ! is_string($data['sheet_type'] ?? null)
            || ! is_array($data['evidence'] ?? null)
            || ! is_array($data['elements'] ?? null)
            || ! is_array($data['scale_candidates'] ?? null)
            || ! is_array($data['warnings'] ?? null)
            || ! is_string($data['provider'] ?? null)
            || ! is_string($data['requested_model'] ?? null)
            || ! is_string($data['reported_model'] ?? null)
            || ! is_string($data['model_version'] ?? null)
            || ! is_array($usage)
            || ! self::hasExactKeys($usage, ['status', 'input_tokens', 'output_tokens'])) {
            throw new VisionContractException('invalid_stored_analysis_schema');
        }

        $evidence = array_map(
            static fn (mixed $item): VisionEvidenceData => is_array($item)
                ? VisionEvidenceData::fromArray($item)
                : throw new VisionContractException('invalid_evidence'),
            self::normalizeEvidencePayload($data['evidence']),
        );
        $elements = array_map(
            static fn (mixed $item): VisionElementData => is_array($item)
                ? VisionElementData::fromArray(self::normalizeStoredElement($item))
                : throw new VisionContractException('invalid_element'),
            $data['elements'],
        );
        $scales = array_map(
            static fn (mixed $item): VisionScaleCandidateData => is_array($item)
                ? VisionScaleCandidateData::fromArray($item)
                : throw new VisionContractException('invalid_scale_candidate'),
            $data['scale_candidates'],
        );
        foreach ($data['warnings'] as $warning) {
            if (! is_string($warning)) {
                throw new VisionContractException('invalid_warning');
            }
        }
        $projectSheetAnalysis = $schemaVersion === self::PROJECT_SHEET_SCHEMA_VERSION
            ? ProjectSheetAnalysisData::fromStoredArray(
                $data['project_sheet_analysis'],
                array_map(static fn (VisionEvidenceData $item): string => $item->key, $evidence),
            )
            : null;

        return new self(
            $data['sheet_type'],
            $evidence,
            $elements,
            $scales,
            array_values($data['warnings']),
            $data['provider'],
            $data['requested_model'],
            $data['reported_model'],
            $data['model_version'],
            is_string($usage['status'] ?? null) ? $usage['status'] : '',
            is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            is_array($data['visual_attributes'] ?? null) ? $data['visual_attributes'] : [],
            $projectSheetAnalysis,
            [],
            [],
        );
    }

    /** @param array<string, mixed> $element @return array<string, mixed> */
    private static function normalizeStoredElement(array $element): array
    {
        $keys = array_keys($element);
        sort($keys);
        $expected = ['confidence', 'evidence_ref', 'key', 'polygon', 'type'];
        sort($expected);
        if ($keys === $expected) {
            return [...$element, 'label' => null];
        }

        $opening = [...$expected, 'geometry'];
        sort($opening);

        return $keys === $opening ? [...$element, 'label' => null] : $element;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $nativeReferences
     */
    public static function fromTargetedProviderArray(
        array $data,
        self $primary,
        string $provider,
        string $requestedModel,
        string $reportedModel,
        string $modelVersion,
        string $usageStatus,
        ?int $inputTokens,
        ?int $outputTokens,
        int $maxFacts,
        array $nativeReferences,
        ProjectiveTransformData $transform,
    ): self {
        if (! self::hasExactKeys($data, ['schema_version', 'evidence', 'project_sheet_analysis'])
            || ($data['schema_version'] ?? null) !== 1
            || ! is_array($data['evidence'])
            || ! is_array($data['project_sheet_analysis'])
            || $maxFacts < 1 || $maxFacts > 500) {
            throw new VisionContractException('invalid_targeted_analysis_schema');
        }
        $targetedEvidence = array_map(
            static fn (mixed $item): VisionEvidenceData => is_array($item)
                ? VisionEvidenceData::fromArray($item)
                : throw new VisionContractException('invalid_evidence'),
            self::normalizeEvidencePayload($data['evidence']),
        );
        $allEvidence = [...$primary->evidence, ...$targetedEvidence];
        $targetedProjectSheetAnalysis = ProjectSheetAnalysisData::fromProviderArray(
            $data['project_sheet_analysis'],
            array_map(static fn (VisionEvidenceData $item): string => $item->key, $allEvidence),
            $maxFacts,
            $nativeReferences,
        )->mapPolygonsToSource($transform);
        $mappedEvidence = [
            ...$primary->evidence,
            ...array_map(static fn (VisionEvidenceData $item): VisionEvidenceData => $item->toSourceSpace(), $targetedEvidence),
        ];
        $factsByIdentity = [];
        foreach ([
            ...($primary->projectSheetAnalysis?->facts ?? []),
            ...$targetedProjectSheetAnalysis->facts,
        ] as $fact) {
            if (is_string($fact['entityKey'] ?? null) && is_string($fact['factType'] ?? null)) {
                $factsByIdentity[$fact['entityKey']."\0".$fact['factType']] = $fact;
            }
        }
        $projectSheetAnalysis = ProjectSheetAnalysisData::fromProviderArray([
            'contractVersion' => ProjectSheetAnalysisData::CONTRACT_VERSION,
            'role' => $targetedProjectSheetAnalysis->sheetRole,
            'facts' => array_slice(array_values($factsByIdentity), 0, $maxFacts),
        ], array_map(static fn (VisionEvidenceData $item): string => $item->key, $mappedEvidence), $maxFacts, $nativeReferences);

        return new self(
            $primary->sheetType,
            $mappedEvidence,
            $primary->elements,
            $primary->scaleCandidates,
            $primary->warnings,
            $provider,
            $requestedModel,
            $reportedModel,
            $modelVersion,
            $usageStatus,
            $inputTokens,
            $outputTokens,
            $primary->visualAttributes,
            $projectSheetAnalysis,
            [
                ...$primary->quarantinedItems,
                ...$targetedProjectSheetAnalysis->quarantinedItems,
                ...$projectSheetAnalysis->quarantinedItems,
            ],
            self::boundedRawObserverFacts([
                ...$primary->rawObserverFacts,
                ...self::boundedRawObserverFacts($data['project_sheet_analysis']['facts'] ?? []),
            ]),
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
            $this->projectSheetAnalysis?->mapPolygonsToSource($transform),
            $this->quarantinedItems,
            $this->rawObserverFacts,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function boundedRawObserverFacts(mixed $facts): array
    {
        if (! is_array($facts)) {
            return [];
        }
        $bounded = [];
        $bytes = 2;
        foreach ($facts as $fact) {
            if (! is_array($fact) || count($bounded) >= 64) {
                continue;
            }
            $encoded = json_encode($fact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($bytes + strlen($encoded) + 1 > self::MAX_RAW_OBSERVATION_BYTES) {
                break;
            }
            $bounded[] = $fact;
            $bytes += strlen($encoded) + 1;
        }

        return $bounded;
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
            'schema_version' => $this->projectSheetAnalysis === null ? ($this->visualAttributes === [] ? self::SCHEMA_VERSION : self::CURRENT_SCHEMA_VERSION) : self::PROJECT_SHEET_SCHEMA_VERSION,
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
        if ($this->projectSheetAnalysis !== null) {
            $payload['project_sheet_analysis'] = $this->projectSheetAnalysis->toArray();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function semanticQuality(): array
    {
        $role = $this->projectSheetAnalysis?->sheetRole ?? 'unknown';
        $factTypes = array_values(array_unique(array_map(
            static fn (array $fact): string => (string) ($fact['factType'] ?? ''),
            $this->projectSheetAnalysis?->facts ?? [],
        )));
        $checked = self::coverageChecklist($role);
        $found = array_values(array_intersect($checked, $factTypes));

        return [
            'role' => $role,
            'checked' => $checked,
            'found' => $found,
            'missing' => array_values(array_diff($checked, $found)),
            'needs_targeted' => array_values(array_intersect(array_diff($checked, $found), ['material', 'finish_zone', 'note', 'table', 'cross_sheet_link'])),
            'quarantined_items' => $this->quarantinedItems,
        ];
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
            if (! is_string($key) || ! is_array($item)) {
                throw new VisionContractException('invalid_evidence');
            }
            if (self::hasExactKeys($item, ['locator'])) {
                $normalized[] = ['key' => $key, 'locator' => $item['locator']];

                continue;
            }
            if (self::hasExactKeys($item, ['page_id', 'page_number', 'processing_unit_id', 'source_version', 'coordinate_space'])) {
                $normalized[] = ['key' => $key, 'locator' => $item];

                continue;
            }

            throw new VisionContractException('invalid_evidence');
        }

        return $normalized;
    }

    /** @param array<mixed> $elements @return array<mixed> */
    private static function normalizeProviderElementLabels(array $elements): array
    {
        return array_map(static function (mixed $element): mixed {
            if (! is_array($element) || ! is_string($element['label'] ?? null)) {
                return $element;
            }

            return [
                ...$element,
                'label' => mb_substr($element['label'], 0, VisionElementData::MAX_LABEL_LENGTH),
            ];
        }, $elements);
    }

    /** @param array<mixed> $payload @param list<string> $evidenceKeys @return array{list<VisionElementData>, list<array{section: string, index: int, reason: string}>} */
    private static function providerElements(array $payload, array $evidenceKeys): array
    {
        $items = [];
        $quarantined = [];
        $keys = [];
        $sourceIndexes = [];
        foreach (self::normalizeProviderElementLabels($payload) as $index => $raw) {
            try {
                $item = is_array($raw) ? VisionElementData::fromArray($raw) : throw new VisionContractException('invalid_element');
                if (! in_array($item->evidenceRef, $evidenceKeys, true)) {
                    throw new VisionContractException('dangling_evidence');
                }
                if (isset($keys[$item->key])) {
                    throw new VisionContractException('duplicate_keys');
                }
                $keys[$item->key] = true;
                $items[] = $item;
                $sourceIndexes[] = $index;
            } catch (VisionContractException $exception) {
                $quarantined[] = ['section' => 'elements', 'index' => $index, 'reason' => $exception->reason];
            }
        }

        $walls = [];
        foreach ($items as $item) {
            if ($item->type === 'wall') {
                $walls[$item->key] = true;
            }
        }
        $validated = [];
        foreach ($items as $acceptedIndex => $item) {
            $wallKey = $item->type === 'opening' && is_array($item->geometry)
                ? ($item->geometry['wall_key'] ?? null)
                : null;
            if ($wallKey !== null && ! isset($walls[$wallKey])) {
                $quarantined[] = [
                    'section' => 'elements',
                    'index' => $sourceIndexes[$acceptedIndex],
                    'reason' => 'dangling_element_reference',
                ];

                continue;
            }
            $validated[] = $item;
        }

        return [$validated, $quarantined];
    }

    /** @param array<mixed> $payload @param list<string> $evidenceKeys @param list<VisionElementData> $elements @return array{list<VisionScaleCandidateData>, list<array{section: string, index: int, reason: string}>} */
    private static function providerScales(array $payload, array $evidenceKeys, array $elements): array
    {
        $elementEvidence = [];
        foreach ($elements as $element) {
            $elementEvidence[$element->key] = $element->evidenceRef;
        }
        $items = [];
        $quarantined = [];
        foreach ($payload as $index => $raw) {
            try {
                if (! is_array($raw)) {
                    throw new VisionContractException('invalid_scale_candidate');
                }
                $reference = $raw['evidence_ref'] ?? null;
                if (is_string($reference) && ! in_array($reference, $evidenceKeys, true) && isset($elementEvidence[$reference])) {
                    $raw['evidence_ref'] = $elementEvidence[$reference];
                }
                $item = VisionScaleCandidateData::fromArray($raw);
                if (! in_array($item->evidenceRef, $evidenceKeys, true)) {
                    throw new VisionContractException('dangling_evidence');
                }
                $items[] = $item;
            } catch (VisionContractException $exception) {
                $quarantined[] = ['section' => 'scale_candidates', 'index' => $index, 'reason' => $exception->reason];
            }
        }

        return [$items, $quarantined];
    }

    /** @param array<string, mixed> $payload @param list<string> $evidenceKeys @return array{array<string, mixed>, list<array{section: string, index: int, reason: string}>} */
    private static function providerVisualAttributes(array $payload, array $evidenceKeys): array
    {
        if ($payload === []) {
            return [[], []];
        }
        $roof = $payload['roof_type'] ?? null;
        if (array_keys($payload) === ['roof_type']
            && is_array($roof)
            && array_keys($roof) === ['value', 'confidence', 'evidence_ref']
            && in_array($roof['value'] ?? null, ['flat', 'pitched', 'gable', 'hip', 'unknown'], true)
            && (is_float($roof['confidence'] ?? null) || is_int($roof['confidence'] ?? null))
            && is_finite((float) $roof['confidence'])
            && (float) $roof['confidence'] >= 0
            && (float) $roof['confidence'] <= 1
            && is_string($roof['evidence_ref'] ?? null)
            && in_array($roof['evidence_ref'], $evidenceKeys, true)) {
            return [$payload, []];
        }

        return [[], [['section' => 'visual_attributes', 'index' => 0, 'reason' => 'invalid_visual_attributes']]];
    }

    /** @return list<string> */
    private static function coverageChecklist(string $role): array
    {
        return match ($role) {
            'facade' => ['elevation', 'level', 'axis', 'dimension_chain', 'area', 'opening', 'roof_geometry', 'material', 'finish_zone', 'note', 'cross_sheet_link'],
            'plan' => ['room', 'wall', 'opening', 'axis', 'dimension_chain', 'area', 'level', 'material', 'finish_zone', 'engineering_element', 'note', 'cross_sheet_link'],
            'section' => ['elevation', 'level', 'axis', 'dimension_chain', 'opening', 'structural_element', 'roof_geometry', 'material', 'engineering_element', 'note', 'cross_sheet_link'],
            'explication' => ['room', 'area', 'level', 'material', 'finish_zone', 'table', 'note', 'cross_sheet_link'],
            'specification' => ['table', 'structural_element', 'material', 'equipment', 'quantity', 'note', 'cross_sheet_link'],
            default => ['opening', 'dimension_chain', 'area', 'material', 'note', 'cross_sheet_link'],
        };
    }

    /** @param list<VisionScaleCandidateData> $scales */
    private static function hasMaterialScaleConflict(array $scales): bool
    {
        for ($left = 0; $left < count($scales); $left++) {
            for ($right = $left + 1; $right < count($scales); $right++) {
                $a = $scales[$left]->metersPerUnit;
                $b = $scales[$right]->metersPerUnit;
                if (abs($a - $b) > max(1.0e-9, 0.02 * min($a, $b))) {
                    return true;
                }
            }
        }

        return false;
    }
}
