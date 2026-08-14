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

    public function test_generation_uses_the_canonical_project_model_without_a_legacy_rebuild(): void
    {
        $request = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Generation/RequestEstimateGeneration.php');

        self::assertIsString($request);
        self::assertStringNotContainsString('SessionBuildingModelBridge', $request);
        self::assertStringNotContainsString('rebuildForGeneration(', $request);
        self::assertStringContainsString('$this->advance->generationStarted(', $request);
    }

    public function test_legacy_building_model_bridge_is_removed(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/BuildingModel/EloquentSessionBuildingModelBridge.php');
    }

    public function test_document_processing_does_not_write_a_second_building_model(): void
    {
        $reconciler = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentUnitAggregateReconciler.php');

        self::assertIsString($reconciler);
        self::assertStringNotContainsString('buildingModels', $reconciler);
        self::assertStringNotContainsString('SessionBuildingModelBridge', $reconciler);
        self::assertStringNotContainsString('rebuildForGeneration(', $reconciler);
    }
}
