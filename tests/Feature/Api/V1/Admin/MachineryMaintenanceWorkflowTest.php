<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Features\MachineryOperations\Models\MaintenanceInspection;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Models\Project;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryMaintenanceWorkflowTest extends TestCase
{
    public function test_critical_defect_blocks_asset_and_only_serviceable_inspection_releases_it(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $service = $this->app->make(MachineryOperationsService::class);
        $asset = $service->createAsset((int) $context->organization->id, [
            'asset_code' => 'DEFECT-1',
            'name' => 'Виброплита',
            'current_project_id' => $project->id,
        ]);
        $service->reportDefect((int) $context->organization->id, (int) $context->user->id, [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'defect_code' => 'engine_failure',
            'severity' => 'critical',
            'description' => 'Двигатель не запускается',
        ]);

        self::assertSame('unavailable', $asset->fresh()->status);
        self::assertSame(AssetTechnicalStatus::Unavailable, $asset->organizationAsset->fresh()->technical_status);

        $first = $service->createMaintenanceOrder((int) $context->organization->id, (int) $context->user->id, [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'title' => 'Диагностика',
        ]);
        $service->completeMaintenanceOrder($first, (int) $context->user->id, 'Требуется ремонт', 'unavailable');
        self::assertSame('unavailable', $asset->fresh()->status);
        self::assertSame('unavailable', MaintenanceInspection::query()->where('maintenance_order_id', $first->id)->value('result'));

        $second = $service->createMaintenanceOrder((int) $context->organization->id, (int) $context->user->id, [
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'title' => 'Ремонт двигателя',
        ]);
        $service->completeMaintenanceOrder($second, (int) $context->user->id, 'Исправна', 'serviceable');
        self::assertSame('available', $asset->fresh()->status);
        self::assertSame(AssetTechnicalStatus::Serviceable, $asset->organizationAsset->fresh()->technical_status);
    }
}
