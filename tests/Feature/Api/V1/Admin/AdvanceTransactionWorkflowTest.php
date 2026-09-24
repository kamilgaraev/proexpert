<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AdvanceAccountTransaction;
use App\Models\CostCategory;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\Billing\PackageAccessSource;
use App\Enums\Billing\PackageSubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class AdvanceTransactionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_advance_transaction_reads_require_view_permission(): void
    {
        $context = $this->createContextWithAdvancePermissions([]);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions/stats')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/advance-accounts/summary')
            ->assertForbidden();

        $this->withHeaders($context->authHeaders())
            ->get('/api/v1/admin/reports/advance-accounts/export/csv')
            ->assertForbidden();
    }

    public function test_read_only_role_can_read_transactions_but_cannot_load_creation_choices(): void
    {
        $context = $this->createContextWithAdvancePermissions(['advance_transactions.view']);
        $user = $this->createOrganizationUser($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $transaction = $this->createTransaction($context->organization->id, $user->id, $project->id);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $transaction->id);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions/stats')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/advance-transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions/available-users')
            ->assertForbidden();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions/available-projects')
            ->assertForbidden();
    }

    public function test_create_permission_allows_choices_scoped_to_the_current_organization(): void
    {
        $context = $this->createContextWithAdvancePermissions([
            'advance_transactions.view',
            'advance_transactions.create',
        ]);
        $organizationUser = $this->createOrganizationUser($context->organization);
        $organizationProject = Project::factory()->create(['organization_id' => $context->organization->id]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = $this->createOrganizationUser($foreignOrganization);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $usersResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions/available-users');
        $projectsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advance-transactions/available-projects');

        $usersResponse->assertOk()->assertJsonFragment(['id' => $organizationUser->id]);
        $usersResponse->assertJsonMissing(['id' => $foreignUser->id]);
        $projectsResponse->assertOk()->assertJsonFragment(['id' => $organizationProject->id]);
        $projectsResponse->assertJsonMissing(['id' => $foreignProject->id]);
    }

    public function test_advance_account_report_export_requires_and_accepts_its_permission(): void
    {
        $viewOnlyContext = $this->createContextWithAdvancePermissions([
            'reports.advance_accounts.view',
        ]);

        $this->withHeaders($viewOnlyContext->authHeaders())
            ->getJson('/api/v1/admin/reports/advance-accounts/users/999999999')
            ->assertNotFound();

        $this->withHeaders($viewOnlyContext->authHeaders())
            ->get('/api/v1/admin/reports/advance-accounts/export/csv')
            ->assertForbidden();

        $exportContext = $this->createContextWithAdvancePermissions([
            'reports.advance_accounts.export',
        ]);

        $this->withHeaders($exportContext->authHeaders())
            ->getJson('/api/v1/admin/reports/advance-accounts/export/unsupported')
            ->assertStatus(422);
    }

    public function test_read_only_role_cannot_mutate_transactions_or_manage_attachments(): void
    {
        $context = $this->createContextWithAdvancePermissions(['advance_transactions.view']);
        $user = $this->createOrganizationUser($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $transaction = $this->createTransaction($context->organization->id, $user->id, $project->id);
        $beforeCount = AdvanceAccountTransaction::query()->count();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advance-transactions', [
                'user_id' => $user->id,
                'project_id' => $project->id,
                'type' => AdvanceAccountTransaction::TYPE_ISSUE,
                'amount' => 1000,
                'description' => 'Unauthorized create',
                'document_date' => '2026-05-01',
            ])
            ->assertForbidden();

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/advance-transactions/{$transaction->id}", ['description' => 'Unauthorized edit'])
            ->assertForbidden();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/advance-transactions/{$transaction->id}/report", [
                'description' => 'Unauthorized report',
                'document_number' => 'ADV-101',
                'document_date' => '2026-05-03',
            ])
            ->assertForbidden();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/advance-transactions/{$transaction->id}/approve", [])
            ->assertForbidden();

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/advance-transactions/{$transaction->id}")
            ->assertForbidden();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/advance-transactions/{$transaction->id}/attachments", ['files' => []])
            ->assertForbidden();

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/advance-transactions/{$transaction->id}/attachments/999999")
            ->assertForbidden();

        $this->assertSame($beforeCount, AdvanceAccountTransaction::query()->count());
        $this->assertDatabaseHas('advance_account_transactions', [
            'id' => $transaction->id,
            'deleted_at' => null,
        ]);
    }

    public function test_index_returns_paginated_transactions_and_accepts_status_alias(): void
    {
        $context = $this->createContextWithAdvancePermissions(['advance_transactions.view']);
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
        $context = $this->createContextWithAdvancePermissions(['advance_transactions.create']);
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
        $context = $this->createContextWithAdvancePermissions([
            'advance_transactions.view',
            'advance_transactions.create',
            'advance_transactions.edit',
            'advance_transactions.delete',
            'advance_transactions.report',
            'advance_transactions.approve',
            'advance_transactions.files.manage',
        ]);
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

    private function createContextWithAdvancePermissions(array $permissions): AdminApiTestContext
    {
        $context = AdminApiTestContext::create();
        $this->activateAdvanceAccountingFor($context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['is_owner' => false]);
        $context->user->roleAssignments()->delete();

        $role = OrganizationCustomRole::createRole(
            organizationId: $context->organization->id,
            name: 'Advance transaction test role',
            modulePermissions: $permissions === [] ? [] : ['advance-accounting' => $permissions],
            interfaceAccess: ['admin'],
            createdBy: $context->user,
        );

        UserRoleAssignment::assignRole(
            user: $context->user,
            roleSlug: $role->slug,
            context: AuthorizationContext::getOrganizationContext($context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );

        Cache::driver('array')->flush();
        Cache::flush();

        return $context;
    }

    private function activateAdvanceAccountingFor(int $organizationId): void
    {
        $moduleDefinition = json_decode(
            (string) file_get_contents(config_path('ModuleList/addons/advance-accounting.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        Module::query()->firstOrCreate(
            ['slug' => 'advance-accounting'],
            [
                'name' => $moduleDefinition['name'],
                'version' => $moduleDefinition['version'],
                'type' => $moduleDefinition['type'],
                'billing_model' => $moduleDefinition['billing_model'],
                'category' => $moduleDefinition['category'],
                'permissions' => $moduleDefinition['permissions'],
                'is_active' => true,
                'is_system_module' => false,
                'can_deactivate' => true,
            ],
        );

        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $organizationId,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'billing_anchor_at' => now(),
            'current_period_start_at' => now(),
            'current_period_end_at' => now()->addDays(30),
            'auto_renew_enabled' => false,
        ]);

        OrganizationPackageSubscription::query()->create([
            'organization_id' => $organizationId,
            'commercial_account_id' => $account->id,
            'package_slug' => 'finance-contracts',
            'status' => PackageSubscriptionStatus::Active,
            'access_source' => PackageAccessSource::PaidPackage,
            'price_paid' => 1000,
            'current_period_start_at' => now(),
            'current_period_end_at' => now()->addDays(30),
        ]);

        Cache::flush();
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
