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
        $canonical->updateQuietly(['status' => 'assigned']);
        $expired = Model::withoutEvents(fn () => MachineryAssignment::query()->create([
            'organization_id' => $fixture->organization->id, 'organization_asset_id' => $canonical->organization_asset_id,
            'project_id' => $project->id, 'asset_id' => $canonical->id, 'status' => 'active',
            'planned_start_at' => now()->subDays(3), 'planned_end_at' => now()->subDays(2),
        ]));
        $otherAssignment = Model::withoutEvents(fn () => MachineryAssignment::query()->create([
            'organization_id' => $fixture->organization->id, 'organization_asset_id' => $canonical->organization_asset_id,
            'project_id' => $other->id, 'asset_id' => $canonical->id, 'status' => 'active',
            'planned_start_at' => now()->subDays(5), 'planned_end_at' => now()->subDays(4),
        ]));
        $read = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertCount(2, $read['data']['assignment_history']['items']);
        self::assertTrue($read['data']['assignment_history']['complete']);
        self::assertNotContains($otherAssignment->id, array_column($read['data']['assignment_history']['items'], 'id'));
        self::assertStringContainsString($expired->planned_end_at->toDateString(), $read['server_formatted_answer']);
        self::assertStringContainsString('фактическое окончание: не зафиксировано', $read['server_formatted_answer']);
        self::assertStringContainsString('нельзя считать свободной', $read['server_formatted_answer']);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $empty['source_refs'][0]));
        \Illuminate\Support\Facades\DB::table('machinery_assignments')->where('id', $expired->id)->update(['actual_end_at' => now()]);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $read['source_refs'][0]));
        $read = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
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
        config(['asset_registry.strict_canonical_reads' => false]);
        $targetProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        for ($index = 0; $index < 21; $index++) {
            MachineryAsset::withoutEvents(fn () => MachineryAsset::query()->create(['organization_id' => $fixture->organization->id,
                'current_project_id' => $targetProject->id, 'asset_code' => 'EARLIER-'.$index, 'name' => 'Другая техника', 'status' => 'available']));
        }
        $target = MachineryOperationsAssetFactory::create($fixture->organization->id, ['asset_code' => 'TARGET-335', 'name' => 'Адресная техника',
            'current_project_id' => $targetProject->id, 'status' => 'assigned']);
        $targetAssignment = Model::withoutEvents(fn () => MachineryAssignment::query()->create([
            'organization_id' => $fixture->organization->id, 'organization_asset_id' => $target->organization_asset_id,
            'project_id' => $targetProject->id, 'asset_id' => $target->id, 'status' => 'active',
            'planned_start_at' => now()->subDays(3), 'planned_end_at' => now()->subDays(2),
        ]));
        $overview = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $targetProject->id]);
        self::assertSame(22, $overview['data']['asset_count']);
        self::assertNotContains($target->id, array_column($overview['data']['assets'], 'id'));
        $targetRead = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $targetProject->id, 'asset_code' => 'TARGET-335']);
        self::assertSame(1, $targetRead['data']['asset_count']);
        self::assertSame($target->id, $targetRead['data']['assets'][0]['id']);
        self::assertSame('assigned', $targetRead['data']['assets'][0]['status']);
        self::assertSame(0, $targetRead['data']['sections']['machinery_assignment']['total']);
        self::assertNull($target->currentAssignment()->first());
        self::assertSame($targetAssignment->id, $targetRead['data']['assignment_history']['items'][0]['id']);
        self::assertStringContainsString($targetAssignment->planned_end_at->toDateString(), $targetRead['server_formatted_answer']);
        self::assertStringContainsString('нельзя считать свободной', $targetRead['server_formatted_answer']);
        self::assertSame($targetRead['server_formatted_answer'], $reader->verifiedAnswer($targetRead, $fixture->owner, $fixture->organization->id)['text']);
        $byId = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $targetProject->id, 'asset_id' => $target->id]);
        self::assertSame($target->id, $byId['data']['assets'][0]['id']);
        $lowercase = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $targetProject->id, 'asset_code' => 'target-335']);
        self::assertSame(1, $lowercase['data']['asset_count']);
        self::assertSame($target->id, $lowercase['data']['assets'][0]['id']);
        self::assertNotNull($reader->verifiedAnswer($lowercase, $fixture->owner, $fixture->organization->id));
        self::assertSame(0, $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $other->id, 'asset_id' => $target->id])['data']['asset_count']);
        $forged = $targetRead['source_refs'][0]; $forged['asset_filter'] = ['asset_id' => $legacy->id];
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $forged));
        $forged['asset_filter'] = ['project_id' => $other->id];
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $forged));
        \Illuminate\Support\Facades\DB::table('machinery_assignments')->where('id', $targetAssignment->id)->update(['actual_end_at' => now()]);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $targetRead['source_refs'][0]));
    }
}
