<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\BudgetEstimates\Services\Library\EstimateLibraryService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use App\Models\EstimateLibraryItemPosition;
use App\Models\Organization;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Illuminate\Auth\Access\AuthorizationException;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class EstimateLibrarySecurityTest extends TestCase
{
    public function test_library_application_uses_session_organization_and_requires_edit_permission(): void
    {
        $access = Mockery::mock(AccessController::class);
        $access->shouldReceive('hasModuleAccess')->andReturnUsing(fn (int $organizationId, string $slug) => $slug === 'budget-estimates');
        $this->app->instance(AccessController::class, $access);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $otherOrganization = Organization::factory()->create();
        $context->user->organizations()->attach($otherOrganization->id, ['is_active' => true]);
        $context->user->update(['current_organization_id' => $otherOrganization->id]);
        $context->user->current_organization_id = $context->organization->id;
        \Illuminate\Support\Facades\Auth::shouldUse('api_admin');
        \Illuminate\Support\Facades\Auth::setUser($context->user);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = Estimate::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'number' => 'SEC-LIB', 'name' => 'Security library estimate', 'type' => 'local',
            'status' => 'draft', 'estimate_date' => now()->toDateString(), 'calculation_method' => 'resource',
        ]);
        $library = EstimateLibrary::query()->create([
            'organization_id' => $context->organization->id, 'created_by_user_id' => $context->user->id,
            'name' => 'Test library', 'access_level' => 'private', 'tags' => [], 'is_active' => true,
        ]);
        $item = EstimateLibraryItem::query()->create([
            'library_id' => $library->id, 'name' => 'Test item', 'parameters' => [], 'positions_count' => 1,
        ]);
        EstimateLibraryItemPosition::query()->create([
            'library_item_id' => $item->id, 'name' => 'Test position', 'sort_order' => 0, 'default_quantity' => 1,
        ]);
        self::assertSame($context->organization->id, $context->user->current_organization_id);
        self::assertTrue(app(\App\Services\Project\UserProjectAccessService::class)->canAccessCurrentOrganizationProject($context->user, $project));
        self::assertTrue($context->user->hasPermission('budget-estimates.*', ['context_type' => 'organization', 'organization_id' => $context->organization->id]));
        $added = app(EstimateLibraryService::class)->applyLibraryItemToEstimate($item, $estimate, $context->user);
        self::assertCount(1, $added);
        self::assertSame('1', $added[0]->position_number);
        self::assertSame(1, EstimateItem::query()->where('estimate_id', $estimate->id)->count());
        $viewer = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $context->organization->users()->attach($viewer->user->id, ['is_active' => true]);
        \App\Domain\Authorization\Models\UserRoleAssignment::assignRole($viewer->user, 'admin_viewer',
            \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($context->organization->id));
        $viewer->user->current_organization_id = $context->organization->id;
        $this->expectException(AuthorizationException::class);
        try {
            app(EstimateLibraryService::class)->applyLibraryItemToEstimate($item, $estimate, $viewer->user);
        } finally {
            self::assertSame(1, EstimateItem::query()->where('estimate_id', $estimate->id)->count());
        }
    }
}
