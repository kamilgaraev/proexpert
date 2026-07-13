<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotEtag;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ConfirmEstimateGenerationGeometryRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationGeometryApiTest extends TestCase
{
    #[Test]
    public function request_contract_requires_all_three_cas_versions_and_closed_operations(): void
    {
        $rules = (new ConfirmEstimateGenerationGeometryRequest)->rules();

        self::assertContains('required', $rules['state_version']);
        self::assertContains('required', $rules['model_version']);
        self::assertContains('required', $rules['input_version']);
        self::assertSame(['array:op,path,value'], $rules['operations.*']);
        self::assertContains('in:replace', $rules['operations.*.op']);
    }

    #[Test]
    public function geometry_review_route_is_read_only_and_separate_from_confirmation(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/{session}/geometry'", $routes);
        self::assertStringContainsString("Route::post('/{session}/geometry/confirm'", $routes);
    }

    #[Test]
    public function snapshot_etag_is_tenant_scoped_and_supports_conditional_semantics(): void
    {
        $etag = SessionSnapshotEtag::forRevision(10, 20, 'revision-1');

        self::assertTrue(SessionSnapshotEtag::matches($etag, $etag));
        self::assertTrue(SessionSnapshotEtag::matches('W/'.$etag, $etag));
        self::assertFalse(SessionSnapshotEtag::matches(SessionSnapshotEtag::forRevision(11, 20, 'revision-1'), $etag));
        self::assertFalse(SessionSnapshotEtag::matches(SessionSnapshotEtag::forRevision(10, 20, 'revision-2'), $etag));
    }

    #[Test]
    public function geometry_response_translation_keys_are_non_empty(): void
    {
        $translations = require dirname(__DIR__, 4).'/lang/ru/estimate_generation.php';

        foreach (['geometry_confirmed', 'geometry_invalid', 'geometry_not_found', 'geometry_error'] as $key) {
            self::assertIsString($translations[$key] ?? null);
            self::assertNotSame('', trim($translations[$key]));
        }
    }
}
