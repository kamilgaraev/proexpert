<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Services\MdmDuplicateDetectionService;
use App\BusinessModules\Core\Mdm\Services\MdmImportService;
use App\BusinessModules\Core\Mdm\Services\MdmMergeService;
use App\Models\CostCategory;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\WorkType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MdmTenantReferenceSecurityTest extends TestCase
{
    public function test_rows_and_csv_preview_report_foreign_references_as_rejected_rows(): void
    {
        [$actor, $ownUnit, $foreignUnit] = $this->catalog();
        foreach (['rows', 'file'] as $source) {
            $this->import($actor, $source, [
                $this->row('PREVIEW-VALID-'.$source, $ownUnit), $this->row('PREVIEW-FOREIGN-'.$source, $foreignUnit),
            ], 'preview')->assertOk()->assertJsonPath('data.accepted_rows', 1)->assertJsonPath('data.rejected_rows', 1)
                ->assertJsonPath('data.issues.0.row', 2)->assertJsonPath('data.issues.0.issues.0.code', 'reference_not_found')
                ->assertJsonPath('data.issues.0.issues.0.field', 'measurement_unit_id')
                ->assertJsonPath('data.issues.0.issues.0.message', trans_message('mdm.validation.reference_not_found', ['field' => 'Единица измерения']));
        }
        self::assertSame(0, Material::query()->where('organization_id', $actor->organization->id)->count());
    }

    public function test_rows_and_csv_reject_private_foreign_units_without_partial_imports(): void
    {
        [$actor, $ownUnit, $foreignUnit] = $this->catalog();
        $this->getJson('/api/v1/admin/measurement-units/'.$foreignUnit->id, $this->headers($actor))->assertNotFound();
        $this->getJson('/api/v1/admin/measurement-units/'.$ownUnit->id, $this->headers($actor))->assertOk();
        $deletedUnit = MeasurementUnit::create([
            'organization_id' => $actor->organization->id, 'name' => 'Deleted unit', 'short_name' => 'deleted-a', 'type' => 'material', 'is_system' => false,
        ]);
        $deletedUnit->delete();
        $before = $this->snapshot($actor->organization->id);

        foreach (['rows', 'file'] as $source) {
            foreach ([$foreignUnit, $deletedUnit, 999999999] as $invalidUnit) {
                $response = $this->import($actor, $source, [
                    $this->row('VALID-'.$source, $ownUnit), $this->row('FOREIGN-'.$source, $invalidUnit),
                ]);
                $response->assertUnprocessable()->assertJsonPath('message', trans_message('mdm.validation.reference_not_found', [
                    'field' => 'Единица измерения',
                ]));
                self::assertSame($before, $this->snapshot($actor->organization->id));
            }
        }
    }

    public function test_import_checks_other_registered_references_and_preserves_own_parents(): void
    {
        [$actor, , $foreignUnit] = $this->catalog();
        $ownParent = CostCategory::create(['organization_id' => $actor->organization->id, 'name' => 'Own parent', 'code' => 'OWN-PARENT']);
        $foreignParent = CostCategory::create(['organization_id' => $foreignUnit->organization_id, 'name' => 'Foreign parent', 'code' => 'FOREIGN-PARENT']);
        $row = ['name' => 'Child category', 'code' => 'CHILD', 'parent_id' => $foreignParent->id];
        $before = $this->snapshot($actor->organization->id);
        $this->postJson('/api/v1/admin/mdm/imports/apply', ['entity_type' => 'cost_category', 'rows' => [$row]], $this->headers($actor))
            ->assertUnprocessable()->assertJsonPath('message', trans_message('mdm.validation.reference_not_found', ['field' => 'Родительская категория']));
        self::assertSame($before, $this->snapshot($actor->organization->id));
        $this->postJson('/api/v1/admin/mdm/imports/apply', ['entity_type' => 'cost_category', 'rows' => [array_replace($row, ['parent_id' => $ownParent->id])]], $this->headers($actor))
            ->assertOk()->assertJsonPath('data.accepted_rows', 1);
        $this->assertDatabaseHas('cost_categories', ['organization_id' => $actor->organization->id, 'code' => 'CHILD', 'parent_id' => $ownParent->id]);
    }

    public function test_rows_and_csv_preserve_own_and_system_units_and_existing_record_updates(): void
    {
        [$actor, $ownUnit, , $systemUnit] = $this->catalog();
        foreach (['rows', 'file'] as $source) {
            foreach ([$ownUnit, $systemUnit] as $index => $unit) {
                $row = $this->row('ALLOWED-'.$source.'-'.$index, $unit);
                $this->import($actor, $source, [$row])->assertOk()->assertJsonPath('data.accepted_rows', 1);
                $material = Material::query()->where('organization_id', $actor->organization->id)->where('code', $row['code'])->firstOrFail();
                self::assertSame($unit->id, (int) $material->measurement_unit_id);
                $this->getJson('/api/v1/admin/materials/'.$material->id, $this->headers($actor))
                    ->assertOk()->assertJsonPath('data.measurement_unit.id', $unit->id);
                $this->import($actor, $source, [array_replace($row, ['description' => 'Allowed update'])])
                    ->assertOk()->assertJsonPath('data.accepted_rows', 1);
                self::assertSame('Allowed update', $material->refresh()->description);
            }
        }
        self::assertSame(4, Material::query()->where('organization_id', $actor->organization->id)->count());
    }

    public function test_merge_plan_and_apply_reject_unavailable_or_non_member_masters_atomically(): void
    {
        [$actor, $group, $ownMaterials, $foreignMaterial, $outsideMaterial] = $this->mergeFixture();
        $ownMaterials[0]->delete();
        $before = $this->snapshot($actor->organization->id);
        $foreignBefore = $foreignMaterial->refresh()->getRawOriginal();
        foreach ([$foreignMaterial->id, $outsideMaterial->id, $ownMaterials[0]->id, 999999999] as $masterId) {
            foreach (['merge-plan', 'merge-apply'] as $action) {
                $this->postJson('/api/v1/admin/mdm/duplicates/'.$group->id.'/'.$action, [
                    'master_entity_id' => $masterId,
                ], $this->headers($actor))->assertUnprocessable()
                    ->assertJsonPath('message', trans_message('mdm.validation.merge_master_not_found'));
                self::assertSame($before, $this->snapshot($actor->organization->id));
                self::assertSame($foreignBefore, $foreignMaterial->refresh()->getRawOriginal());
            }
        }
        $foreignGroup = MdmDuplicateGroup::create([
            'organization_id' => $foreignMaterial->organization_id,
            'entity_type' => 'material', 'fingerprint' => 'foreign-group', 'match_strategy' => 'exact',
            'status' => 'open', 'confidence' => 100,
        ]);
        $this->postJson('/api/v1/admin/mdm/duplicates/'.$foreignGroup->id.'/merge-apply', [
            'master_entity_id' => $ownMaterials[0]->id,
        ], $this->headers($actor))->assertNotFound();
        self::assertSame($before, $this->snapshot($actor->organization->id));
    }

    public function test_direct_services_enforce_reference_boundaries_before_writes(): void
    {
        [$actor, $group, , $foreignMaterial, , , $ownUnit, $foreignUnit] = $this->mergeFixture();
        $before = $this->snapshot($actor->organization->id);
        $this->assertValidationRejected(fn () => app(MdmImportService::class)->apply($actor->organization->id, 'material', [
            $this->row('DIRECT-VALID', $ownUnit), $this->row('DIRECT-FOREIGN', $foreignUnit),
        ], $actor->user->id), 'rows.1.measurement_unit_id');
        self::assertSame($before, $this->snapshot($actor->organization->id));
        $this->assertValidationRejected(fn () => app(MdmMergeService::class)->plan($group, $foreignMaterial->id), 'master_entity_id');
        self::assertSame($before, $this->snapshot($actor->organization->id));
        $this->assertValidationRejected(fn () => app(MdmMergeService::class)->apply($group, $foreignMaterial->id, $actor->user->id), 'master_entity_id');
        self::assertSame($before, $this->snapshot($actor->organization->id));
    }

    public function test_own_member_merge_preserves_links_and_foreign_material(): void
    {
        [$actor, $group, $ownMaterials, $foreignMaterial, , $workType] = $this->mergeFixture();
        $foreignBefore = $foreignMaterial->refresh()->getRawOriginal();
        $input = ['master_entity_id' => $ownMaterials[0]->id];
        $base = '/api/v1/admin/mdm/duplicates/'.$group->id;
        $this->postJson($base.'/merge-plan', $input, $this->headers($actor))->assertOk();
        $this->postJson($base.'/merge-apply', $input, $this->headers($actor))->assertOk();
        $this->assertDatabaseHas('work_type_materials', ['work_type_id' => $workType->id, 'material_id' => $ownMaterials[0]->id]);
        self::assertNull($ownMaterials[0]->refresh()->deleted_at);
        foreach (array_slice($ownMaterials, 1) as $duplicate) {
            $this->assertSoftDeleted('materials', ['id' => $duplicate->id]);
        }
        $this->getJson('/api/v1/admin/work-types/'.$workType->id.'/materials', $this->headers($actor))
            ->assertOk()->assertJsonFragment(['id' => $ownMaterials[0]->id, 'name' => 'Бетон М300']);
        self::assertSame($foreignBefore, $foreignMaterial->refresh()->getRawOriginal());
        self::assertSame('merged', $group->refresh()->status);
    }

    private function assertValidationRejected(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Foreign reference was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors());
        }
    }

    private function row(string $code, MeasurementUnit|int $unit): array
    {
        return ['name' => 'Material '.$code, 'code' => $code, 'measurement_unit_id' => $unit instanceof MeasurementUnit ? $unit->id : $unit, 'description' => 'Initial description'];
    }

    private function import(AdminApiTestContext $actor, string $source, array $rows, string $action = 'apply'): \Illuminate\Testing\TestResponse
    {
        if ($source === 'rows') {
            return $this->postJson('/api/v1/admin/mdm/imports/'.$action, ['entity_type' => 'material', 'rows' => $rows], $this->headers($actor));
        }
        $csv = "name;code;measurement_unit_id;description\n";
        foreach ($rows as $row) {
            $csv .= implode(';', [$row['name'], $row['code'], $row['measurement_unit_id'], $row['description']])."\n";
        }

        return $this->post('/api/v1/admin/mdm/imports/file/'.$action, [
            'entity_type' => 'material', 'file' => UploadedFile::fake()->createWithContent('materials.csv', $csv),
        ], $this->headers($actor));
    }

    private function catalog(): array
    {
        $actor = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        self::assertFalse($foreignOrganization->users()->whereKey($actor->user->id)->exists());
        $ownUnit = MeasurementUnit::create([
            'organization_id' => $actor->organization->id, 'name' => 'Own unit', 'short_name' => 'own-a', 'type' => 'material', 'is_system' => false,
        ]);
        $foreignUnit = MeasurementUnit::create([
            'organization_id' => $foreignOrganization->id, 'name' => 'Private unit B', 'short_name' => 'private-b', 'type' => 'material',
            'description' => 'PRIVATE-UNIT-B-MARKER', 'is_system' => false,
        ]);
        $systemUnit = MeasurementUnit::create([
            'organization_id' => $foreignOrganization->id, 'name' => 'System unit', 'short_name' => 'system-b', 'type' => 'material', 'is_system' => true,
        ]);

        return [$actor, $ownUnit, $foreignUnit, $systemUnit];
    }

    private function mergeFixture(): array
    {
        [$actor, $ownUnit, $foreignUnit] = $this->catalog();
        $ownMaterials = [];
        foreach (['A', 'B'] as $code) {
            $ownMaterials[] = Material::create([
                'organization_id' => $actor->organization->id, 'name' => 'Бетон М300', 'code' => 'MERGE-PROBE-'.$code,
                'measurement_unit_id' => $ownUnit->id, 'is_active' => true,
            ]);
        }
        $foreignMaterial = Material::create([
            'organization_id' => $foreignUnit->organization_id, 'name' => 'PRIVATE-MATERIAL-B-MARKER', 'code' => 'FOREIGN-MERGE',
            'measurement_unit_id' => $foreignUnit->id, 'is_active' => true,
        ]);
        $outsideMaterial = Material::create([
            'organization_id' => $actor->organization->id, 'name' => 'Unrelated own material', 'code' => 'OUTSIDE-GROUP',
            'measurement_unit_id' => $ownUnit->id, 'is_active' => true,
        ]);
        $workType = WorkType::create([
            'organization_id' => $actor->organization->id, 'name' => 'Бетонирование', 'code' => 'W-TENANT-PROBE',
            'measurement_unit_id' => $ownUnit->id, 'is_active' => true,
        ]);
        DB::table('work_type_materials')->insert([
            'organization_id' => $actor->organization->id, 'work_type_id' => $workType->id,
            'material_id' => $ownMaterials[1]->id, 'default_quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MdmDuplicateDetectionService::class)->scanOrganization($actor->organization->id, 'material');
        $group = MdmDuplicateGroup::query()->where('organization_id', $actor->organization->id)->where('entity_type', 'material')
            ->whereHas('members', fn ($query) => $query->where('entity_id', $ownMaterials[0]->id))
            ->whereHas('members', fn ($query) => $query->where('entity_id', $ownMaterials[1]->id))->firstOrFail();
        self::assertSame('material', $group->entity_type);
        self::assertEqualsCanonicalizing([$ownMaterials[0]->id, $ownMaterials[1]->id], $group->members()->pluck('entity_id')->map(static fn ($id): int => (int) $id)->all());

        return [$actor, $group, $ownMaterials, $foreignMaterial, $outsideMaterial, $workType, $ownUnit, $foreignUnit];
    }

    private function snapshot(int $organizationId): array
    {
        $snapshot = [];
        foreach (['materials', 'cost_categories', 'work_type_materials', 'mdm_records', 'mdm_import_batches', 'mdm_merge_runs', 'mdm_change_logs', 'mdm_duplicate_groups'] as $table) {
            $snapshot[$table] = DB::table($table)->where('organization_id', $organizationId)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function headers(AdminApiTestContext $actor): array
    {
        auth()->forgetGuards();

        return $actor->authHeaders();
    }
}
