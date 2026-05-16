<?php

declare(strict_types=1);

namespace Tests\Feature\Mdm;

use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Core\Mdm\Services\MdmDuplicateDetectionService;
use App\Models\Contractor;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\WorkType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MdmProductWorkflowTest extends TestCase
{
    public function test_observer_syncs_catalog_changes_and_quality_policy_changes_score(): void
    {
        $context = AdminApiTestContext::create();

        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Авто MDM',
            'inn' => '7701000010',
        ]);

        $this->assertDatabaseHas('mdm_records', [
            'entity_type' => 'contractor',
            'entity_id' => $contractor->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->putJson('/api/v1/admin/mdm/quality-policies/contractor', [
                'required_fields' => ['name', 'email'],
                'field_weights' => ['name' => 25, 'email' => 30, 'normalized_key' => 15],
                'min_acceptable_score' => 80,
            ]);

        $response->assertOk();
        $contractor->touch();

        $this->assertLessThan(100, (int) MdmRecord::query()
            ->where('entity_type', 'contractor')
            ->where('entity_id', $contractor->id)
            ->value('quality_score'));
    }

    public function test_fuzzy_duplicates_and_merge_apply_repoint_material_links(): void
    {
        $context = AdminApiTestContext::create();
        $unit = MeasurementUnit::create([
            'organization_id' => $context->organization->id,
            'name' => 'Ед. merge',
            'short_name' => 'merge-mdm',
            'type' => 'material',
        ]);
        $master = Material::create([
            'organization_id' => $context->organization->id,
            'name' => 'Бетон М300',
            'code' => 'M300-A',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $duplicate = Material::create([
            'organization_id' => $context->organization->id,
            'name' => 'Бетон М300',
            'code' => 'M300-B',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $workType = WorkType::create([
            'organization_id' => $context->organization->id,
            'name' => 'Бетонирование',
            'code' => 'W-MERGE',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);

        DB::table('work_type_materials')->insert([
            'organization_id' => $context->organization->id,
            'work_type_id' => $workType->id,
            'material_id' => $duplicate->id,
            'default_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(MdmDuplicateDetectionService::class)->scanOrganization($context->organization->id, 'material');
        $group = MdmDuplicateGroup::query()->where('entity_type', 'material')->firstOrFail();

        $plan = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/duplicates/{$group->id}/merge-plan", ['master_entity_id' => $master->id]);
        $plan->assertOk();
        $plan->assertJsonPath('success', true);

        $apply = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/duplicates/{$group->id}/merge-apply", ['master_entity_id' => $master->id]);
        $apply->assertOk();
        $apply->assertJsonPath('success', true);

        $this->assertDatabaseHas('work_type_materials', [
            'work_type_id' => $workType->id,
            'material_id' => $master->id,
        ]);
        $this->assertDatabaseHas('mdm_records', [
            'entity_type' => 'material',
            'entity_id' => $duplicate->id,
            'status' => 'merged',
        ]);
    }

    public function test_file_import_preview_accepts_csv(): void
    {
        $context = AdminApiTestContext::create();
        $file = UploadedFile::fake()->createWithContent(
            'contractors.csv',
            "name;inn;kpp\nООО Файл;7701000011;770101001\n;123;\n"
        );

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/mdm/imports/file/preview', [
                'entity_type' => 'contractor',
                'file' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.total_rows', 2);
        $response->assertJsonPath('data.accepted_rows', 1);
        $response->assertJsonPath('data.rejected_rows', 1);
    }
}
