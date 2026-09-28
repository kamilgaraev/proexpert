<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Addons\FileManagement\FileManagementModule;
use App\BusinessModules\Features\ContractManagement\ContractManagementModule;
use App\BusinessModules\Features\DesignManagement\DesignManagementModule;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentTypeEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\ExecutiveDocumentationModule;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ProjectManagement\ProjectManagementModule;
use App\BusinessModules\Services\ReportTemplates\ReportTemplatesModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\UserProjectAccessMode;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\AccessController;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobilePtoHttpContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_pto_routes_use_mobile_authentication_and_interface_access_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.mobile.pto.design-packages.index');
        self::assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        self::assertContains('auth:api_mobile', $middleware);
        self::assertContains('auth.jwt:api_mobile', $middleware);
        self::assertContains('organization.context', $middleware);
        self::assertContains('can:access-mobile-app', $middleware);

        $response = $this->getJson('/api/v1/mobile/pto/design-packages?project_id=1');
        $this->assertHttpStatus($response, 401);
    }

    public function test_design_package_list_detail_and_action_use_mobile_http_contract_and_persist_transition(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->grantProject($context, $project);
        $this->registerPtoEntitlements($context);
        $this->assertProjectPermissions($context, $project, ['design-management.view']);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'title' => 'Design package for mobile review',
            'stage' => 'working',
            'discipline' => 'architecture',
            'status' => 'under_norm_control',
            'metadata' => [],
        ]);
        $listResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/pto/design-packages?project_id='.$project->id);
        $this->assertHttpStatus($listResponse, 200);
        $listResponse
            ->assertJsonPath('data.0.id', $package->id)
            ->assertJsonPath('data.0.status', 'under_norm_control')
            ->assertJsonPath('meta.total', 1);

        $detailResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/pto/design-packages/'.$package->id);
        $this->assertHttpStatus($detailResponse, 200);
        $detailResponse
            ->assertJsonPath('data.id', $package->id)
            ->assertJsonPath('data.available_actions.0.key', 'return_to_work');

        $actionResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/pto/design-packages/'.$package->id.'/actions/return_to_work', [
                'comment' => 'Нужно уточнить состав комплекта',
            ]);
        $this->assertHttpStatus($actionResponse, 200);
        $actionResponse
            ->assertJsonPath('data.status', 'returned');

        $this->assertDatabaseHas('design_packages', ['id' => $package->id, 'status' => 'returned']);
    }

    public function test_mobile_executive_document_action_rejects_unavailable_state_and_hides_foreign_records(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->grantProject($context, $project);
        $this->registerPtoEntitlements($context);
        $this->registerPtoEntitlements($foreignContext);
        $this->assertProjectPermissions($context, $project, ['executive-documentation.view', 'executive-documentation.submit']);
        $localDocument = $this->createExecutiveDocument($context, $project, 'draft');
        $foreignDocument = $this->createExecutiveDocument($foreignContext, $foreignProject, 'draft');
        $path = 'org-'.$context->organization->id.'/executive-documentation/mobile-note.txt';
        ExecutiveDocumentVersion::query()->create([
            'organization_id' => $context->organization->id,
            'document_id' => $localDocument->id,
            'uploaded_by' => $context->user->id,
            'version_number' => '1',
            'status' => 'draft',
            'file_url' => $path,
            'content_hash' => str_repeat('a', 64),
            'uploaded_at' => now(),
            'metadata' => [],
        ]);
        $this->mock(FileService::class, function (MockInterface $mock) use ($path): void {
            $mock->shouldReceive('temporaryDownloadUrl')
                ->once()
                ->with($path, 300)
                ->andReturn('https://files.example.test/mobile-note');
        });

        $unavailableActionResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/pto/executive-documents/'.$localDocument->id.'/actions/approve');
        $this->assertHttpStatus($unavailableActionResponse, 409);

        $this->assertDatabaseHas('executive_documents', ['id' => $localDocument->id, 'status' => 'draft']);

        $submitResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/pto/executive-documents/'.$localDocument->id.'/actions/submit', [
                'comment' => 'Передано на проверку',
            ]);
        $this->assertHttpStatus($submitResponse, 200);
        $submitResponse
            ->assertJsonPath('data.status', 'under_review');

        $this->assertDatabaseHas('executive_documents', ['id' => $localDocument->id, 'status' => 'under_review']);
        $this->assertDatabaseHas('executive_document_versions', [
            'document_id' => $localDocument->id,
            'status' => 'under_review',
        ]);

        $foreignResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/pto/executive-documents/'.$foreignDocument->id.'/actions/submit');
        $this->assertHttpStatus($foreignResponse, 404);

        $this->assertDatabaseHas('executive_documents', ['id' => $foreignDocument->id, 'status' => 'draft']);
    }

    public function test_mobile_design_package_http_endpoints_require_project_scope_and_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $context->organization->users()->updateExistingPivot($context->user->id, [
            'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
        ]);
        $this->registerPtoEntitlements($context);
        $allowedProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $hiddenProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->grantProject($context, $allowedProject);
        $this->assertProjectPermissions($context, $allowedProject, ['design-management.view']);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $hiddenProject->id,
            'created_by' => $context->user->id,
            'title' => 'Package outside assigned project',
            'status' => 'under_norm_control',
        ]);
        $hiddenListResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/pto/design-packages?project_id='.$hiddenProject->id);
        $this->assertHttpStatus($hiddenListResponse, 404);

        $hiddenDetailResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/pto/design-packages/'.$package->id);
        $this->assertHttpStatus($hiddenDetailResponse, 404);

        $hiddenActionResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/pto/design-packages/'.$package->id.'/actions/return_to_work', ['comment' => 'Проверка области']);
        $this->assertHttpStatus($hiddenActionResponse, 404);

        $this->assertDatabaseHas('design_packages', ['id' => $package->id, 'status' => 'under_norm_control']);
    }

    public function test_mobile_design_package_routes_require_the_module_view_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->grantProject($context, $project, 'foreman');
        $this->registerPtoEntitlements($context);
        DesignPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'title' => 'Permission protected design package',
            'status' => 'under_norm_control',
        ]);
        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/pto/design-packages?project_id='.$project->id)
            ->assertForbidden();
    }

    private function createExecutiveDocument(AdminApiTestContext $context, Project $project, string $status): ExecutiveDocument
    {
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'MOB-ED-'.$project->id,
            'title' => 'Mobile executive documents',
            'status' => 'draft',
        ]);

        return ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => ExecutiveDocumentTypeEnum::OTHER,
            'title' => 'Mobile document '.$project->id,
            'status' => $status,
        ]);
    }

    private function grantProject(AdminApiTestContext $context, Project $project, string $roleSlug = 'project_manager'): void
    {
        $context->user->assignedProjects()->attach($project->id, [
            'is_active' => true,
            'role' => 'member',
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        UserRoleAssignment::assignRole(
            user: $context->user,
            roleSlug: $roleSlug,
            context: AuthorizationContext::getProjectContext((int) $project->id, (int) $context->organization->id),
        );
    }

    private function registerPtoEntitlements(AdminApiTestContext $context): void
    {
        $this->registerModule(new DesignManagementModule, 'ModuleList/features/design-management.json');
        $this->registerModule(new ExecutiveDocumentationModule, 'ModuleList/features/executive-documentation.json');
        $this->registerModule(new ProjectManagementModule, 'ModuleList/features/project-management.json');
        $this->registerModule(new ContractManagementModule, 'ModuleList/features/contract-management.json');
        $this->registerModule(new FileManagementModule, 'ModuleList/addons/file-management.json');
        $this->registerModule(new ReportTemplatesModule, 'ModuleList/services/report-templates.json');

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
            'price_paid' => 39900,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);

        $access = $this->app->make(AccessController::class);
        $access->clearAccessCache((int) $context->organization->id);
        self::assertTrue($access->hasModuleAccess((int) $context->organization->id, 'design-management'));
        self::assertTrue($access->hasModuleAccess((int) $context->organization->id, 'executive-documentation'));
        foreach (['project-management', 'file-management', 'contract-management', 'report-templates'] as $requiredModule) {
            self::assertTrue($access->hasModuleAccess((int) $context->organization->id, $requiredModule));
        }
    }

    private function registerModule(ModuleInterface $module, string $configFile): void
    {
        $manifest = $module->getManifest();
        Module::query()->updateOrCreate(
            ['slug' => $module->getSlug()],
            [
                'name' => $module->getName(),
                'version' => $module->getVersion(),
                'type' => $module->getType()->value,
                'billing_model' => $module->getBillingModel()->value,
                'category' => $manifest['category'] ?? 'construction',
                'description' => $module->getDescription(),
                'features' => $module->getFeatures(),
                'permissions' => $module->getPermissions(),
                'dependencies' => $module->getDependencies(),
                'conflicts' => $module->getConflicts(),
                'limits' => $module->getLimits(),
                'class_name' => $module::class,
                'config_file' => $configFile,
                'display_order' => $manifest['display_order'] ?? 0,
                'is_active' => true,
                'is_system_module' => false,
            ],
        );
    }

    /** @param list<string> $permissions */
    private function assertProjectPermissions(AdminApiTestContext $context, Project $project, array $permissions): void
    {
        $authorization = $this->app->make(AuthorizationService::class);
        self::assertTrue($authorization->canAccessInterface($context->user, 'mobile'));

        foreach ($permissions as $permission) {
            self::assertTrue($authorization->can($context->user, $permission, [
                'organization_id' => $context->organization->id,
                'project_id' => $project->id,
                'strict_project_scope' => true,
            ]), "Expected active project role to grant {$permission}.");
        }
    }

    private function assertHttpStatus(TestResponse $response, int $expectedStatus): void
    {
        self::assertSame(
            $expectedStatus,
            $response->getStatusCode(),
            'Unexpected mobile HTTP response: '.$response->getContent(),
        );
    }
}
