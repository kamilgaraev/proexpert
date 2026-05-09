<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;

class EstimateGenerationPackageFlowTest extends TestCase
{
    public function test_generation_persists_local_estimate_packages_and_dense_items(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'created',
            'processing_stage' => 'created',
            'processing_progress' => 0,
            'input_payload' => [
                'description' => 'Дом 150 м2, 2 этажа, 8 комнат, Татарстан, 1 квартал 2026',
                'building_type' => 'Жилой',
                'area' => 150,
                'regional_context' => [
                    'region_name' => 'Республика Татарстан',
                    'year' => 2026,
                    'quarter' => 1,
                    'version_key' => '2026-q1-ru-ta',
                ],
            ],
            'problem_flags' => [],
        ]);

        $session = app(EstimateGenerationOrchestrator::class)->generate($session);

        $this->assertContains($session->status, ['ready_for_review', 'review_required', 'blocked']);
        $this->assertGreaterThanOrEqual(15, $session->packages()->count());
        $this->assertGreaterThanOrEqual(250, $session->packages()->withCount('items')->get()->sum('items_count'));
        $this->assertGreaterThan(0, $session->packages()->get()->sum(fn ($package): int => (int) ($package->totals['priced_items_count'] ?? 0)));
        $this->assertGreaterThan(0, $session->packages()->get()->sum(fn ($package): int => (int) ($package->totals['operation_items_count'] ?? 0)));
        $this->assertDatabaseMissing('estimate_generation_package_items', [
            'item_type' => 'priced_work',
            'unit' => 'компл',
            'quantity' => 0.080000,
        ]);
        $this->assertNotSame('generated', $session->status);
        $this->assertNotEmpty($session->draft_payload['object_profile'] ?? []);
        $this->assertNotEmpty($session->draft_payload['package_plan'] ?? []);
    }
}
