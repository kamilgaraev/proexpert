<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class PipelineStagePayload
{
    private const KEYS = [
        'understand_documents' => ['base_input_version', 'documents', 'documents_count', 'rebuild_section_key'],
        'understand_object' => ['analysis'],
        'extract_quantities' => ['quantity_learning_hints'],
        'plan_work_items' => ['object_profile', 'package_plan', 'document_requirements', 'generation_mode', 'regional_context', 'normative_context_pin', 'local_estimates'],
        'match_normatives' => ['local_estimates'],
        'assemble_resources' => ['local_estimates'],
        'resolve_prices' => ['local_estimates'],
        'build_draft' => ['draft'],
        'validate_draft' => ['draft', 'requires_review'],
    ];

    private function __construct(public ProcessingStage $stage, public array $data) {}

    public static function from(ProcessingStage $stage, array $data): self
    {
        if (array_keys($data) !== self::KEYS[$stage->value]) {
            throw new InvalidArgumentException('Pipeline stage payload schema is invalid.');
        }
        match ($stage) {
            ProcessingStage::UnderstandDocuments => self::assertDocumentManifest($data),
            ProcessingStage::UnderstandObject => self::assertArray($data['analysis']),
            ProcessingStage::ExtractQuantities => self::assertArray($data['quantity_learning_hints']),
            ProcessingStage::PlanWorkItems => self::assertPlanning($data),
            ProcessingStage::MatchNormatives,
            ProcessingStage::AssembleResources,
            ProcessingStage::ResolvePrices => self::assertList($data['local_estimates']),
            ProcessingStage::BuildDraft => self::assertArray($data['draft']),
            ProcessingStage::ValidateDraft => self::assertValidatedDraft($data),
        };
        CanonicalPipelineJson::encode($data);

        return new self($stage, $data);
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
