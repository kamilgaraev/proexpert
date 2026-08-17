<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryOperationRecordResource;
use App\BusinessModules\Features\MachineryOperations\Models\AssetRequest;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\Models\Project;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Http\Request;

final class MachineryAssignmentResourceTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_assignment_resource_exposes_stable_request_provenance_and_project(): void
    {
        $assetRequest = new AssetRequest([
            'origin_type' => 'site_request',
            'site_request_id' => 42,
            'purpose' => 'Вывоз грунта',
        ]);
        $assetRequest->id = 9;
        $assignment = new MachineryAssignment([
            'asset_request_id' => 9,
            'status' => 'active',
            'planned_start_at' => '2026-08-18 09:00:00',
            'planned_end_at' => '2026-08-18 18:00:00',
        ]);
        $assignment->id = 3;
        $assignment->setRelation('assetRequest', $assetRequest);
        $assignment->setRelation('project', new Project(['name' => 'Клубника']));

        $payload = (new MachineryOperationRecordResource($assignment))->toArray(Request::create('/'));

        self::assertSame(9, $payload['asset_request_id']);
        self::assertSame('site_request', $payload['origin_type']);
        self::assertSame('42', $payload['request_number']);
        self::assertSame(42, $payload['site_request_id']);
        self::assertSame('Клубника', $payload['project']['name']);
    }

    public function test_legacy_assignment_keeps_provenance_nullable(): void
    {
        $assignment = new MachineryAssignment(['status' => 'completed']);
        $assignment->id = 4;

        $payload = (new MachineryOperationRecordResource($assignment))->toArray(Request::create('/'));

        self::assertNull($payload['asset_request_id']);
        self::assertNull($payload['origin_type']);
        self::assertNull($payload['request_number']);
    }
}
