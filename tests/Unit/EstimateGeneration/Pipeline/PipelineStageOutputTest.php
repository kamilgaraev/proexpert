<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineStageOutputTest extends TestCase
{
    #[Test]
    public function canonical_version_is_stable_across_associative_key_order(): void
    {
        $left = PipelineStageOutput::create(ProcessingStage::UnderstandObject, 1, [
            'object' => ['type' => 'house', 'floors' => 2],
            'warnings' => [],
        ]);
        $right = PipelineStageOutput::create(ProcessingStage::UnderstandObject, 1, [
            'warnings' => [],
            'object' => ['floors' => 2, 'type' => 'house'],
        ]);

        self::assertSame($left->version, $right->version);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $left->version);
    }

    #[Test]
    public function semantic_change_changes_version(): void
    {
        $left = PipelineStageOutput::create(ProcessingStage::UnderstandObject, 1, ['floors' => 1]);
        $right = PipelineStageOutput::create(ProcessingStage::UnderstandObject, 1, ['floors' => 2]);

        self::assertNotSame($left->version, $right->version);
    }

    #[Test]
    public function output_rejects_oversized_or_non_json_payloads(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PipelineStageOutput::create(ProcessingStage::UnderstandObject, 1, [
            'content' => str_repeat('x', PipelineStageOutput::MAX_BYTES + 1),
        ]);
    }
}
