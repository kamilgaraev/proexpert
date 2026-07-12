<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\StageDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceSnapshotCheckpointInvalidationTest extends TestCase
{
    #[Test]
    public function regional_context_change_replaces_price_lineage_and_invalidates_draft_input(): void
    {
        $graph = PipelineDefinitionGraph::standard();
        $base = 'sha256:'.str_repeat('a', 64);
        $assembleVersion = 'sha256:'.str_repeat('b', 64);
        $priceDefinition = $graph->get(ProcessingStage::ResolvePrices);
        $priceInput = PipelineInputVersion::for($priceDefinition, $base, [ProcessingStage::AssembleResources->value => $assembleVersion]);
        $first = $this->stageOutput($priceDefinition, $priceInput, $assembleVersion, ['region_id' => 16, 'version_id' => 11]);
        $changed = $this->stageOutput($priceDefinition, $priceInput, $assembleVersion, ['region_id' => 77, 'version_id' => 12]);

        self::assertNotSame($first->version, $changed->version);
        $draftDefinition = $graph->get(ProcessingStage::BuildDraft);
        $fixed = [
            ProcessingStage::UnderstandDocuments->value => 'sha256:'.str_repeat('c', 64),
            ProcessingStage::UnderstandObject->value => 'sha256:'.str_repeat('d', 64),
            ProcessingStage::PlanWorkItems->value => 'sha256:'.str_repeat('e', 64),
        ];
        $firstDraftInput = PipelineInputVersion::for($draftDefinition, $base, [...$fixed, ProcessingStage::ResolvePrices->value => $first->version]);
        $changedDraftInput = PipelineInputVersion::for($draftDefinition, $base, [...$fixed, ProcessingStage::ResolvePrices->value => $changed->version]);
        self::assertNotSame($firstDraftInput, $changedDraftInput);
    }

    private function stageOutput(StageDefinition $definition, string $input, string $dependency, array $regionalContext): PipelineStageOutput
    {
        $payload = ['regional_context' => $regionalContext, 'local_estimates' => []];
        $canonical = CanonicalPipelineJson::encode($payload);

        return PipelineStageOutput::create(
            $definition,
            $input,
            [ProcessingStage::AssembleResources->value => $dependency],
            new PipelineArtifactReference('memory_json_v1', 'contract/resolve-prices', 'sha256:'.hash('sha256', $canonical), strlen($canonical)),
        );
    }
}
