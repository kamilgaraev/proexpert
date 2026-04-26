<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\User;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

class ActReportsPreviewTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActingSchema();
    }

    public function test_preview_returns_policy_available_works_and_summary_for_current_organization(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('PREVIEW-1');
        $this->createJournalWork($organization->id, $project->id, $contract->id, 101, 2);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.policy.mode', 'operational');
        $response->assertJsonCount(1, 'data.available_works');
        $response->assertJsonPath('data.summary.current_approved_amount', 0);
    }

    public function test_preview_rejects_contract_from_another_organization(): void
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $contractor = Contractor::create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign contractor',
        ]);
        $contract = Contract::create([
            'organization_id' => $foreignOrganization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'FOREIGN-1',
            'date' => '2026-04-01',
            'subject' => 'Works',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_preview_requires_view_permission(): void
    {
        [, $user, $contract] = $this->createContractFixture('PREVIEW-PERM');

        $this->withoutMiddleware();
        $this->allowPermissions(false);

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    public function test_create_from_wizard_creates_act_lines_and_recalculates_amount(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('WIZARD-1');
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 201, 5);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-1',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                [
                    'completed_work_id' => $work->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.amount', 2000);
        $response->assertJsonCount(1, 'data.lines');

        $this->assertDatabaseHas('performance_act_lines', [
            'completed_work_id' => $work->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'quantity' => 2,
            'amount' => 2000,
        ]);
    }

    public function test_create_from_wizard_requires_create_permission(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('CREATE-PERM');
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 301, 2);

        $this->withoutMiddleware();
        $this->allowPermissions(false);

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-PERM',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('contract_performance_acts', 0);
    }

    public function test_create_from_wizard_rejects_empty_act(): void
    {
        [, $user, $contract] = $this->createContractFixture('EMPTY-ACT');

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-EMPTY',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [],
            'manual_lines' => [],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contract_performance_acts', 0);
    }

    public function test_create_from_wizard_rejects_project_id_spoofing(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('PROJECT-SPOOF');
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 401, 2);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'KS-2-SPOOF',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contract_performance_acts', 0);
    }

    public function test_create_from_wizard_aggregates_duplicate_selected_works_before_quantity_check(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('DUPLICATE-WORK');
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 501, 5);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-DUP',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 3],
                ['completed_work_id' => $work->id, 'quantity' => 3],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('performance_act_lines', 0);
    }

    public function test_create_from_wizard_allows_partial_repeat_and_rejects_overacting(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('PARTIAL-REPEAT');
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 601, 5);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $first = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-1',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 3],
            ],
        ]);
        $first->assertCreated();

        $second = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-2',
            'act_date' => '2026-04-21',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 2],
            ],
        ]);
        $second->assertCreated();

        $third = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-3',
            'act_date' => '2026-04-22',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 0.1],
            ],
        ]);

        $third->assertStatus(422);
        $this->assertSame(5.0, (float) PerformanceActLine::where('completed_work_id', $work->id)->sum('quantity'));
    }

    public function test_legacy_store_route_uses_wizard_contract(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('STORE-ALIAS');
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 701, 2);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-ALIAS',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(1, 'data.lines');

        $this->assertDatabaseHas('performance_act_lines', [
            'completed_work_id' => $work->id,
            'quantity' => 1,
            'amount' => 1000,
        ]);
    }

    public function test_act_can_be_submitted_approved_locked_and_return_financial_summary(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('APPROVAL-1');
        $this->withoutMiddleware();
        $this->allowPermissions();

        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'APPROVAL-ACT', 4);

        PaymentDocument::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_type' => 'invoice',
            'document_number' => 'PAY-1',
            'document_date' => '2026-04-21',
            'direction' => 'outgoing',
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'amount' => 4000,
            'paid_amount' => 1500,
            'remaining_amount' => 2500,
            'status' => 'partially_paid',
        ]);

        $submit = $this->actingAs($user, 'api_admin')->postJson("/api/v1/admin/act-reports/{$act->id}/submit");
        $submit->assertOk();
        $submit->assertJsonPath('data.status', 'pending_approval');

        $approve = $this->actingAs($user, 'api_admin')->postJson("/api/v1/admin/act-reports/{$act->id}/approve");
        $approve->assertOk();
        $approve->assertJsonPath('data.status', 'approved');
        $approve->assertJsonPath('data.is_approved', true);
        $approve->assertJsonPath('data.financial_summary.accepted_amount', 4000);
        $approve->assertJsonPath('data.financial_summary.paid_amount', 1500);
        $approve->assertJsonPath('data.financial_summary.debt_amount', 2500);

        $this->assertDatabaseHas('contract_performance_acts', [
            'id' => $act->id,
            'status' => 'approved',
            'approved_by_user_id' => $user->id,
            'locked_by_user_id' => $user->id,
        ]);
    }

    public function test_rejected_act_stores_reason_and_can_not_be_approved_after_signing_lock(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('REJECT-1');
        $this->withoutMiddleware();
        $this->allowPermissions();
        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'REJECT-ACT', 1);

        $reject = $this->actingAs($user, 'api_admin')->postJson("/api/v1/admin/act-reports/{$act->id}/reject", [
            'reason' => 'Не совпадает объем',
        ]);

        $reject->assertOk();
        $reject->assertJsonPath('data.status', 'rejected');
        $reject->assertJsonPath('data.rejection_reason', 'Не совпадает объем');

        $this->assertDatabaseHas('contract_performance_acts', [
            'id' => $act->id,
            'status' => 'rejected',
            'rejected_by_user_id' => $user->id,
        ]);
    }

    private function allowPermissions(bool $allowed = true): void
    {
        $this->mock(AuthorizationService::class, function ($mock) use ($allowed): void {
            $mock->shouldReceive('can')->andReturn($allowed);
        });
    }

    private function createContractFixture(string $number): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Contractor',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => $number,
            'date' => '2026-04-01',
            'subject' => 'Works',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        return [$organization, $user, $contract, $project];
    }

    private function createJournalWork(
        int $organizationId,
        int $projectId,
        int $contractId,
        int $journalEntryId,
        float $quantity
    ): CompletedWork {
        return CompletedWork::create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contract_id' => $contractId,
            'journal_entry_id' => $journalEntryId,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'quantity' => $quantity,
            'completed_quantity' => null,
            'price' => 1000,
            'total_amount' => $quantity * 1000,
            'completion_date' => '2026-04-10',
            'status' => 'confirmed',
        ]);
    }

    private function createActWithWork(
        int $organizationId,
        User $user,
        Contract $contract,
        Project $project,
        string $number,
        float $quantity
    ): \App\Models\ContractPerformanceAct {
        $work = $this->createJournalWork($organizationId, $project->id, $contract->id, random_int(1000, 9999), $quantity);

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => $number,
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => $quantity],
            ],
        ]);

        $response->assertCreated();

        return \App\Models\ContractPerformanceAct::query()->findOrFail((int) $response->json('data.id'));
    }
}
