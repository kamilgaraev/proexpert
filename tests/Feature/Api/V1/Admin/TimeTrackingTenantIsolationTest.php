<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class TimeTrackingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_entry_create_uses_current_organization_instead_of_request_organization(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignOrganization = Organization::factory()->verified()->create();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/time-tracking', [
                'organization_id' => $foreignOrganization->id,
                'worker_type' => 'virtual',
                'worker_name' => 'Монтажная бригада',
                'project_id' => $project->id,
                'work_date' => now()->subDay()->format('Y-m-d'),
                'hours_worked' => 4,
                'title' => 'Монтаж',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);

        $entry = TimeEntry::query()->findOrFail($response->json('data.id'));
        $this->assertSame($context->organization->id, $entry->organization_id);
    }

    public function test_time_entry_create_rejects_foreign_project_id(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/time-tracking', [
                'worker_type' => 'virtual',
                'worker_name' => 'Монтажная бригада',
                'project_id' => $foreignProject->id,
                'work_date' => now()->subDay()->format('Y-m-d'),
                'hours_worked' => 4,
                'title' => 'Монтаж',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }

    public function test_time_entry_update_rejects_foreign_project_id(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $entry = TimeEntry::query()->create([
            'organization_id' => $context->organization->id,
            'worker_type' => 'virtual',
            'worker_name' => 'Монтажная бригада',
            'project_id' => $project->id,
            'work_date' => now()->subDay()->format('Y-m-d'),
            'hours_worked' => 4,
            'title' => 'Монтаж',
            'status' => 'draft',
            'is_billable' => true,
        ]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/time-tracking/{$entry->id}", [
                'project_id' => $foreignProject->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
        $this->assertSame($project->id, $entry->fresh()->project_id);
    }

    public function test_time_tracking_filters_reject_foreign_project_id(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking?' . http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $indexResponse->assertStatus(422);
        $indexResponse->assertJsonValidationErrors('project_id');

        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking/statistics?' . http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $statisticsResponse->assertStatus(422);
        $statisticsResponse->assertJsonValidationErrors('project_id');
    }

    public function test_time_tracking_report_rejects_foreign_project_id(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/time-tracking?' . http_build_query([
                'date_from' => now()->subWeek()->format('Y-m-d'),
                'date_to' => now()->format('Y-m-d'),
                'project_id' => $foreignProject->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }

    private function activateReportsModule(int $organizationId): void
    {
        $reportsModule = Module::query()->firstOrCreate(
            ['slug' => 'reports'],
            [
                'name' => 'Reports',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'core',
                'is_active' => true,
                'can_deactivate' => true,
            ]
        );

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $reportsModule->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
