<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineArtifactStoreTest extends TestCase
{
    #[Test]
    public function replay_reuses_reference_without_exposing_content_and_tenant_is_fenced(): void
    {
        $store = new InMemoryPipelineArtifactStore;
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandObject);
        $owner = $this->context(20);
        $foreign = $this->context(21);
        $data = ['analysis' => ['description' => 'Секретное описание']];
        $first = $store->write($owner, $definition, $data);
        $second = $store->write($owner, $definition, $data);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertStringNotContainsString('Секретное', json_encode($first->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame($data, $store->read($owner, $first));
        $this->expectException(DomainException::class);
        $store->read($foreign, $first);
    }

    #[Test]
    public function forged_digest_is_rejected(): void
    {
        $store = new InMemoryPipelineArtifactStore;
        $context = $this->context(20);
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandObject);
        $reference = $store->write($context, $definition, ['analysis' => []]);
        $forged = new PipelineArtifactReference($reference->kind, $reference->objectKey, 'sha256:'.str_repeat('0', 64), $reference->bytes);

        $this->expectException(DomainException::class);
        $store->read($context, $forged);
    }

    #[Test]
    public function prior_outputs_load_only_requested_typed_payload(): void
    {
        $loads = 0;
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandObject);
        $dependency = 'sha256:'.str_repeat('d', 64);
        $dependencies = [ProcessingStage::UnderstandDocuments->value => $dependency];
        $input = PipelineInputVersion::for($definition, 'sha256:'.str_repeat('a', 64), $dependencies);
        $output = PipelineStageOutput::create($definition, $input, $dependencies, new PipelineArtifactReference('memory_json_v1', 'memory/one', 'sha256:'.str_repeat('b', 64), 10));
        $prior = new PipelinePriorOutputs([$output->stage->value => $output], loader: static function () use (&$loads): array {
            $loads++;

            return ['analysis' => []];
        });

        self::assertSame(['analysis' => []], $prior->payload(ProcessingStage::UnderstandObject));
        self::assertSame(1, $loads);
    }

    private function context(int $organizationId): PipelineContext
    {
        $base = 'sha256:'.str_repeat('a', 64);

        return new PipelineContext(10, $organizationId, 30, 4, $base, 'generating', generationAttemptId: '00000000-0000-4000-8000-000000000001', baseInputVersion: $base);
    }
}
