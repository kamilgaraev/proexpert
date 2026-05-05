<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstimateBackfillCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stable_keys_dry_run_does_not_write_to_database(): void
    {
        $estimate = $this->createEstimate();
        $section = $this->createSection($estimate);
        $item = $this->createItem($estimate, $section);

        $this->artisan('estimates:backfill-stable-keys', ['--dry-run' => true])
            ->expectsOutput('Dry-run completed. Database was not changed.')
            ->assertExitCode(0);

        $this->assertNull($section->refresh()->stable_key);
        $this->assertNull($item->refresh()->stable_key);
    }

    public function test_stable_keys_real_run_backfills_missing_keys(): void
    {
        $estimate = $this->createEstimate();
        $section = $this->createSection($estimate);
        $item = $this->createItem($estimate, $section);

        $this->artisan('estimates:backfill-stable-keys')
            ->expectsOutput('Backfill completed.')
            ->assertExitCode(0);

        $this->assertNotNull($section->refresh()->stable_key);
        $this->assertNotNull($item->refresh()->stable_key);
    }

    public function test_stable_keys_rejects_invalid_organization_id_without_writes(): void
    {
        $estimate = $this->createEstimate();
        $section = $this->createSection($estimate);
        $item = $this->createItem($estimate, $section);

        foreach (['0', '-1', 'abc', '12abc', ''] as $organizationId) {
            $this->artisan('estimates:backfill-stable-keys', ['--organization_id' => $organizationId])
                ->expectsOutput('The --organization_id option must be a positive integer.')
                ->assertExitCode(1);
        }

        $this->assertNull($section->refresh()->stable_key);
        $this->assertNull($item->refresh()->stable_key);
    }

    public function test_approval_versions_dry_run_does_not_create_versions_or_stable_keys(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
        $section = $this->createSection($estimate);
        $item = $this->createItem($estimate, $section);

        $this->artisan('estimates:backfill-approval-versions', ['--dry-run' => true])
            ->expectsOutput('Dry-run completed. Database was not changed.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('estimate_versions', 0);
        $this->assertNull($section->refresh()->stable_key);
        $this->assertNull($item->refresh()->stable_key);
    }

    public function test_approval_versions_real_run_creates_approval_snapshot(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
        $section = $this->createSection($estimate);
        $item = $this->createItem($estimate, $section);

        $this->artisan('estimates:backfill-approval-versions')
            ->expectsOutput('Backfill completed.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('estimate_versions', [
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'snapshot_type' => 'approval',
            'estimate_status' => 'approved',
            'approved_by_user_id' => $actor->id,
        ]);
        $this->assertDatabaseCount('estimate_versions', 1);
        $this->assertNotNull(DB::table('estimate_versions')->where('estimate_id', $estimate->id)->value('snapshot_hash'));
        $this->assertNotNull($section->refresh()->stable_key);
        $this->assertNotNull($item->refresh()->stable_key);
    }

    public function test_approval_versions_rejects_invalid_organization_id_without_writes(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
        $section = $this->createSection($estimate);
        $item = $this->createItem($estimate, $section);

        foreach (['0', '-1', 'abc', '12abc', ''] as $organizationId) {
            $this->artisan('estimates:backfill-approval-versions', ['--organization_id' => $organizationId])
                ->expectsOutput('The --organization_id option must be a positive integer.')
                ->assertExitCode(1);
        }

        $this->assertDatabaseCount('estimate_versions', 0);
        $this->assertNull($section->refresh()->stable_key);
        $this->assertNull($item->refresh()->stable_key);
    }

    private function createEstimate(array $overrides = []): Estimate
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'BACKFILL-' . (DB::table('estimates')->count() + 1),
            'name' => 'Backfill test estimate',
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

    private function createSection(Estimate $estimate): EstimateSection
    {
        return EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Backfill section',
            'sort_order' => 1,
            'section_total_amount' => 1200,
        ]);
    }

    private function createItem(Estimate $estimate, EstimateSection $section): EstimateItem
    {
        return EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.1',
            'name' => 'Backfill item',
            'item_type' => 'work',
            'quantity' => 2,
            'unit_price' => 600,
            'total_amount' => 1200,
        ]);
    }
}
