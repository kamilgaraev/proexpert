<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Modules\Core\AccessController;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ScheduleOneDayDateRangeHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_day_schedule_can_be_created_activated_and_keeps_date_guards(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateSchedulePackage($context);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $this->assertSchedulePermissions($context, $project);

        $url = "/api/v1/admin/projects/{$project->id}/schedules";
        $startDate = '2026-10-12';
        $scheduleName = 'One-day schedule '.random_int(10000, 99999);

        $created = $this->withHeaders($context->authHeaders())
            ->postJson($url, [
                'name' => $scheduleName,
                'planned_start_date' => $startDate,
                'planned_end_date' => $startDate,
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.planned_start_date', $startDate)
            ->assertJsonPath('data.planned_end_date', $startDate)
            ->assertJsonPath('data.planned_duration_days', 1);
        $scheduleId = $created->json('data.id');

        $this->assertDatabaseHas('project_schedules', [
            'id' => $scheduleId,
            'project_id' => $project->id,
            'organization_id' => $context->organization->id,
            'planned_start_date' => $startDate,
            'planned_end_date' => $startDate,
            'status' => 'draft',
        ]);

        $this->withHeaders($context->authHeaders())
            ->putJson("{$url}/{$scheduleId}", [
                'planned_start_date' => '2026-10-13',
                'planned_end_date' => $startDate,
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['planned_end_date']);

        $this->assertDatabaseHas('project_schedules', [
            'id' => $scheduleId,
            'planned_start_date' => $startDate,
            'planned_end_date' => $startDate,
            'status' => 'draft',
        ]);

        $this->withHeaders($context->authHeaders())
            ->putJson("{$url}/{$scheduleId}", [
                'planned_start_date' => $startDate,
                'planned_end_date' => $startDate,
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.planned_start_date', $startDate)
            ->assertJsonPath('data.planned_end_date', $startDate)
            ->assertJsonPath('data.planned_duration_days', 1);

        $this->assertDatabaseHas('project_schedules', [
            'id' => $scheduleId,
            'planned_start_date' => $startDate,
            'planned_end_date' => $startDate,
            'status' => 'active',
        ]);

        $reversedScheduleName = 'Reversed one-day schedule '.random_int(10000, 99999);
        $this->withHeaders($context->authHeaders())
            ->postJson($url, [
                'name' => $reversedScheduleName,
                'planned_start_date' => '2026-10-13',
                'planned_end_date' => $startDate,
                'status' => 'draft',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['planned_end_date']);

        $this->assertDatabaseMissing('project_schedules', [
            'project_id' => $project->id,
            'name' => $reversedScheduleName,
        ]);
    }

    private function assertSchedulePermissions(AdminApiTestContext $context, Project $project): void
    {
        $authorization = app(AuthorizationService::class);
        $organizationId = (int) $context->organization->id;

        foreach (['schedule.create', 'schedule.edit'] as $permission) {
            $this->assertTrue($authorization->can($context->user, $permission, [
                'organization_id' => $organizationId,
                'context_type' => 'organization',
            ]));
            $this->assertTrue($authorization->can($context->user, $permission, [
                'organization_id' => $organizationId,
                'project_id' => (int) $project->id,
            ]));
        }
    }

    private function activateSchedulePackage(AdminApiTestContext $context): void
    {
        $catalog = app(PackageCatalogService::class);
        $definitions = $catalog->moduleDefinitions();

        foreach ($catalog->tierModules('working-entry', 'standard') as $moduleSlug) {
            $definition = $definitions[$moduleSlug] ?? null;
            if ($definition === null) {
                continue;
            }

            Module::query()->updateOrCreate(
                ['slug' => $moduleSlug],
                [
                    'name' => $definition['name'],
                    'version' => $definition['version'] ?? '1.0.0',
                    'type' => $definition['type'],
                    'billing_model' => $definition['billing_model'] ?? 'free',
                    'category' => $definition['category'] ?? 'general',
                    'description' => $definition['description'] ?? null,
                    'pricing_config' => $definition['pricing'] ?? null,
                    'features' => $definition['features'] ?? null,
                    'permissions' => $definition['permissions'] ?? [],
                    'dependencies' => $definition['dependencies'] ?? [],
                    'conflicts' => $definition['conflicts'] ?? [],
                    'limits' => $definition['limits'] ?? [],
                    'class_name' => $definition['class_name'] ?? null,
                    'config_file' => $definition['_config_file'] ?? null,
                    'icon' => $definition['icon'] ?? null,
                    'display_order' => $definition['display_order'] ?? 0,
                    'is_active' => true,
                    'is_system_module' => $definition['is_system_module'] ?? false,
                    'can_deactivate' => $definition['can_deactivate'] ?? true,
                ],
            );
        }

        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id,
            'responsible_user_id' => $context->user->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);

        OrganizationPackageSubscription::query()->create([
            'organization_id' => $context->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'working-entry',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 0,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);

        $access = app(AccessController::class);
        $access->clearAccessCache((int) $context->organization->id);
        $this->assertTrue($access->hasModuleAccess((int) $context->organization->id, 'schedule-management'));
    }
}
