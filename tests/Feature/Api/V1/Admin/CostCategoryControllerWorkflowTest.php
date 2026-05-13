<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\CostCategory;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class CostCategoryControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_tenant_scoped_and_tolerates_admin_registry_filters(): void
    {
        $context = AdminApiTestContext::create();
        $category = $this->createCategory($context->organization->id, [
            'name' => 'Current materials',
            'code' => 'MAT-CURRENT',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignCategory = $this->createCategory($foreignOrganization->id, [
            'name' => 'Foreign materials',
            'code' => 'MAT-FOREIGN',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/cost-categories?search=&is_active=&parent_id=&sort_by=unknown_column&sort_direction=sideways&per_page=-1&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($category->id, $ids);
        $this->assertNotContains($foreignCategory->id, $ids);
    }

    public function test_store_show_update_and_delete_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $parent = $this->createCategory($context->organization->id, [
            'name' => 'Current parent',
            'code' => 'PARENT',
        ]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignCategory = $this->createCategory($foreignOrganization->id, [
            'name' => 'Foreign category',
            'code' => 'FOREIGN',
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/cost-categories', [
                'name' => 'Current category',
                'code' => 'CURRENT',
                'external_code' => 'EXT-CURRENT',
                'parent_id' => $parent->id,
                'organization_id' => $foreignOrganization->id,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);
        $createResponse->assertJsonPath('data.parent_id', $parent->id);

        $categoryId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/cost-categories/{$categoryId}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.id', $categoryId);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/cost-categories/{$categoryId}", [
                'name' => 'Updated category',
            ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.name', 'Updated category');
        $this->assertDatabaseHas('cost_categories', [
            'id' => $categoryId,
            'code' => 'CURRENT',
        ]);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/cost-categories/{$foreignCategory->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/cost-categories/{$foreignCategory->id}", ['name' => 'Leaked update']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/cost-categories/{$foreignCategory->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/cost-categories/{$categoryId}");
        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('cost_categories', ['id' => $categoryId]);
    }

    public function test_cost_category_codes_are_unique_only_inside_current_organization_and_parent_is_scoped(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignParent = $this->createCategory($foreignOrganization->id, [
            'name' => 'Foreign parent',
            'code' => 'FOREIGN-PARENT',
        ]);

        $this->createCategory($context->organization->id, [
            'name' => 'Duplicate code',
            'code' => 'DUP',
            'external_code' => 'EXT-DUP',
        ]);
        $this->createCategory($foreignOrganization->id, [
            'name' => 'Foreign shared code',
            'code' => 'SHARED',
            'external_code' => 'EXT-SHARED',
        ]);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/cost-categories', [
                'name' => 'Duplicate category',
                'code' => 'DUP',
            ]);
        $foreignParentResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/cost-categories', [
                'name' => 'Wrong parent category',
                'code' => 'WRONG-PARENT',
                'parent_id' => $foreignParent->id,
            ]);
        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/cost-categories', [
                'name' => 'Allowed category',
                'code' => 'SHARED',
                'external_code' => 'EXT-ALLOWED',
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);
        $foreignParentResponse->assertStatus(422);
        $foreignParentResponse->assertJsonPath('success', false);
        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('success', true);
        $allowedResponse->assertJsonPath('data.organization_id', $context->organization->id);
    }

    public function test_cost_categories_with_children_or_projects_cannot_be_deleted(): void
    {
        $context = AdminApiTestContext::create();
        $parent = $this->createCategory($context->organization->id, [
            'name' => 'Parent',
            'code' => 'PARENT-USED',
        ]);
        $withProject = $this->createCategory($context->organization->id, [
            'name' => 'Project category',
            'code' => 'PROJECT-USED',
        ]);
        $this->createCategory($context->organization->id, [
            'name' => 'Child',
            'code' => 'CHILD',
            'parent_id' => $parent->id,
        ]);

        Project::factory()->create([
            'organization_id' => $context->organization->id,
            'cost_category_id' => $withProject->id,
        ]);

        $parentDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/cost-categories/{$parent->id}");
        $projectDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/cost-categories/{$withProject->id}");

        $parentDeleteResponse->assertStatus(422);
        $parentDeleteResponse->assertJsonPath('success', false);
        $projectDeleteResponse->assertStatus(422);
        $projectDeleteResponse->assertJsonPath('success', false);
    }

    private function createCategory(int $organizationId, array $overrides = []): CostCategory
    {
        return CostCategory::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Category',
            'code' => 'CAT',
            'external_code' => null,
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => 0,
            'additional_attributes' => null,
        ], $overrides));
    }
}
