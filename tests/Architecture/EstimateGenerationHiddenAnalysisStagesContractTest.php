<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class EstimateGenerationHiddenAnalysisStagesContractTest extends TestCase
{
    public function test_geometry_and_project_model_are_internal_and_only_basis_read_api_is_exposed(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/{session}/analysis-basis'", $routes);
        self::assertStringContainsString('authorize:estimate_generation.view,project,project', $routes);
        foreach (['/geometry', '/building-model', '/project-model/review', '/project-model/corrections'] as $obsolete) {
            self::assertStringNotContainsString($obsolete, $routes);
        }
    }

    public function test_obsolete_second_model_and_manual_confirmation_production_paths_are_absent(): void
    {
        $root = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';
        foreach ([
            '/BuildingModel/BuildingModelStore.php',
            '/BuildingModel/EloquentBuildingModelStore.php',
            '/BuildingModel/EloquentSessionBuildingModelBridge.php',
            '/Application/Geometry/ConfirmBuildingGeometry.php',
            '/Http/Controllers/EstimateGenerationGeometryController.php',
            '/Http/Controllers/EstimateGenerationBuildingModelController.php',
            '/Http/Controllers/EstimateGenerationProjectModelCorrectionController.php',
        ] as $obsolete) {
            self::assertFileDoesNotExist($root.$obsolete);
        }

        $gateway = file_get_contents($root.'/Pipeline/EloquentGenerationPipelineDataGateway.php');
        $resolver = file_get_contents($root.'/Pipeline/EvidenceAwarePipelineBaseInputVersionResolver.php');
        self::assertIsString($gateway);
        self::assertIsString($resolver);
        self::assertStringContainsString('CanonicalProjectModelPipelineProjection', $gateway);
        self::assertStringContainsString('CanonicalProjectModelPipelineProjection', $resolver);
        self::assertStringNotContainsString('BuildingModelReadDataSource', $gateway.$resolver);
    }
}
