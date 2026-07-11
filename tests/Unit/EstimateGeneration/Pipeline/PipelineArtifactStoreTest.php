<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineArtifactStoreTest extends TestCase
{
    #[Test]
    public function replay_reuses_the_same_content_addressed_reference_without_exposing_content(): void
    {
        $store = new InMemoryPipelineArtifactStore;
        $context = new PipelineContext(10, 20, 30, 4, 'attempt-a', 'generating');
        $data = ['description' => 'Секретное описание', 'count' => 2];

        $first = $store->write($context, ProcessingStage::UnderstandObject, $data);
        $second = $store->write($context, ProcessingStage::UnderstandObject, $data);

        self::assertSame($first->version, $second->version);
        self::assertSame($first->data, $second->data);
        self::assertStringNotContainsString('Секретное', json_encode($first->envelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame($data, $store->read($context, $first));
    }

    #[Test]
    public function tenant_context_cannot_read_another_organizations_artifact(): void
    {
        $store = new InMemoryPipelineArtifactStore;
        $owner = new PipelineContext(10, 20, 30, 4, 'attempt-a', 'generating');
        $foreign = new PipelineContext(10, 21, 30, 4, 'attempt-a', 'generating');
        $reference = $store->write($owner, ProcessingStage::UnderstandObject, ['value' => 1]);

        $this->expectException(DomainException::class);
        $store->read($foreign, $reference);
    }

    #[Test]
    public function forged_digest_is_rejected(): void
    {
        $store = new InMemoryPipelineArtifactStore;
        $context = new PipelineContext(10, 20, 30, 4, 'attempt-a', 'generating');
        $reference = $store->write($context, ProcessingStage::UnderstandObject, ['value' => 1]);
        $forged = PipelineStageOutput::create(ProcessingStage::UnderstandObject, 1, [
            ...$reference->data,
            'content_version' => 'sha256:'.str_repeat('0', 64),
        ]);

        $this->expectException(DomainException::class);
        $store->read($context, $forged);
    }

    #[Test]
    public function prior_outputs_load_only_the_requested_artifact(): void
    {
        $loads = 0;
        $reference = PipelineStageOutput::create(ProcessingStage::UnderstandObject, 1, ['artifact_kind' => 'test', 'object_key' => 'one']);
        $prior = new PipelinePriorOutputs(
            [ProcessingStage::UnderstandObject->value => $reference],
            loader: static function () use (&$loads): array {
                $loads++;

                return ['analysis' => []];
            },
        );

        self::assertSame(0, $loads);
        self::assertSame(['analysis' => []], $prior->payload(ProcessingStage::UnderstandObject));
        self::assertSame(1, $loads);
    }

    #[Test]
    public function transient_final_payload_must_match_the_content_addressed_reference(): void
    {
        $reference = PipelineStageOutput::create(ProcessingStage::ValidateDraft, 1, [
            'artifact_kind' => 'test',
            'object_key' => 'one',
            'content_version' => 'sha256:'.hash('sha256', '{}'),
            'bytes' => 2,
        ]);
        $this->expectException(\InvalidArgumentException::class);

        new PipelineStageResult(
            ProcessingStage::ValidateDraft,
            $reference->version,
            [],
            [],
            $reference,
            ['draft' => ['changed' => true]],
        );
    }
}
