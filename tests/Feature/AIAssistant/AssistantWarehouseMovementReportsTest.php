<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantWarehouseMovementReportsTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'app.timezone' => 'UTC']);
        Queue::fake();
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->actingAs($this->fixture->owner);
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::ADMIN);
    }

    public function test_source_exposes_movement_time_type_and_unit_with_real_authorization(): void
    {
        $fields = app(AssistantCustomReportSource::class)->resolve($this->fixture->owner, $this->fixture->organization->id, 'warehouse', 'warehouse_movement', null)['fields'];
        self::assertArrayHasKey('movement_date', $fields);
        self::assertSame('date', $fields['movement_date']['type']);
        self::assertStringContainsString('МСК', $fields['movement_date']['label']);
        self::assertArrayHasKey('movement_type', $fields);
        self::assertSame('string', $fields['movement_type']['type']);
        self::assertArrayHasKey('unit', $fields);
        self::assertSame('string', $fields['unit']['type']);
        self::assertArrayNotHasKey('price', $fields);
        self::assertArrayNotHasKey('metadata', $fields);
    }

    public static function storageTimezones(): array
    {
        return [
            'UTC storage' => ['UTC', '2026-09-30 21:00:00', '2026-10-22 20:59:59', '2026-09-30 20:59:59', '2026-10-22 21:00:00'],
            'Moscow storage' => ['Europe/Moscow', '2026-10-01 00:00:00', '2026-10-22 23:59:59', '2026-09-30 23:59:59', '2026-10-23 00:00:00'],
        ];
    }

    #[DataProvider('storageTimezones')]
    public function test_detail_filters_calendar_boundaries_and_exact_warehouse_without_losing_time_or_null_projects(string $timezone, string $start, string $end, string $before, string $after): void
    {
        config(['app.timezone' => $timezone]);
        [$warehouse, $material, $unit] = $this->inventory();
        $project = $this->project();
        $receipt = $this->movement($warehouse, $material, ['project_id' => $project->id, 'movement_date' => $start, 'quantity' => '1.125']);
        $outgoing = $this->movement($warehouse, $material, ['movement_type' => 'transfer_out', 'movement_date' => $end]);
        $reserved = $this->movement($warehouse, $material, ['movement_type' => 'unreservation']);
        $this->movement($warehouse, $material, ['movement_date' => $before]);
        $this->movement($warehouse, $material, ['movement_date' => $after]);
        [$otherWarehouse] = $this->inventory();
        $this->movement($otherWarehouse, $material, ['movement_type' => 'transfer_in', 'from_warehouse_id' => $warehouse->id]);
        [$foreignWarehouse, $foreignMaterial] = $this->inventory($this->fixture->foreignOrganization->id);
        $this->movement($foreignWarehouse, $foreignMaterial);

        $report = $this->build($warehouse);
        $rows = $report['sections'][0]['rows'];
        self::assertSame(3, $report['sections'][0]['source_row_count']);
        self::assertSame([$receipt->id, $outgoing->id, $reserved->id], array_column($rows, 0));
        self::assertSame('2026-10-01 00:00:00', $rows[0][1]);
        self::assertSame('2026-10-22 23:59:59', $rows[1][1]);
        self::assertSame('Поступление', $rows[0][2]);
        self::assertSame('Перемещение: расход', $rows[1][2]);
        self::assertSame('Снятие резерва', $rows[2][2]);
        self::assertSame('#'.$warehouse->id.' — '.$warehouse->name, $rows[0][3]);
        self::assertSame('#'.$material->id.' — '.$material->name, $rows[0][4]);
        self::assertSame('#'.$project->id.' — '.$project->name, $rows[0][5]);
        self::assertNull($rows[1][5]);
        self::assertSame('1.125', $rows[0][6]);
        self::assertSame($unit->short_name, $rows[0][7]);
        $reference = $report['source_refs'][0];
        self::assertNull($reference['project_id']);
        self::assertSame(['material' => [$material->id], 'measurement_unit' => [$unit->id]], $reference['_report_scope']['warehouse_projection_dependencies']);
        self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($this->fixture->owner, $this->fixture->organization->id, $reference));
    }

    public function test_real_assigned_project_acl_keeps_unlinked_rows_and_rechecks_unit_permission(): void
    {
        [$warehouse, $material] = $this->inventory();
        $visible = $this->project();
        $hidden = $this->project();
        $own = $this->movement($warehouse, $material, ['project_id' => $visible->id]);
        $unlinked = $this->movement($warehouse, $material);
        $this->movement($warehouse, $material, ['project_id' => $hidden->id]);
        $actor = $this->authorizedMember();
        $actor->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        $report = $this->build($warehouse, $actor);
        self::assertSame([$own->id, $unlinked->id], array_column($report['sections'][0]['rows'], 0));
        $reference = $report['source_refs'][0];
        self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($actor, $this->fixture->organization->id, $reference));
        $permissions = $this->fixture->memberRole->fresh()->module_permissions;
        $permissions['catalog-management'] = ['materials.view'];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
        self::assertFalse(app(AssistantCustomReportReference::class)->canReadReference($actor, $this->fixture->organization->id, $reference));
        $fields = app(AssistantCustomReportSource::class)->resolve($actor, $this->fixture->organization->id, 'warehouse', 'warehouse_movement', null)['fields'];
        self::assertArrayNotHasKey('unit', $fields);
    }

    public function test_signed_dependencies_are_validated_and_legacy_id_only_snapshots_still_work(): void
    {
        [$warehouse, $material, $unit] = $this->inventory();
        $this->movement($warehouse, $material);
        $reference = $this->build($warehouse)['source_refs'][0];
        $references = app(AssistantCustomReportReference::class);
        $scope = $reference['_report_scope'];
        $cases = [
            ['material' => [$material->id]],
            ['material' => [$material->id, $material->id], 'measurement_unit' => [$unit->id]],
            ['material' => [$material->id], 'measurement_unit' => [''.$unit->id]],
            ['material' => [$material->id], 'measurement_unit' => [999999999]],
        ];
        foreach ($cases as $dependencies) {
            $badScope = array_replace($scope, ['warehouse_projection_dependencies' => $dependencies]);
            $signed = $references->create($this->fixture->owner, $this->fixture->organization->id, $badScope,
                $reference['checked_fields'], $reference['_report_ids'], $reference['source_version'], now()->toISOString());
            self::assertFalse($references->canReadReference($this->fixture->owner, $this->fixture->organization->id, $signed));
        }
        unset($scope['warehouse_projection_dependencies']);
        $missing = $references->create($this->fixture->owner, $this->fixture->organization->id, $scope,
            $reference['checked_fields'], $reference['_report_ids'], $reference['source_version'], now()->toISOString());
        self::assertFalse($references->canReadReference($this->fixture->owner, $this->fixture->organization->id, $missing));
        $scope['plan']['columns'] = ['id'];
        $scope['plan']['filters'] = [['field' => 'warehouse_id', 'operator' => 'eq', 'value' => $warehouse->id]];
        $legacy = $references->create($this->fixture->owner, $this->fixture->organization->id, $scope,
            ['id', 'warehouse_id'], $reference['_report_ids'], $reference['source_version'], now()->toISOString());
        self::assertTrue($references->canReadReference($this->fixture->owner, $this->fixture->organization->id, $legacy));
    }

    public function test_unit_preserves_native_empty_short_name_and_foreign_catalog_data_is_not_exposed(): void
    {
        [$warehouse, $material, $unit] = $this->inventory();
        $unit->update(['short_name' => '']);
        $this->movement($warehouse, $material);
        [, $foreignMaterial, $foreignUnit] = $this->inventory($this->fixture->foreignOrganization->id);
        $foreignMaterial->update(['name' => 'SECRET чужой материал']);
        $foreignUnit->update(['short_name' => 'SECRET чужая единица']);
        $foreignMovement = $this->movement($warehouse, $foreignMaterial);
        $report = $this->build($warehouse);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertSame('', $report['sections'][0]['rows'][0][7]);
        $byId = array_column($report['sections'][0]['rows'], null, 0);
        self::assertNull($byId[$foreignMovement->id][7]);
        self::assertStringNotContainsString('SECRET', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_real_missing_warehouse_permission_denies_source_access(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        app(AssistantCustomReportSource::class)->resolve($this->fixture->member, $this->fixture->organization->id, 'warehouse', 'warehouse_movement', null);
    }

    public function test_quantity_totals_do_not_mix_units(): void
    {
        [$warehouse, $material] = $this->inventory();
        [, $secondMaterial] = $this->inventory(shortName: 'л');
        $this->movement($warehouse, $material);
        $this->movement($warehouse, $secondMaterial);
        $this->expectException(ValidationException::class);
        app(AssistantCustomReportBuilder::class)->build($this->plan($warehouse, ['columns' => [],
            'metrics' => [['field' => 'quantity', 'operation' => 'sum', 'label' => 'Объём']], 'order_by' => []]), $this->fixture->owner, $this->fixture->organization->id);
    }

    private function build(OrganizationWarehouse $warehouse, ?User $actor = null): array
    {
        return app(AssistantCustomReportBuilder::class)->build($this->plan($warehouse), $actor ?? $this->fixture->owner, $this->fixture->organization->id);
    }

    private function plan(OrganizationWarehouse $warehouse, array $overrides = []): array
    {
        return ['title' => 'Журнал движений', 'format' => 'csv', 'sections' => [array_replace([
            'title' => 'Операции', 'domain' => 'warehouse', 'entity_type' => 'warehouse_movement', 'scope' => 'organization', 'project_id' => null,
            'columns' => ['id', 'movement_date', 'movement_type', 'warehouse_id', 'material_id', 'project_id', 'quantity', 'unit'],
            'filters' => [['field' => 'warehouse_id', 'operator' => 'eq', 'value' => $warehouse->id],
                ['field' => 'movement_date', 'operator' => 'gte', 'value' => '2026-10-01'], ['field' => 'movement_date', 'operator' => 'lte', 'value' => '2026-10-22']],
            'group_by' => [], 'metrics' => [], 'order_by' => [['field' => 'id', 'direction' => 'asc']],
        ], $overrides)]];
    }

    private function authorizedMember(): User
    {
        $this->fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.analytics.view'],
            'basic-warehouse' => ['warehouse.view'], 'catalog-management' => ['materials.view', 'measurement_units.view'],
        ], 'system_permissions' => ['projects.view']]);

        return $this->fixture->member;
    }

    private function project(): Project
    {
        return Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
    }

    private function inventory(?int $organizationId = null, string $shortName = 'кг'): array
    {
        $organizationId ??= $this->fixture->organization->id;
        $warehouse = OrganizationWarehouse::query()->create(['organization_id' => $organizationId, 'name' => 'Склад отчёта',
            'code' => 'WH-'.bin2hex(random_bytes(6)), 'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL, 'is_main' => false, 'is_active' => true]);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $organizationId, 'short_name' => $shortName],
            ['name' => $shortName === 'кг' ? 'Килограмм' : 'Литр', 'type' => 'material']);
        $material = Material::query()->create(['organization_id' => $organizationId, 'name' => 'Смесь', 'code' => 'M-'.bin2hex(random_bytes(6)),
            'measurement_unit_id' => $unit->id, 'is_active' => true]);

        return [$warehouse, $material, $unit];
    }

    private function movement(OrganizationWarehouse $warehouse, Material $material, array $overrides = []): WarehouseMovement
    {
        return WarehouseMovement::query()->create(array_replace(['organization_id' => $warehouse->organization_id, 'warehouse_id' => $warehouse->id,
            'material_id' => $material->id, 'project_id' => null, 'movement_type' => 'receipt', 'quantity' => '2.500', 'price' => 10,
            'movement_date' => '2026-10-15 12:34:56'], $overrides));
    }
}
