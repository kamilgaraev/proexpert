<?php

declare(strict_types=1);

namespace Tests\Feature\Mdm;

use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Core\Mdm\Models\MdmRelationship;
use App\BusinessModules\Core\Mdm\Services\MdmDuplicateDetectionService;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use App\BusinessModules\Core\Mdm\Services\MdmRelationshipService;
use App\Models\Contractor;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\WorkType;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MdmCoreWorkflowTest extends TestCase
{
    public function test_records_are_synced_with_quality_and_duplicate_groups(): void
    {
        $context = AdminApiTestContext::create();

        $first = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Строй',
            'inn' => '7701000001',
            'kpp' => '770101001',
            'email' => 'first@example.test',
        ]);
        $second = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => '  ооо строй ',
            'inn' => '77 01 000001',
            'kpp' => '7701-01001',
            'email' => 'second@example.test',
        ]);

        app(MdmRecordService::class)->syncModel($first, 'contractor');
        app(MdmRecordService::class)->syncModel($second, 'contractor');
        $result = app(MdmDuplicateDetectionService::class)->scanOrganization($context->organization->id, 'contractor');

        $this->assertSame(1, $result['groups_created']);
        $this->assertDatabaseHas('mdm_records', [
            'organization_id' => $context->organization->id,
            'entity_type' => 'contractor',
            'entity_id' => $first->id,
            'normalized_key' => 'contractor:7701000001:770101001',
        ]);
        $this->assertSame(1, MdmDuplicateGroup::query()->where('entity_type', 'contractor')->count());
    }

    public function test_material_work_type_relationships_are_indexed(): void
    {
        $context = AdminApiTestContext::create();

        $unit = MeasurementUnit::create([
            'organization_id' => $context->organization->id,
            'name' => 'Тестовая единица MDM',
            'short_name' => 'mdm-test-unit',
            'type' => 'material',
        ]);
        $material = Material::create([
            'organization_id' => $context->organization->id,
            'name' => 'Кирпич',
            'code' => 'BR-1',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $workType = WorkType::create([
            'organization_id' => $context->organization->id,
            'name' => 'Кладка',
            'code' => 'WORK-1',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);

        DB::table('work_type_materials')->insert([
            'organization_id' => $context->organization->id,
            'work_type_id' => $workType->id,
            'material_id' => $material->id,
            'default_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(MdmRelationshipService::class)->syncOrganization($context->organization->id);

        $this->assertGreaterThanOrEqual(1, $result['relationships_synced']);
        $this->assertDatabaseHas('mdm_relationships', [
            'organization_id' => $context->organization->id,
            'source_type' => 'work_type',
            'source_id' => $workType->id,
            'target_type' => 'material',
            'target_id' => $material->id,
            'relationship_type' => 'uses_material',
        ]);
        $this->assertSame(1, MdmRelationship::query()->count());
    }

    public function test_admin_mdm_dashboard_endpoint_returns_catalog_health(): void
    {
        $context = AdminApiTestContext::create();

        Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Строй',
            'inn' => '7701000001',
            'kpp' => '770101001',
        ]);

        app(MdmRecordService::class)->syncOrganization($context->organization->id, 'contractor');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/mdm/dashboard');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.entities.0.type', 'contractor');
        $response->assertJsonPath('data.entities.0.title', 'Контрагенты');
        $response->assertJsonPath('data.entities.0.source_total', 1);
        $response->assertJsonPath('data.entities.0.mdm_total', 1);
        $response->assertJsonPath('data.entities.0.coverage_percent', 100);

        $record = MdmRecord::query()
            ->where('organization_id', $context->organization->id)
            ->where('entity_type', 'contractor')
            ->firstOrFail();
        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/mdm/records/{$record->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.entity_type', 'contractor');
    }

    public function test_duplicate_decision_archive_history_and_import_preview_are_available_via_admin_api(): void
    {
        $context = AdminApiTestContext::create();

        $first = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Север',
            'inn' => '7701000005',
            'kpp' => '770101001',
        ]);
        $second = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Север Дубль',
            'inn' => '7701 000005',
            'kpp' => '7701 01001',
        ]);

        app(MdmDuplicateDetectionService::class)->scanOrganization($context->organization->id, 'contractor');

        $group = MdmDuplicateGroup::query()->firstOrFail();
        $resolveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/duplicates/{$group->id}/resolve", [
                'decision' => 'resolved',
                'master_entity_id' => $first->id,
                'note' => 'Проверено в тесте',
            ]);

        $resolveResponse->assertOk();
        $resolveResponse->assertJsonPath('success', true);
        $this->assertDatabaseHas('mdm_duplicate_groups', [
            'id' => $group->id,
            'status' => 'resolved',
            'suggested_master_entity_id' => $first->id,
        ]);

        $archiveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/records/contractor/{$second->id}/archive", [
                'reason' => 'Дубль',
            ]);

        $archiveResponse->assertOk();
        $archiveResponse->assertJsonPath('success', true);
        $this->assertDatabaseHas('mdm_records', [
            'entity_type' => 'contractor',
            'entity_id' => $second->id,
            'status' => 'archived',
        ]);

        $historyResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/mdm/history?entity_type=contractor&entity_id={$second->id}");

        $historyResponse->assertOk();
        $historyResponse->assertJsonPath('success', true);
        $this->assertContains('archived', collect($historyResponse->json('data'))->pluck('action')->all());

        $importResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/imports/preview', [
                'entity_type' => 'contractor',
                'rows' => [
                    ['name' => 'ООО Импорт', 'inn' => '7701000006', 'kpp' => '770101001'],
                    ['name' => '', 'inn' => '123'],
                ],
            ]);

        $importResponse->assertOk();
        $importResponse->assertJsonPath('success', true);
        $importResponse->assertJsonPath('data.total_rows', 2);
        $importResponse->assertJsonPath('data.accepted_rows', 1);
        $importResponse->assertJsonPath('data.rejected_rows', 1);
    }

    public function test_change_approval_owner_assignment_and_import_apply_work_via_admin_api(): void
    {
        $context = AdminApiTestContext::create();

        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Запад',
            'inn' => '7701000007',
            'kpp' => '770101001',
        ]);

        $record = app(MdmRecordService::class)->syncModel($contractor, 'contractor');

        $ownerResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/records/{$record->id}/owner", [
                'owner_user_id' => $context->user->id,
            ]);

        $ownerResponse->assertOk();
        $ownerResponse->assertJsonPath('success', true);
        $this->assertDatabaseHas('mdm_records', [
            'id' => $record->id,
            'owner_user_id' => $context->user->id,
        ]);

        $submitResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/change-requests', [
                'entity_type' => 'contractor',
                'entity_id' => $contractor->id,
                'action' => 'update',
                'proposed_values' => [
                    'name' => 'ООО Запад Обновленный',
                    'inn' => '7701000007',
                    'kpp' => '770101001',
                ],
            ]);

        $submitResponse->assertCreated();
        $submitResponse->assertJsonPath('success', true);

        $changeRequestId = $submitResponse->json('data.id');
        $reviewResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/change-requests/{$changeRequestId}/review", [
                'decision' => 'approved',
                'note' => 'Согласовано',
            ]);

        $reviewResponse->assertOk();
        $reviewResponse->assertJsonPath('success', true);
        $this->assertDatabaseHas('contractors', [
            'id' => $contractor->id,
            'name' => 'ООО Запад Обновленный',
        ]);
        $this->assertDatabaseHas('mdm_change_requests', [
            'id' => $changeRequestId,
            'status' => 'approved',
        ]);

        $applyResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/imports/apply', [
                'entity_type' => 'contractor',
                'rows' => [
                    ['name' => 'ООО Импорт Применен', 'inn' => '7701000008', 'kpp' => '770101001'],
                    ['name' => '', 'inn' => '123'],
                ],
            ]);

        $applyResponse->assertOk();
        $applyResponse->assertJsonPath('success', true);
        $applyResponse->assertJsonPath('data.status', 'applied');
        $applyResponse->assertJsonPath('data.accepted_rows', 1);
        $applyResponse->assertJsonPath('data.rejected_rows', 1);
        $this->assertDatabaseHas('contractors', [
            'organization_id' => $context->organization->id,
            'name' => 'ООО Импорт Применен',
            'inn' => '7701000008',
        ]);
    }

    public function test_import_validation_errors_are_not_masked_as_server_errors(): void
    {
        $context = AdminApiTestContext::create();

        foreach ([
            '/api/v1/admin/mdm/imports/preview',
            '/api/v1/admin/mdm/imports/apply',
        ] as $endpoint) {
            $response = $this->withHeaders($context->authHeaders())
                ->postJson($endpoint, []);

            $response->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Заполните поле «тип справочника».')
                ->assertJsonPath('errors.entity_type.0', 'Заполните поле «тип справочника».')
                ->assertJsonPath('errors.rows.0', 'Заполните поле «строки импорта».');
        }
    }
}
