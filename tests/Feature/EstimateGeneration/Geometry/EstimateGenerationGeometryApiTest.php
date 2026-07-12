<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Geometry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationGeometryApiTest extends TestCase
{
    #[Test]
    public function geometry_confirmation_route_uses_review_permission(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::post('/{session}/geometry/confirm'", $routes);
        self::assertStringContainsString("middleware('authorize:estimate_generation.review,project,project')->name('geometry.confirm')", $routes);
        self::assertLessThan(strpos($routes, "Route::get('/{session}',"), strpos($routes, "Route::post('/{session}/geometry/confirm'"));
    }

    #[Test]
    public function geometry_confirmation_has_closed_versioned_request_contract(): void
    {
        $request = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Http/Requests/ConfirmEstimateGenerationGeometryRequest.php');

        self::assertIsString($request);
        self::assertStringContainsString("'state_version' => ['required', 'integer', 'min:0']", $request);
        self::assertStringContainsString("'model_version' => ['required', 'string'", $request);
        self::assertStringContainsString("'input_version' => ['required', 'string'", $request);
        self::assertStringContainsString("'operations.*' => ['array:op,path,value']", $request);
    }

    #[Test]
    public function response_translations_and_recoverable_outbox_are_declared(): void
    {
        $translations = require dirname(__DIR__, 4).'/lang/ru/estimate_generation.php';
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_000100_create_geometry_regeneration_outbox_table.php');
        $command = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Geometry/ConfirmBuildingGeometry.php');

        self::assertNotSame('', $translations['geometry_confirmed'] ?? '');
        self::assertNotSame('', $translations['geometry_invalid'] ?? '');
        self::assertIsString($migration);
        self::assertStringContainsString('idempotency_key', $migration);
        self::assertStringContainsString("'pending','delivering','delivered','failed'", $migration);
        self::assertIsString($command);
        self::assertStringNotContainsString('ApplyGeneratedEstimate', $command);
        self::assertStringNotContainsString("table('estimates')", $command);
    }
}
