<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class UserPermissionsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_index_returns_effective_permissions_for_current_admin_context(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/permissions');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.user_id', $context->user->id);
        $response->assertJsonPath('data.organization_id', $context->organization->id);

        $roles = $response->json('data.roles');
        $interfaces = $response->json('data.interfaces');
        $permissions = $response->json('data.permissions_flat');

        $this->assertContains('admin_viewer', $roles);
        $this->assertContains('admin', $interfaces);
        $this->assertNotContains('mobile', $interfaces);
        $this->assertContains('admin.projects.view', $permissions);
        $this->assertContains('projects.view', $permissions);
        $this->assertNotContains('projects.create', $permissions);
    }

    public function test_check_uses_current_context_and_denies_ungranted_or_foreign_permissions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $foreignOrganization = Organization::factory()->verified()->create();

        $viewResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/permissions/check', [
                'permission' => 'admin.projects.view',
                'interface' => 'admin',
            ]);

        $viewResponse->assertOk();
        $viewResponse->assertJsonPath('success', true);
        $viewResponse->assertJsonPath('data.has_permission', true);
        $viewResponse->assertJsonPath('data.has_interface_access', true);
        $viewResponse->assertJsonPath('data.context.organization_id', $context->organization->id);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/permissions/check', [
                'permission' => 'admin.projects.edit',
            ]);

        $createResponse->assertOk();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.has_permission', false);
        $createResponse->assertJsonPath('data.context.organization_id', $context->organization->id);

        $foreignResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/permissions/check', [
                'permission' => 'admin.projects.view',
                'context' => [
                    'organization_id' => $foreignOrganization->id,
                ],
            ]);

        $foreignResponse->assertOk();
        $foreignResponse->assertJsonPath('success', true);
        $foreignResponse->assertJsonPath('data.has_permission', false);
        $foreignResponse->assertJsonPath('data.context.organization_id', $foreignOrganization->id);

        $inactiveModuleResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/permissions/check', [
                'permission' => 'projects.view',
            ]);

        $inactiveModuleResponse->assertOk();
        $inactiveModuleResponse->assertJsonPath('success', true);
        $inactiveModuleResponse->assertJsonPath('data.has_permission', false);
        $inactiveModuleResponse->assertJsonPath('data.context.organization_id', $context->organization->id);
    }

    public function test_check_returns_admin_validation_contract_for_missing_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/permissions/check', []);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'permission',
            ],
        ]);
    }
}
