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

    public function test_generation_rebuilds_the_derived_building_model_before_starting_the_attempt(): void
    {
        $request = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Generation/RequestEstimateGeneration.php');

        self::assertIsString($request);
        self::assertStringContainsString('EloquentSessionBuildingModelBridge $buildingModels', $request);
        $rebuild = strpos($request, '$this->buildingModels->rebuildForGeneration(');
        $start = strpos($request, '$this->advance->generationStarted(');

        self::assertIsInt($rebuild);
        self::assertIsInt($start);
        self::assertLessThan($start, $rebuild);
    }

    public function test_generation_refresh_preserves_the_latest_user_confirmed_geometry(): void
    {
        $bridge = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/BuildingModel/EloquentSessionBuildingModelBridge.php');

        self::assertIsString($bridge);
        self::assertStringContainsString('public function rebuildForGeneration(', $bridge);
        self::assertStringContainsString("->where('evidence.source_type', 'user_input')", $bridge);
        self::assertStringContainsString("->where('evidence.producer_name', 'user_input_normalizer')", $bridge);
        self::assertStringContainsString("->whereNull('evidence.invalidated_at')", $bridge);
        self::assertStringContainsString('->preservesLatestModel(', $bridge);
        self::assertStringContainsString('$this->rebuild($sessionId);', $bridge);
    }

    public function test_document_processing_keeps_the_forced_building_model_rebuild(): void
    {
        $reconciler = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentUnitAggregateReconciler.php');

        self::assertIsString($reconciler);
        self::assertStringContainsString('$this->buildingModels->rebuild(', $reconciler);
        self::assertStringNotContainsString('rebuildForGeneration(', $reconciler);
    }
}
