<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AdvanceAccountTransaction;
use App\Models\CostCategory;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class AdvanceTransactionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_transactions_and_accepts_status_alias(): void
    {
        $context = AdminApiTestContext::create();
        $user = $this->createOrganizationUser($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        $pending = $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
            'amount' => 1000,
            'document_date' => '2026-05-01',
        ]);
        $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'reporting_status' => AdvanceAccountTransaction::STATUS_REPORTED,
            'amount' => 500,
            'document_date' => '2026-05-02',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = $this->createOrganizationUser($foreignOrganization);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $this->createTransaction($foreignOrganization->id, $foreignUser->id, $foreignProject->id, [
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
            'amount' => 9000,
            'document_date' => '2026-05-03',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions?status=pending&per_page=1&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $pending->id);
        $response->assertJsonPath('data.0.reporting_status', AdvanceAccountTransaction::STATUS_PENDING);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.per_page', 1);
    }

    public function test_create_rejects_foreign_user_and_project(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = $this->createOrganizationUser($foreignOrganization);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advance-transactions', [
                'user_id' => $foreignUser->id,
                'project_id' => $foreignProject->id,
                'type' => AdvanceAccountTransaction::TYPE_ISSUE,
                'amount' => 1000,
                'description' => 'Advance for works',
                'document_date' => '2026-05-01',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseMissing('advance_account_transactions', [
            'organization_id' => $context->organization->id,
            'user_id' => $foreignUser->id,
        ]);
    }

    public function test_user_account_routes_are_paginated_and_reject_foreign_projects(): void
    {
        $context = AdminApiTestContext::create();
        $user = $this->createOrganizationUser($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
            'amount' => 700,
        ]);

        $historyResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/users/{$user->id}/advance-transactions?per_page=1");

        $historyResponse->assertOk();
        $historyResponse->assertJsonPath('success', true);
        $historyResponse->assertJsonPath('data.0.user_id', $user->id);
        $historyResponse->assertJsonPath('meta.total', 1);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $issueResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/users/{$user->id}/issue-funds", [
                'amount' => 1000,
                'project_id' => $foreignProject->id,
                'description' => 'Foreign project advance',
                'document_date' => '2026-05-01',
            ]);

        $issueResponse->assertStatus(422);
        $this->assertDatabaseMissing('advance_account_transactions', [
            'organization_id' => $context->organization->id,
            'project_id' => $foreignProject->id,
        ]);
    }

    public function test_report_approve_and_delete_follow_advance_account_workflow(): void
    {
        $context = AdminApiTestContext::create();
        $user = $this->createOrganizationUser($context->organization, [
            'current_balance' => 1500,
            'total_issued' => 1500,
            'total_reported' => 0,
        ]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $category = $this->createCostCategory($context->organization->id);

        $pendingForReport = $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'amount' => 1000,
            'balance_after' => 1500,
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
        ]);
        $pendingForDelete = $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'amount' => 500,
            'balance_after' => 500,
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
        ]);

        $reportResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/advance-transactions/{$pendingForReport->id}/report", [
                'description' => 'Receipt for delivery',
                'document_number' => 'ADV-101',
                'document_date' => '2026-05-03',
                'cost_category_id' => $category->id,
            ]);

        $reportResponse->assertOk();
        $reportResponse->assertJsonPath('success', true);
        $reportResponse->assertJsonPath('data.reporting_status', AdvanceAccountTransaction::STATUS_REPORTED);
        $reportResponse->assertJsonPath('data.document_number', 'ADV-101');
        $reportResponse->assertJsonPath('data.cost_category_id', $category->id);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'total_reported' => 1000,
        ]);

        $approveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/advance-transactions/{$pendingForReport->id}/approve", [
                'accounting_data' => ['external_id' => '1C-ADV-101'],
            ]);

        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('success', true);
        $approveResponse->assertJsonPath('data.reporting_status', AdvanceAccountTransaction::STATUS_APPROVED);
        $approveResponse->assertJsonPath('data.approved_by_user_id', $context->user->id);

        $deleteApprovedResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/advance-transactions/{$pendingForReport->id}");

        $deleteApprovedResponse->assertStatus(400);
        $deleteApprovedResponse->assertJsonPath('success', false);
        $this->assertDatabaseHas('advance_account_transactions', [
            'id' => $pendingForReport->id,
            'reporting_status' => AdvanceAccountTransaction::STATUS_APPROVED,
            'deleted_at' => null,
        ]);

        $deletePendingResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/advance-transactions/{$pendingForDelete->id}");

        $deletePendingResponse->assertOk();
        $deletePendingResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('advance_account_transactions', ['id' => $pendingForDelete->id]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'current_balance' => 1000,
            'total_issued' => 1000,
        ]);
    }

    private function createOrganizationUser(Organization $organization, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ], $overrides));

        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        return $user;
    }

    private function createCostCategory(int $organizationId): CostCategory
    {
        return CostCategory::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Project expenses',
            'code' => 'ADV-COST',
            'external_code' => null,
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => 0,
            'additional_attributes' => null,
        ]);
    }

    private function createTransaction(int $organizationId, int $userId, int $projectId, array $overrides = []): AdvanceAccountTransaction
    {
        return AdvanceAccountTransaction::query()->create(array_merge([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'type' => AdvanceAccountTransaction::TYPE_ISSUE,
            'amount' => 100,
            'description' => 'Advance',
            'document_date' => '2026-05-01',
            'balance_after' => 100,
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
        ], $overrides));
    }
}
