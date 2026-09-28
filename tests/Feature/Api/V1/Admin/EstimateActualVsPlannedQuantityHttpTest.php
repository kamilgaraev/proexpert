<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Modules\Core\AccessController;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class EstimateActualVsPlannedQuantityHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_work_quantity_is_used_as_planned_volume_when_total_is_null(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateWorkingEntryPackage($context);

        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'number' => 'PROGRESS-'.random_int(10000, 99999),
            'name' => 'Approved estimate with manual work',
            'type' => 'local',
            'status' => 'approved',
            'estimate_date' => '2026-09-01',
            'total_direct_costs' => 0,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_amount' => 0,
            'total_amount_with_vat' => 0,
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Manual work with zero price',
            'quantity' => 1,
            'quantity_total' => null,
            'unit_price' => 0,
            'total_amount' => 0,
            'is_manual' => true,
        ]);

        $this->assertNull($item->fresh()->quantity_total);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/progress/actual-vs-planned")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.item_id', $item->id)
            ->assertJsonPath('data.items.0.planned_volume', 1)
            ->assertJsonPath('data.items.0.actual_volume', 0)
            ->assertJsonPath('data.items.0.remaining_volume', 1);

        $otherProject = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$otherProject->id}/estimates/{$estimate->id}/progress/actual-vs-planned")
            ->assertNotFound();
    }

    private function activateWorkingEntryPackage(AdminApiTestContext $context): void
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
        $this->assertTrue($access->hasModuleAccess((int) $context->organization->id, 'budget-estimates'));
    }
}
