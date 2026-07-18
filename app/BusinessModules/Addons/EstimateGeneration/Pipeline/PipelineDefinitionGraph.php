<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class PipelineDefinitionGraph
{
    public const MAX_TOTAL_ARTIFACT_BYTES = 10_485_760;

    /** @var array<string, StageDefinition> */
    private array $definitions;

    /** @param list<StageDefinition> $definitions */
    public function __construct(array $definitions)
    {
        $byStage = [];
        foreach ($definitions as $definition) {
            if (isset($byStage[$definition->stage->value])) {
                throw new InvalidArgumentException('Pipeline stage definition is duplicated.');
            }
            $byStage[$definition->stage->value] = $definition;
        }
        $ordered = array_values($byStage);
        if (array_map(static fn (StageDefinition $definition): ProcessingStage => $definition->stage, $ordered) !== ProcessingStage::cases()
            || array_sum(array_map(static fn (StageDefinition $definition): int => $definition->maxArtifactBytes, $ordered)) > self::MAX_TOTAL_ARTIFACT_BYTES) {
            throw new InvalidArgumentException('Pipeline definition graph is incomplete, unordered or exceeds its aggregate bound.');
        }
        $this->definitions = $byStage;
    }

    public static function standard(): self
    {
        return new self([
            new StageDefinition(ProcessingStage::UnderstandDocuments, 1, [], 131_072),
            new StageDefinition(ProcessingStage::UnderstandObject, 2, [ProcessingStage::UnderstandDocuments], 1_048_576),
            new StageDefinition(ProcessingStage::ExtractQuantities, 2, [ProcessingStage::UnderstandObject], 262_144),
            new StageDefinition(ProcessingStage::PlanWorkItems, 23, [ProcessingStage::UnderstandObject, ProcessingStage::ExtractQuantities], 1_310_720),
            new StageDefinition(ProcessingStage::MatchNormatives, 6, [ProcessingStage::PlanWorkItems], 1_310_720),
            new StageDefinition(ProcessingStage::AssembleResources, 2, [ProcessingStage::MatchNormatives], 1_048_576),
            new StageDefinition(ProcessingStage::ResolvePrices, 3, [ProcessingStage::AssembleResources], 1_441_792),
            new StageDefinition(ProcessingStage::BuildDraft, 1, [ProcessingStage::UnderstandDocuments, ProcessingStage::UnderstandObject, ProcessingStage::PlanWorkItems, ProcessingStage::ResolvePrices], 1_572_864),
            new StageDefinition(ProcessingStage::ValidateDraft, 1, [ProcessingStage::BuildDraft], 1_572_864),
        ]);
    }

    /** @return list<StageDefinition> */
    public function ordered(): array
    {
        return array_values($this->definitions);
    }

    public function get(ProcessingStage $stage): StageDefinition
    {
        return $this->definitions[$stage->value];
    }
}
