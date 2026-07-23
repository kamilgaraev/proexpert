<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning;
use InvalidArgumentException;

final readonly class PipelineStagePayload
{
    private const KEYS = [
        'understand_documents' => ['base_input_version', 'documents', 'documents_count', 'rebuild_section_key'],
        'understand_object' => ['analysis'],
        'extract_quantities' => ['quantity_learning_hints', 'quantity_coverage_warnings', 'building_quantities'],
        'plan_work_items' => ['object_profile', 'package_plan', 'document_requirements', 'generation_mode', 'regional_context', 'normative_context_pin', 'local_estimates'],
        'match_normatives' => ['regional_context', 'supplementary_materials', 'local_estimates'],
        'assemble_resources' => ['regional_context', 'supplementary_materials', 'local_estimates'],
        'resolve_prices' => ['regional_context', 'supplementary_materials', 'local_estimates'],
        'build_draft' => ['draft'],
        'validate_draft' => ['draft', 'requires_review'],
    ];

    private function __construct(public ProcessingStage $stage, public array $data) {}

    public static function from(ProcessingStage $stage, array $data): self
    {
        $actualKeys = array_keys($data);
        $expectedKeys = self::KEYS[$stage->value];
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException('Pipeline stage payload schema is invalid.');
        }
        match ($stage) {
            ProcessingStage::UnderstandDocuments => self::assertDocumentManifest($data),
            ProcessingStage::UnderstandObject => self::assertArray($data['analysis']),
            ProcessingStage::ExtractQuantities => self::assertExtractedQuantities($data),
            ProcessingStage::PlanWorkItems => self::assertPlanning($data),
            ProcessingStage::MatchNormatives,
            ProcessingStage::AssembleResources,
            ProcessingStage::ResolvePrices => self::assertPricedWorkItems($data),
            ProcessingStage::BuildDraft => self::assertArray($data['draft']),
            ProcessingStage::ValidateDraft => self::assertValidatedDraft($data),
        };
        CanonicalPipelineJson::encode($data);

        return new self($stage, $data);
    }

    private static function assertPricedWorkItems(array $data): void
    {
        self::assertArray($data['regional_context']);
        self::assertList($data['supplementary_materials']);
        self::assertList($data['local_estimates']);
    }

    private static function assertDocumentManifest(array $data): void
    {
        PipelineVersionValidator::assertSha256((string) $data['base_input_version'], 'manifest base input');
        self::assertList($data['documents']);
        if (! is_int($data['documents_count']) || $data['documents_count'] !== count($data['documents'])
            || ($data['rebuild_section_key'] !== null && ! is_string($data['rebuild_section_key']))) {
            throw new InvalidArgumentException('Pipeline document manifest is invalid.');
        }
        foreach ($data['documents'] as $document) {
            if (! is_array($document) || array_keys($document) !== ['id', 'source_version'] || ! is_int($document['id'])) {
                throw new InvalidArgumentException('Pipeline document reference is invalid.');
            }
            PipelineVersionValidator::assertSha256((string) $document['source_version'], 'document source');
        }
    }

    private static function assertPlanning(array $data): void
    {
        foreach (['object_profile', 'package_plan', 'document_requirements', 'regional_context'] as $key) {
            self::assertArray($data[$key]);
        }
        if (! is_string($data['generation_mode'])) {
            throw new InvalidArgumentException('Pipeline generation mode is invalid.');
        }
        self::assertList($data['local_estimates']);
        self::assertCanonicalQuantityEvidence($data['local_estimates']);
    }

    private static function assertExtractedQuantities(array $data): void
    {
        self::assertArray($data['quantity_learning_hints']);
        self::assertList($data['quantity_coverage_warnings']);
        foreach ($data['quantity_coverage_warnings'] as $warning) {
            if (! QuantityCoverageWarning::isValid($warning)) {
                throw new InvalidArgumentException('Pipeline quantity coverage warning is invalid.');
            }
        }
        self::assertArray($data['building_quantities']);
    }

    private static function assertCanonicalQuantityEvidence(array $localEstimates): void
    {
        foreach ($localEstimates as $localEstimate) {
            foreach (($localEstimate['sections'] ?? []) as $section) {
                foreach (($section['work_items'] ?? []) as $workItem) {
                    $evidence = $workItem['quantity_evidence'] ?? null;
                    if ($evidence === null) {
                        continue;
                    }
                    if (! is_array($evidence)) {
                        throw new InvalidArgumentException('Pipeline canonical quantity evidence is invalid.');
                    }
                    \App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData::fromArray($evidence);
                }
            }
        }
    }

    private static function assertValidatedDraft(array $data): void
    {
        self::assertArray($data['draft']);
        if (! is_bool($data['requires_review'])) {
            throw new InvalidArgumentException('Pipeline review flag is invalid.');
        }
    }

    private static function assertArray(mixed $value): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Pipeline stage payload field must be an array.');
        }
    }

    private static function assertList(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Pipeline stage payload field must be a list.');
        }
    }
}
