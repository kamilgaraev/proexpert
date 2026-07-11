<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use PHPUnit\Framework\TestCase;

final class GenerationEntrypointsContractTest extends TestCase
{
    public function test_analyze_and_rebuild_enter_the_same_generation_pipeline(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Generation/';
        $analyze = file_get_contents($root.'AnalyzeEstimateGenerationSession.php');
        $rebuild = file_get_contents($root.'RebuildGeneratedSection.php');

        self::assertIsString($analyze);
        self::assertStringContainsString('RequestEstimateGeneration $generation', $analyze);
        self::assertStringContainsString('$this->generation->handle(', $analyze);
        self::assertFileDoesNotExist($root.'AnalyzeGenerationInput.php');
        self::assertStringNotContainsString('ConstructionSemanticParser', $analyze);
        self::assertIsString($rebuild);
        self::assertStringContainsString('GenerateEstimateDraftJob::dispatch(', $rebuild);
        self::assertStringContainsString('generationStarted(', $rebuild);
    }
}
