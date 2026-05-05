<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstimateVersioningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_snapshot_creates_immutable_estimate_version(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate();
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Подготовительные работы',
            'sort_order' => 1,
            'section_total_amount' => 1200,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.1',
            'name' => 'Разметка',
            'item_type' => 'work',
            'quantity' => 2,
            'unit_price' => 600,
            'total_amount' => 1200,
        ]);

        $version = app(EstimateVersioningService::class)->createSnapshot(
            estimate: $estimate,
            actorId: $actor->id,
            label: 'Контрольная версия',
            comment: 'Перед изменениями'
        );

        $estimate->update([
            'name' => 'Измененная смета',
            'total_amount' => 2400,
            'total_amount_with_vat' => 2880,
        ]);
        EstimateItem::query()->where('estimate_id', $estimate->id)->update([
            'name' => 'Измененная позиция',
            'total_amount' => 2400,
        ]);

        $version->refresh();

        $this->assertSame(1, $version->version_number);
        $this->assertSame($estimate->organization_id, $version->organization_id);
        $this->assertSame($actor->id, $version->created_by_user_id);
        $this->assertSame('manual', $version->snapshot_type);
        $this->assertSame('draft', $version->estimate_status);
        $this->assertSame('Контрольная версия', $version->label);
        $this->assertSame('Перед изменениями', $version->comment);
        $this->assertSame('1200.00', $version->total_amount);
        $this->assertSame('1440.00', $version->total_amount_with_vat);
        $this->assertSame('1200.00', $version->total_direct_costs);
        $this->assertSame('Test estimate', $version->snapshot['estimate']['name']);
        $this->assertSame('Разметка', $version->snapshot['sections'][0]['items'][0]['name']);
        $this->assertNotSame($estimate->fresh()->name, $version->snapshot['estimate']['name']);
    }

    public function test_identical_approval_snapshot_is_idempotent_by_hash(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Основные работы',
            'sort_order' => 1,
            'section_total_amount' => 1200,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.1',
            'name' => 'Монтаж',
            'item_type' => 'work',
            'quantity' => 2,
            'unit_price' => 600,
            'total_amount' => 1200,
        ]);

        $service = app(EstimateVersioningService::class);

        $firstVersion = $service->createApprovalSnapshot($estimate, $actor->id);
        $secondVersion = $service->createApprovalSnapshot($estimate->fresh(), $actor->id);

        $this->assertSame($firstVersion->id, $secondVersion->id);
        $this->assertSame($firstVersion->snapshot_hash, $secondVersion->snapshot_hash);
        $this->assertSame(1, $firstVersion->version_number);
        $this->assertSame('approval', $firstVersion->snapshot_type);
        $this->assertSame($actor->id, $firstVersion->approved_by_user_id);
        $this->assertDatabaseCount('estimate_versions', 1);
    }

    private function createEstimate(array $overrides = []): Estimate
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-' . (DB::table('estimates')->count() + 1),
            'name' => 'Test estimate',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-05-05',
            'base_price_date' => '2026-05-01',
            'total_direct_costs' => 1200,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_equipment_costs' => 0,
            'total_amount' => 1200,
            'total_amount_with_vat' => 1440,
            'vat_rate' => 20,
            'overhead_rate' => 0,
            'profit_rate' => 0,
            'calculation_method' => 'resource',
        ], $overrides));
    }
}
