<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehousePassportService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentInput;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRelationSnapshot;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WarehousePassportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_passport_is_filterable_and_can_be_linked_to_itd_only_in_its_project(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Основной склад',
            'code' => 'WH-PASSPORT',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Штука',
            'short_name' => 'шт-паспорт',
            'type' => 'material',
        ]);
        $material = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Бетон B25',
            'code' => 'B25',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $receipt = $this->receipt($organization->id, $project->id, $warehouse->id, $material->id, 'П-1');
        $missing = $this->receipt($organization->id, $project->id, $warehouse->id, $material->id, 'П-2');

        $fileService = $this->mock(FileService::class);
        $fileService->shouldReceive('upload')->twice()->andReturn('org-'.$organization->id.'/warehouse/movements/'.$receipt->id.'/passport/file.pdf');
        $fileService->shouldReceive('temporaryUrl')->andReturn('https://example.test/passport.pdf');
        $this->mock(AuthorizationService::class)->shouldReceive('can')->with($user, 'warehouse.receipts', ['organization_id' => $organization->id])->andReturn(true);
        $passports = app(WarehousePassportService::class);
        $uploaded = $passports->upload($organization->id, $receipt->id, UploadedFile::fake()->create('passport.pdf', 1, 'application/pdf'), $user);
        $again = $passports->upload($organization->id, $receipt->id, UploadedFile::fake()->create('passport.pdf', 1, 'application/pdf'), $user);

        self::assertSame($uploaded['id'], $again['id']);
        self::assertSame('passport.pdf', $uploaded['original_name']);
        self::assertCount(1, $passports->references($organization->id, $project->id));
        self::assertSame([], $passports->references($organization->id, $otherProject->id));

        $warehouseService = app(WarehouseService::class);
        $withPassport = $warehouseService->paginateMovementsData($organization->id, ['warehouse_id' => $warehouse->id, 'movement_type' => 'receipt', 'has_passport' => true]);
        $withoutPassport = $warehouseService->paginateMovementsData($organization->id, ['warehouse_id' => $warehouse->id, 'movement_type' => 'receipt', 'has_passport' => false]);
        self::assertSame(1, $withPassport->total());
        self::assertSame($receipt->id, $withPassport->items()[0]['movement_id']);
        self::assertSame(1, $withoutPassport->total());
        self::assertSame($missing->id, $withoutPassport->items()[0]['movement_id']);

        $set = $this->set($organization->id, $project->id, $user->id);
        $relation = ['relation_type' => 'quality_documents', 'target_type' => 'warehouse_passport', 'target_id' => $uploaded['id']];
        $input = app(ExecutiveDocumentInput::class);
        $normalized = $input->normalize($this->documentInput($relation), $set);
        self::assertSame($relation, $normalized['relations'][0]);

        $document = ExecutiveDocument::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $user->id,
            'document_type' => 'hidden_work_act',
            'title' => 'Акт скрытых работ',
            'status' => ExecutiveDocumentStatusEnum::DRAFT,
        ]);
        $document->relations()->create($relation + ['organization_id' => $organization->id]);
        $snapshot = app(ExecutiveDocumentRelationSnapshot::class)->forDocument($document);
        self::assertSame('Паспорт материала Бетон B25, партия BATCH-42 (passport.pdf)', $snapshot[0]['domain_snapshot']['title']);

        $centralReceipt = $this->receipt($organization->id, null, $warehouse->id, $material->id, 'П-3');
        $centralPassport = $passports->upload($organization->id, $centralReceipt->id, UploadedFile::fake()->create('central.pdf', 1, 'application/pdf'), $user);
        self::assertCount(1, $passports->references($organization->id, $otherProject->id));
        $otherSet = $this->set($organization->id, $otherProject->id, $user->id);
        $centralRelation = ['relation_type' => 'quality_documents', 'target_type' => 'warehouse_passport', 'target_id' => $centralPassport['id']];
        self::assertSame($centralRelation, $input->normalize($this->documentInput($centralRelation), $otherSet)['relations'][0]);

        $this->expectException(ValidationException::class);
        $input->normalize($this->documentInput($relation), $otherSet);
    }

    public function test_passport_upload_requires_current_organization(): void
    {
        $user = User::factory()->create();
        $this->expectException(BusinessLogicException::class);
        app(WarehousePassportService::class)->upload(999999, 1, UploadedFile::fake()->create('passport.pdf', 1, 'application/pdf'), $user);
    }

    private function receipt(int $organizationId, ?int $projectId, int $warehouseId, int $materialId, string $number): WarehouseMovement
    {
        return WarehouseMovement::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 1,
            'price' => 100,
            'document_number' => $number,
            'metadata' => ['batch_number' => 'BATCH-42'],
            'movement_date' => now(),
        ]);
    }

    private function set(int $organizationId, int $projectId, int $userId): ExecutiveDocumentSet
    {
        return ExecutiveDocumentSet::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'created_by' => $userId,
            'set_number' => 'ИД-'.$projectId,
            'title' => 'Комплект ИД',
            'status' => ExecutiveDocumentStatusEnum::DRAFT,
        ]);
    }

    private function documentInput(array $relation): array
    {
        return [
            'document_type' => 'hidden_work_act',
            'title' => 'Акт скрытых работ',
            'profile_data' => [
                'act_number' => 'АОСР-1',
                'presented_works' => 'Армирование',
                'started_at' => '2026-09-01',
                'finished_at' => '2026-09-02',
                'next_works_permission' => 'Разрешено',
            ],
            'relations' => [$relation],
        ];
    }
}
