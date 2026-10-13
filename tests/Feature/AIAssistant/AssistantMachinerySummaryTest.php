<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectMachineryReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\MachineryOperationsAssetFactory;
use Tests\TestCase;

final class AssistantMachinerySummaryTest extends TestCase
{
    public function test_machinery_project_scope_supports_canonical_and_legacy_links_and_complete_empty_proof(): void
    {
        Queue::fake(); config(['cache.default' => 'array', 'asset_registry.strict_canonical_reads' => false]);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $reader = app(AssistantProjectMachineryReader::class);
        $empty = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(0, $empty['data']['asset_count']);
        self::assertNotNull($reader->verifiedAnswer($empty, $fixture->owner, $fixture->organization->id));
        $catalogue = Model::withoutEvents(function () use ($fixture) {
            $record = new \App\Models\Machinery;
            $record->forceFill(['id' => 99000, 'organization_id' => $fixture->organization->id, 'code' => 'CAT-DIRECTOR', 'name' => 'Каталог техники',
                'hourly_rate' => '77777.00', 'is_active' => true])->save();
            return $record;
        });
        $canonical = MachineryOperationsAssetFactory::create($fixture->organization->id, ['asset_code' => 'CAN-DIRECTOR', 'name' => 'Каноническая техника',
            'current_project_id' => $project->id, 'machinery_id' => $catalogue->id]);
        $catalogue->deleteQuietly();
        $canonical->updateQuietly(['current_project_id' => null]);
        $legacy = MachineryAsset::withoutEvents(fn () => MachineryAsset::query()->create(['organization_id' => $fixture->organization->id,
            'current_project_id' => $project->id, 'asset_code' => 'LEG-DIRECTOR', 'name' => 'Техника карточки', 'status' => 'available']));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        MachineryOperationsAssetFactory::create($fixture->organization->id, ['asset_code' => 'OTHER-DIRECTOR', 'name' => 'Другой проект', 'current_project_id' => $other->id]);
        Model::withoutEvents(function () use ($fixture, $project, $canonical): void {
            MachineryAssignment::query()->create(['organization_id' => $fixture->organization->id, 'organization_asset_id' => $canonical->organization_asset_id,
                'project_id' => $project->id, 'asset_id' => $canonical->id, 'status' => 'active', 'planned_start_at' => now()->subDay(), 'planned_end_at' => now()->addDay()]);
            MachineryDowntime::query()->create(['organization_id' => $fixture->organization->id, 'organization_asset_id' => $canonical->organization_asset_id,
                'project_id' => $project->id, 'asset_id' => $canonical->id, 'reason' => 'Нет фронта работ', 'started_at' => now()->subHour(), 'ended_at' => null]);
            MachineryMaintenanceOrder::query()->create(['organization_id' => $fixture->organization->id, 'organization_asset_id' => $canonical->organization_asset_id,
                'project_id' => $project->id, 'asset_id' => $canonical->id, 'order_number' => 'MAINT-DIRECTOR', 'title' => 'Ремонт', 'maintenance_type' => 'repair', 'status' => 'open', 'priority' => 'high']);
        });
        $read = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(2, $read['data']['asset_count']);
        self::assertStringNotContainsString('77777.00', json_encode($read));
        foreach (['machinery_assignment', 'machinery_downtime', 'machinery_maintenance_order'] as $type) { self::assertSame(1, $read['data']['sections'][$type]['total']); }
        self::assertStringNotContainsString('Другой проект', json_encode($read, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('зарегистрированных', mb_strtolower($read['server_formatted_answer']));
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($fixture->owner, $fixture->organization->id, $read['source_refs']));
        $generic = app(AssistantDomainReadService::class)->execute('search', ['domain' => 'machinery', 'entity_type' => 'machinery_asset', 'project_id' => $project->id,
            'query' => '', 'limit' => 20, 'fields' => ['name', 'status', 'current_project_id']], $fixture->owner, $fixture->organization->id);
        self::assertEqualsCanonicalizing([$legacy->id, $canonical->id], array_column($generic['results'], 'id'));
        foreach (array_filter($generic['source_refs'], static fn (array $ref): bool => $ref['entity_type'] === 'machinery_asset') as $reference) {
            self::assertSame($project->id, $reference['project_id']);
            self::assertSame($project->id, $reference['machinery_project_scope']);
        }
        self::assertTrue((new \App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope(true, $project->id))->allowsReferences($generic['source_refs']));
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($fixture->owner, $fixture->organization->id, $generic['source_refs']));
        config(['asset_registry.strict_canonical_reads' => true]);
        $read = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(1, $read['data']['asset_count']);
        self::assertTrue($reader->matchesReference($fixture->owner, $fixture->organization->id, $read['source_refs'][0]));
        $canonical->organizationAsset->updateQuietly(['technical_status' => 'maintenance']);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $read['source_refs'][0]));
        $foreignCatalogue = Model::withoutEvents(function () use ($fixture) {
            $record = new \App\Models\Machinery;
            $record->forceFill(['id' => 99001, 'organization_id' => $fixture->foreignOrganization->id, 'code' => 'FOREIGN-CAT', 'name' => 'Чужой каталог'])->save();
            return $record;
        });
        $canonical->organizationAsset->updateQuietly(['machinery_id' => $foreignCatalogue->id]);
        self::assertSame(0, $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id])['data']['asset_count']);
    }
}
