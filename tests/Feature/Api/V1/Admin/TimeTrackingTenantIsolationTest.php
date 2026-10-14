<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
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
        $context = $this->timeTrackingContext();
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
        $context = $this->timeTrackingContext();
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
        $context = $this->timeTrackingContext();
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
        $context = $this->timeTrackingContext();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking?'.http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $indexResponse->assertStatus(422);
        $indexResponse->assertJsonValidationErrors('project_id');

        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking/statistics?'.http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $statisticsResponse->assertStatus(422);
        $statisticsResponse->assertJsonValidationErrors('project_id');
    }

    public function test_time_tracking_report_rejects_foreign_project_id(): void
    {
        $context = $this->timeTrackingContext();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/time-tracking/reports?'.http_build_query([
                'start_date' => now()->subWeek()->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
                'project_id' => $foreignProject->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }

    private function timeTrackingContext(): AdminApiTestContext
    {
        $context = AdminApiTestContext::create();
        $definition = json_decode((string) file_get_contents(config_path('ModuleList/features/time-tracking.json')), true, flags: JSON_THROW_ON_ERROR);
        \App\Models\Module::query()->updateOrCreate(['slug' => 'time-tracking'], [
            'name' => $definition['name'], 'version' => $definition['version'], 'type' => $definition['type'],
            'billing_model' => $definition['billing_model'], 'category' => $definition['category'],
            'permissions' => $definition['permissions'], 'is_active' => true, 'is_system_module' => false, 'can_deactivate' => true,
        ]);
        $account = \App\Models\OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id, 'responsible_user_id' => $context->user->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1,
            'current_period_start_at' => now(), 'current_period_end_at' => now()->addDays(30),
        ]);
        \App\Models\OrganizationPackageSubscription::query()->create([
            'organization_id' => $context->organization->id, 'commercial_account_id' => $account->id,
            'package_slug' => 'workforce-output', 'status' => 'active', 'access_source' => 'paid_package', 'price_paid' => 7900,
            'current_period_start_at' => now(), 'current_period_end_at' => now()->addDays(30),
        ]);
        app(\App\Modules\Core\AccessController::class)->clearAccessCache((int) $context->organization->id);
        \Illuminate\Support\Facades\Cache::flush();

        return $context;
    }
}
