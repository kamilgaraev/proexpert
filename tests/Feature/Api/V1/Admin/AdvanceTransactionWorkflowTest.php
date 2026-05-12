<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AdvanceAccountTransaction;
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

    private function createOrganizationUser(Organization $organization): User
    {
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        return $user;
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
