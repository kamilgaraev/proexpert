<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\ImportSession;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EstimateGenerationLearningImportTest extends TestCase
{
    public function test_imported_estimate_work_rows_create_positive_learning_examples(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $normId = $this->seedNormative('01-01-006-01', 'Бетонирование фундаментной ленты B22.5', 'м3');

        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'IMP-001',
            'name' => 'Импортированная смета',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
        ]);
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Фундамент',
            'sort_order' => 1,
        ]);
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organization->id,
            'name' => 'кубический метр',
            'short_name' => 'м3',
            'type' => 'work',
        ]);
        $workItem = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1',
            'name' => 'Бетонирование фундаментной ленты B22.5',
            'measurement_unit_id' => $unit->id,
            'quantity' => 13.8,
            'unit_price' => 5000,
            'total_amount' => 69000,
            'normative_rate_code' => 'ФСНБ 01-01-006-01',
            'item_type' => 'work',
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'parent_work_id' => $workItem->id,
            'position_number' => '1.1',
            'name' => 'Бетон B22.5',
            'measurement_unit_id' => $unit->id,
            'quantity' => 13.8,
            'unit_price' => 4000,
            'total_amount' => 55200,
            'item_type' => 'material',
        ]);
        $importSession = ImportSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => 'completed',
            'file_name' => 'estimate.xlsx',
            'file_size' => 1000,
            'file_format' => 'xlsx',
        ]);

        $created = app(EstimateGenerationLearningRecorder::class)
            ->recordImportedEstimate($estimate, $importSession);

        $this->assertSame(1, $created);
        $this->assertSame(1, EstimateGenerationLearningExample::query()->count());

        $example = EstimateGenerationLearningExample::query()->firstOrFail();

        $this->assertSame($organization->id, $example->organization_id);
        $this->assertSame($project->id, $example->project_id);
        $this->assertSame($estimate->id, $example->estimate_id);
        $this->assertSame($workItem->id, $example->estimate_item_id);
        $this->assertSame($normId, $example->estimate_norm_id);
        $this->assertSame('imported_estimate', $example->source_type);
        $this->assertSame('estimate_item', $example->source_entity_type);
        $this->assertSame($workItem->id, $example->source_entity_id);
        $this->assertSame('01-01-006-01', $example->norm_code);
        $this->assertSame('м3', $example->work_unit);
        $this->assertSame('foundation', $example->work_intent['scope']);
        $this->assertSame('concreting', $example->work_intent['action']);
        $this->assertTrue($example->is_positive);
        $this->assertContains('unit_compatible', $example->quality_flags);
    }

    private function seedNormative(string $code, string $name, string $unit): int
    {
        $versionId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => 'test-' . Str::uuid(),
            'bucket' => 'test-bucket',
            'prefix' => 'test-prefix',
            'status' => 'parsed',
            'files_count' => 1,
            'rows_read' => 1,
            'rows_imported' => 1,
            'errors_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collectionId = (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $versionId,
            'code' => 'gesn',
            'name' => 'ГЭСН',
            'norm_type' => 'gesn',
            'source_file' => 'ГЭСН.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = (int) DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collectionId,
            'code' => '01',
            'name' => 'Строительные работы',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => '01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'section_id' => $sectionId,
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'section_code' => '01',
            'section_name' => 'Строительные работы',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
