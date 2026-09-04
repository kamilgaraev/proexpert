<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\User;
use App\Services\Acting\LegacyPerformanceActBasisBackfillService;
use Illuminate\Support\Facades\DB;
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

    public function test_preview_exposes_fixed_contract_amount_and_available_balance(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('PREVIEW-LIMIT');
        $contract->forceFill(['is_fixed_amount' => true])->saveQuietly();
        ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'KS-2-APPROVED',
            'act_date' => '2026-03-31',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'amount' => 1000,
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
            'created_by_user_id' => $user->id,
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.contract_amount_limit.is_fixed', true);
        $response->assertJsonPath('data.contract_amount_limit.contract_amount', '100000.00');
        $response->assertJsonPath('data.contract_amount_limit.approved_amount', '1000.00');
        $response->assertJsonPath('data.contract_amount_limit.remaining_amount', '99000.00');
        $response->assertJsonPath('data.contract_amount_limit.currency', 'RUB');
    }

    public function test_preview_exposes_only_approved_variation_orders_for_the_selected_contract_project(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('PREVIEW-VARIATION');
        $allocationId = DB::table('contract_project_allocations')->insertGetId([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'allocation_type' => 'fixed',
            'allocated_amount' => 100000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approved = ChangeRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'change_number' => 'CH-PREVIEW-1',
            'title' => 'Согласованные допработы',
            'reason' => 'customer_request',
            'description' => 'Основание ручной строки',
            'initiator_type' => 'customer',
            'status' => 'approved',
            'reporting_currency' => 'RUB',
            'reporting_contract_project_allocation_id' => $allocationId,
            'approved_at' => now(),
        ]);
        $variation = VariationOrder::query()->create([
            'organization_id' => $organization->id,
            'change_request_id' => $approved->id,
            'variation_number' => 'VO-PREVIEW-1',
            'amount' => 1500,
            'description' => 'Согласованный лимит',
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.available_variation_orders');
        $response->assertJsonPath('data.available_variation_orders.0.id', $variation->id);
        $response->assertJsonPath('data.available_variation_orders.0.remaining_amount', '1500.00');
    }

    public function test_preview_and_wizard_accept_journal_work_resolved_by_estimate_contract_coverage(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('PREVIEW-COVERAGE');
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Estimate',
            'status' => 'approved',
            'total_amount' => 10000,
        ]);
        $estimateItem = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '5',
            'item_type' => 'work',
            'name' => 'Concrete',
            'quantity' => 20,
            'quantity_total' => 20,
            'unit_price' => 1000,
            'total_amount' => 20000,
        ]);
        ContractEstimateItem::create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $estimateItem->id,
            'quantity' => 20,
            'amount' => 20000,
        ]);
        $this->approveEstimateSnapshot($estimate, $user);
        $work = CompletedWork::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => null,
            'estimate_item_id' => $estimateItem->id,
            'journal_entry_id' => 102,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'quantity' => 15,
            'completed_quantity' => 15,
            'price' => 1000,
            'total_amount' => 15000,
            'completion_date' => '2026-04-28',
            'status' => 'confirmed',
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $preview = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);

        $preview->assertOk();
        $preview->assertJsonCount(1, 'data.available_works');
        $preview->assertJsonPath('data.available_works.0.id', $work->id);

        $create = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-COVERAGE',
            'act_date' => '2026-04-28',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 15],
            ],
        ]);

        $create->assertCreated();
        $create->assertJsonPath('data.amount', 15000);
        $this->assertDatabaseHas('completed_works', [
            'id' => $work->id,
            'contract_id' => $contract->id,
            'contractor_id' => $contract->contractor_id,
        ]);
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
        $response->assertJsonPath('data.project_name', $project->name);
        $response->assertJsonPath('data.contractor_name', 'Contractor');
        $response->assertJsonCount(1, 'data.lines');

        $this->assertDatabaseHas('performance_act_lines', [
            'completed_work_id' => $work->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'quantity' => 2,
            'amount' => 2000,
        ]);
    }

    public function test_create_from_wizard_rejects_amount_above_fixed_contract_balance(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('WIZARD-LIMIT');
        $contract->forceFill(['is_fixed_amount' => true, 'total_amount' => 1500])->saveQuietly();
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 1205, 2);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-LIMIT',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            'Нельзя оформить акт на 2 000,00 ₽: сумма договора — 1 500,00 ₽, уже утверждено — 0,00 ₽, доступно — 1 500,00 ₽. Уменьшите сумму акта или оформите изменение суммы договора.'
        );
        $this->assertDatabaseMissing('contract_performance_acts', [
            'act_document_number' => 'KS-2-LIMIT',
        ]);
    }

    public function test_submit_rejects_existing_draft_above_fixed_contract_balance(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('SUBMIT-LIMIT');

        $this->withoutMiddleware();
        $this->allowPermissions();

        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'KS-2-SUBMIT-LIMIT', 2);
        $contract->forceFill(['is_fixed_amount' => true, 'total_amount' => 1500])->saveQuietly();

        $response = $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/submit");

        $response->assertStatus(422);
        $this->assertSame(ContractPerformanceAct::STATUS_DRAFT, $act->fresh()->status);
    }

    public function test_submit_uses_current_contract_total_after_applied_amount_change(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('SUBMIT-AGREEMENT-LIMIT');

        $this->withoutMiddleware();
        $this->allowPermissions();

        $act = $this->createActWithWork(
            $organization->id,
            $user,
            $contract,
            $project,
            'KS-2-SUBMIT-AGREEMENT-LIMIT',
            5,
        );
        $contract->forceFill([
            'is_fixed_amount' => true,
            'base_amount' => 2100,
            'total_amount' => 5715,
        ])->saveQuietly();

        $response = $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/submit");

        $response->assertOk();
        $this->assertSame(ContractPerformanceAct::STATUS_PENDING_APPROVAL, $act->fresh()->status);
    }

    public function test_approve_rejects_pending_act_above_fixed_contract_balance(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('APPROVE-LIMIT');

        $this->withoutMiddleware();
        $this->allowPermissions();

        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'KS-2-APPROVE-LIMIT', 2);
        $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/submit")
            ->assertOk();
        $contract->forceFill(['is_fixed_amount' => true, 'total_amount' => 1500])->saveQuietly();

        $response = $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/approve");

        $response->assertStatus(422);
        $this->assertSame(ContractPerformanceAct::STATUS_PENDING_APPROVAL, $act->fresh()->status);
    }

    public function test_create_from_wizard_derives_project_from_the_selected_contract_work(): void
    {
        [$organization, $user, $contract, $contractProject] = $this->createContractFixture('WIZARD-MULTI-PROJECT');
        $workProject = Project::factory()->create(['organization_id' => $organization->id]);
        $contract->forceFill(['is_multi_project' => true])->save();
        $contract->projects()->attach([$contractProject->id, $workProject->id]);
        $work = $this->createJournalWork($organization->id, $workProject->id, $contract->id, 202, 5);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-MULTI-PROJECT',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $work->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.project_name', $workProject->name);
        $this->assertDatabaseHas('contract_performance_acts', [
            'contract_id' => $contract->id,
            'project_id' => $workProject->id,
            'act_document_number' => 'KS-2-MULTI-PROJECT',
        ]);
    }

    public function test_create_from_wizard_rejects_work_from_project_not_linked_to_contract(): void
    {
        [$organization, $user, $contract] = $this->createContractFixture('WIZARD-CROSS-PROJECT');
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $secondWork = $this->createJournalWork($organization->id, $otherProject->id, $contract->id, 204, 5);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-CROSS-PROJECT',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                ['completed_work_id' => $secondWork->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseMissing('contract_performance_acts', [
            'act_document_number' => 'KS-2-CROSS-PROJECT',
        ]);
    }

    public function test_create_from_wizard_uses_estimate_contract_price_when_completed_work_amount_is_empty(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('WIZARD-PRICE');
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Estimate',
            'status' => 'approved',
            'total_amount' => 6000,
        ]);
        $estimateItem = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Concrete',
            'quantity' => 6,
            'quantity_total' => 6,
            'unit_price' => 1000,
            'total_amount' => 6000,
        ]);
        ContractEstimateItem::create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $estimateItem->id,
            'quantity' => 6,
            'amount' => 6000,
        ]);
        $this->approveEstimateSnapshot($estimate, $user);
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 1201, 3);
        $work->update([
            'estimate_item_id' => $estimateItem->id,
            'price' => null,
            'total_amount' => null,
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-PRICE',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                [
                    'completed_work_id' => $work->id,
                    'quantity' => 3,
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', 3000);
        $response->assertJsonPath('data.lines.0.unit_price', 1000);
        $response->assertJsonPath('data.lines.0.amount', 3000);
    }

    public function test_create_from_wizard_applies_estimate_vat_to_completed_work_price(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('WIZARD-VAT');
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'name' => 'Estimate',
            'status' => 'approved',
            'total_amount' => 6000,
            'total_amount_with_vat' => 7200,
            'vat_rate' => 20,
        ]);
        $estimateItem = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Concrete',
            'quantity' => 6,
            'quantity_total' => 6,
            'unit_price' => 1000,
            'total_amount' => 6000,
        ]);
        $this->approveEstimateSnapshot($estimate, $user);
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 1203, 3);
        $work->update([
            'estimate_item_id' => $estimateItem->id,
            'price' => 1000,
            'total_amount' => 3000,
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-VAT',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                [
                    'completed_work_id' => $work->id,
                    'quantity' => 3,
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', 3600);
        $response->assertJsonPath('data.lines.0.unit_price', 1200);
        $response->assertJsonPath('data.lines.0.amount', 3600);
    }

    public function test_create_from_wizard_uses_full_estimate_item_amount_for_composite_price(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('WIZARD-COMPOSITE-PRICE');
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'name' => 'Estimate with overhead and profit',
            'status' => 'approved',
            'total_amount' => 95250,
            'total_amount_with_vat' => 114300,
            'vat_rate' => 20,
        ]);
        $estimateItem = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Reinforcement installation',
            'quantity' => 100,
            'quantity_total' => null,
            'unit_price' => 750,
            'total_amount' => 95250,
        ]);
        $this->approveEstimateSnapshot($estimate, $user);
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 1204, 5);
        $work->update([
            'estimate_item_id' => $estimateItem->id,
            'price' => 952.5,
            'total_amount' => 4762.5,
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'KS-2-COMPOSITE-PRICE',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [
                [
                    'completed_work_id' => $work->id,
                    'quantity' => 5,
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', 5715);
        $response->assertJsonPath('data.lines.0.unit_price', 1143);
        $response->assertJsonPath('data.lines.0.amount', 5715);
    }

    public function test_approve_rejects_zero_amount_act_without_stored_financial_basis(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('APPROVE-PRICE');
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Estimate',
            'status' => 'approved',
            'total_amount' => 6000,
        ]);
        $estimateItem = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Concrete',
            'quantity' => 6,
            'quantity_total' => 6,
            'unit_price' => 1000,
            'total_amount' => 6000,
        ]);
        ContractEstimateItem::create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $estimateItem->id,
            'quantity' => 6,
            'amount' => 6000,
        ]);
        $this->approveEstimateSnapshot($estimate, $user);
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 1202, 3);
        $work->update([
            'estimate_item_id' => $estimateItem->id,
            'price' => null,
            'total_amount' => null,
        ]);
        $act = \App\Models\ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'KS-2-REPAIR',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'amount' => 0,
            'status' => \App\Models\ContractPerformanceAct::STATUS_DRAFT,
            'is_approved' => false,
            'created_by_user_id' => $user->id,
        ]);
        PerformanceActLine::create([
            'performance_act_id' => $act->id,
            'completed_work_id' => $work->id,
            'estimate_item_id' => $estimateItem->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'title' => 'Work',
            'quantity' => 3,
            'unit_price' => 0,
            'amount' => 0,
        ]);
        $act->completedWorks()->syncWithoutDetaching([
            $work->id => [
                'included_quantity' => 3,
                'included_amount' => 0,
            ],
        ]);

        $this->withoutMiddleware();
        $this->allowPermissions();

        $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/submit")
            ->assertOk();
        $response = $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/approve");

        $response->assertStatus(422);
        $this->assertSame(ContractPerformanceAct::STATUS_PENDING_APPROVAL, $act->fresh()->status);
        $this->assertDatabaseHas('performance_act_lines', [
            'id' => 1,
            'unit_price' => 0,
            'amount' => 0,
        ]);
    }

    public function test_official_pdf_views_render_with_inline_address_fields(): void
    {
        $data = [
            'act' => (object) [
                'id' => 1,
                'act_document_number' => '1',
                'act_date' => now(),
            ],
            'contract' => (object) [
                'number' => 'CONTRACT-1',
                'date' => now(),
            ],
            'customer_org' => (object) [
                'legal_name' => 'Customer',
                'name' => 'Customer',
                'tax_number' => '1650000000',
                'postal_code' => '420000',
                'city' => 'Kazan',
                'address' => 'Main street',
            ],
            'contractor' => (object) [
                'name' => 'Contractor',
                'inn' => '1660000000',
                'legal_address' => 'Contractor street',
            ],
            'project' => (object) [
                'name' => 'Project',
            ],
            'works' => collect([
                [
                    'title' => 'Concrete works',
                    'unit' => 'm3',
                    'quantity' => 3,
                    'unit_price' => 1000,
                    'amount' => 3000,
                    'notes' => null,
                    'code' => '1',
                ],
            ]),
            'total_amount' => 3000,
            'vat_amount' => 500,
            'contract_amount' => 100000,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'total_from_start' => 9000,
            'year_total' => 6000,
        ];

        $ks2 = view('estimates.exports.ks2', $data)->render();
        $ks3 = view('estimates.exports.ks3', $data)->render();

        $this->assertStringContainsString('<html lang="ru">', $ks2);
        $this->assertStringContainsString('<html lang="ru">', $ks3);
        $this->assertStringContainsString('size: A4 landscape', $ks2);
        $this->assertStringContainsString('size: A4 landscape', $ks3);
        $this->assertStringContainsString('Унифицированная форма № КС-2', $ks2);
        $this->assertStringContainsString('Унифицированная форма № КС-3', $ks3);
        $this->assertStringContainsString('0322005', $ks2);
        $this->assertStringContainsString('0322001', $ks3);
        $this->assertStringContainsString('О приемке выполненных работ', $ks2);
        $this->assertStringContainsString('О стоимости выполненных работ и затрат', $ks3);
        $this->assertStringNotContainsString("\u{0420}\u{0459}", $ks2);
        $this->assertStringNotContainsString("\u{0420}\u{0459}", $ks3);
    }

    public function test_ks6_and_ks6a_pdf_views_render_official_landscape_layout(): void
    {
        $organization = (object) [
            'legal_name' => 'ООО "Городстрой"',
            'name' => 'ООО "Городстрой"',
            'tax_number' => '1650000000',
            'postal_code' => '420000',
            'city' => 'Казань',
            'address' => 'ул. Центральная, 1',
            'phone' => '+7 843 000-00-00',
        ];
        $contractor = (object) [
            'name' => 'ООО "Быстрострой"',
            'inn' => '1660000000',
            'legal_address' => 'ул. Подрядная, 2',
            'phone' => '+7 843 111-11-11',
        ];
        $project = (object) [
            'name' => 'Торговый центр',
            'address' => 'улица Весенняя, 55',
            'organization' => $organization,
        ];
        $contract = (object) [
            'id' => 1,
            'number' => 'ДП-1',
            'date' => now(),
            'subject' => 'ЛСР 55-17 Устройство стен внутренних',
            'total_amount' => 270216,
            'contractor' => $contractor,
            'organization' => $organization,
        ];
        $journal = (object) [
            'id' => 1,
            'journal_number' => 'Ж-1',
            'project' => $project,
            'organization' => $organization,
            'contract' => $contract,
            'createdBy' => (object) ['name' => 'Иванов И.И.'],
        ];

        $ks6 = view('estimates.exports.ks6', [
            'journal' => $journal,
            'entries' => collect([
                (object) [
                    'entry_number' => 1,
                    'entry_date' => now(),
                    'work_description' => 'Кладка стен из легкобетонных камней',
                    'workVolumes' => collect([
                        (object) [
                            'quantity' => 6,
                            'measurementUnit' => (object) ['short_name' => 'м2'],
                            'workType' => (object) ['name' => 'Кладка стен'],
                            'estimateItem' => null,
                        ],
                    ]),
                    'weather_conditions' => ['temperature' => 18, 'precipitation' => 'без осадков'],
                    'createdBy' => (object) ['name' => 'Петров П.П.'],
                ],
            ]),
            'period_from' => now()->startOfMonth(),
            'period_to' => now()->endOfMonth(),
        ])->render();

        $ks6a = view('estimates.exports.ks6a', [
            'contract' => $contract,
            'project' => $project,
            'customer_org' => $organization,
            'contractor' => $contractor,
            'month_groups' => [
                ['key' => '2026-03', 'title' => 'март 2026 г.'],
                ['key' => '2026-04', 'title' => 'апрель 2026 г.'],
            ],
            'remaining_label' => 'на май 2026 г.',
            'rows' => collect([
                [
                    'number' => 1,
                    'estimate_position' => '1',
                    'title' => 'Кладка стен из легкобетонных камней',
                    'rate_code' => 'ТЕР08-03-002-01',
                    'unit' => 'м2',
                    'unit_price' => 898.03,
                    'estimate_quantity' => 6,
                    'estimate_amount' => 5388,
                    'months' => [
                        '2026-03' => ['quantity' => 2, 'amount' => 1796.06, 'from_start' => 1796.06],
                        '2026-04' => ['quantity' => 2, 'amount' => 1796.06, 'from_start' => 3592.12],
                    ],
                    'remaining_quantity' => 2,
                    'remaining_amount' => 1795.88,
                ],
            ]),
            'total_estimate_amount' => 5388,
            'total_remaining_amount' => 1795.88,
        ])->render();

        $this->assertStringContainsString('<html lang="ru">', $ks6);
        $this->assertStringContainsString('<html lang="ru">', $ks6a);
        $this->assertStringContainsString('size: A4 landscape', $ks6);
        $this->assertStringContainsString('size: A4 landscape', $ks6a);
        $this->assertStringContainsString('Типовая межотраслевая форма № КС-6', $ks6);
        $this->assertStringContainsString('Унифицированная форма № КС-6а', $ks6a);
        $this->assertStringContainsString('0322002', $ks6);
        $this->assertStringContainsString('0322006', $ks6a);
        $this->assertStringContainsString('ОБЩИЙ ЖУРНАЛ РАБОТ', $ks6);
        $this->assertStringContainsString('Журнал учета выполненных работ', $ks6a);
        $this->assertStringContainsString('Кладка стен из легкобетонных камней', $ks6a);
        $this->assertStringNotContainsString("\u{0420}\u{0459}", $ks6);
        $this->assertStringNotContainsString("\u{0420}\u{0459}", $ks6a);
    }

    public function test_recalculate_does_not_rewrite_approved_act_history(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('SHOW-VAT');
        $estimate = Estimate::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'name' => 'Estimate',
            'status' => 'approved',
            'total_amount' => 600,
            'total_amount_with_vat' => 720,
            'vat_rate' => 20,
        ]);
        $estimateItem = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Concrete',
            'quantity' => 6,
            'quantity_total' => 6,
            'unit_price' => 100,
            'total_amount' => 600,
        ]);
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 1204, 3);
        $work->update([
            'estimate_item_id' => $estimateItem->id,
            'price' => 100,
            'total_amount' => 300,
        ]);
        $act = \App\Models\ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'KS-2-SHOW-VAT',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'amount' => 300,
            'status' => \App\Models\ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
            'created_by_user_id' => $user->id,
        ]);
        PerformanceActLine::create([
            'performance_act_id' => $act->id,
            'completed_work_id' => $work->id,
            'estimate_item_id' => $estimateItem->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'title' => 'Work',
            'quantity' => 3,
            'unit_price' => 100,
            'amount' => 300,
            'basis_snapshot' => [
                'base_unit_price' => '100.00',
                'unit_price_with_vat' => '120.00',
                'vat_rate' => '20.00',
            ],
        ]);
        $act->completedWorks()->syncWithoutDetaching([
            $work->id => [
                'included_quantity' => 3,
                'included_amount' => 300,
            ],
        ]);

        app(\App\Services\ActReport\ActReportWorkflowService::class)->recalculatePricedLines($act);

        $this->assertSame(300.0, (float) $act->fresh()->amount);
        $this->assertDatabaseHas('performance_act_lines', [
            'performance_act_id' => $act->id,
            'unit_price' => 100,
            'amount' => 300,
        ]);
    }

    public function test_legacy_act_basis_backfill_preserves_stored_financial_history_idempotently(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('LEGACY-BASIS');
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 1205, 2);
        $act = ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'KS-2-LEGACY-BASIS',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'amount' => '251.10',
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
            'created_by_user_id' => $user->id,
        ]);
        $line = PerformanceActLine::create([
            'performance_act_id' => $act->id,
            'completed_work_id' => $work->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'title' => 'Историческая работа',
            'quantity' => 2,
            'unit_price' => '125.55',
            'amount' => '251.10',
        ]);

        $service = app(LegacyPerformanceActBasisBackfillService::class);

        $this->assertSame(1, $service->backfill());
        $this->assertSame(0, $service->backfill());

        $line->refresh();
        $act->refresh();
        $this->assertSame('legacy_act_line', $line->basis_snapshot['basis_type']);
        $this->assertSame(125.55, (float) $line->basis_snapshot['unit_price_with_vat']);
        $this->assertSame(251.10, (float) $line->basis_snapshot['legacy_amount']);
        $this->assertSame(251.10, (float) $act->amount_without_vat);
        $this->assertSame(0.0, (float) $act->vat_amount);
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

    public function test_act_financial_summary_uses_its_own_ledger_and_net_refunds(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('ACT-LEDGER');
        $this->withoutMiddleware();
        $this->allowPermissions();
        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'ACT-LEDGER', 4);
        $base = [
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_type' => 'invoice',
            'direction' => 'outgoing',
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
            'amount' => 500,
            'paid_amount' => 499,
            'remaining_amount' => 1,
            'currency' => 'RUB',
            'status' => 'partially_paid',
        ];
        $documentId = DB::table('payment_documents')->insertGetId($base + ['document_number' => 'ACT-OWN']);
        foreach ([170, -20] as $amount) {
            DB::table('payment_transactions')->insert([
                'payment_document_id' => $documentId,
                'organization_id' => $organization->id,
                'amount' => $amount,
                'currency' => 'RUB',
                'status' => 'completed',
            ]);
        }
        foreach ([
            ['invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id],
            ['invoiceable_id' => $act->id + 1000],
            ['organization_id' => $organization->id + 1000],
            ['status' => 'cancelled'],
            ['deleted_at' => now()],
            ['currency' => 'USD'],
        ] as $index => $overrides) {
            $ignoredId = DB::table('payment_documents')->insertGetId(array_merge($base, $overrides, ['document_number' => 'IGNORED-'.$index]));
            DB::table('payment_transactions')->insert([
                'payment_document_id' => $ignoredId,
                'organization_id' => $overrides['organization_id'] ?? $organization->id,
                'amount' => 300,
                'currency' => $overrides['currency'] ?? 'RUB',
                'status' => 'completed',
            ]);
        }
        $summary = app(\App\Services\ActReport\ActReportWorkflowService::class)->financialSummary($act);
        self::assertSame('4000.00', $summary['accepted_amount']);
        self::assertSame('150.00', $summary['paid_amount']);
        self::assertSame('3850.00', $summary['debt_amount']);
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
        $approve->assertJsonPath('data.financial_summary.accepted_amount', '4000.00');
        $approve->assertJsonPath('data.financial_summary.paid_amount', '0.00');
        $approve->assertJsonPath('data.financial_summary.debt_amount', '4000.00');

        $this->assertDatabaseHas('contract_performance_acts', [
            'id' => $act->id,
            'status' => 'approved',
            'approved_by_user_id' => $user->id,
            'locked_by_user_id' => $user->id,
        ]);
    }

    public function test_draft_act_cannot_skip_submission_and_be_approved(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('APPROVAL-SKIP');
        $this->withoutMiddleware();
        $this->allowPermissions();
        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'APPROVAL-SKIP-ACT', 1);

        $response = $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/approve");

        $response->assertStatus(422);
        self::assertSame(ContractPerformanceAct::STATUS_DRAFT, $act->fresh()->status);
    }

    public function test_create_from_wizard_rejects_non_active_contract(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('CLOSED-CONTRACT-ACT');
        $contract->forceFill(['status' => 'completed'])->save();
        $work = $this->createJournalWork($organization->id, $project->id, $contract->id, 9901, 1);
        $this->withoutMiddleware();
        $this->allowPermissions();

        $response = $this->actingAs($user, 'api_admin')->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'ACT-CLOSED-CONTRACT',
            'act_date' => '2026-04-20',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'selected_works' => [[
                'completed_work_id' => $work->id,
                'quantity' => 1,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('contract_performance_acts', [
            'act_document_number' => 'ACT-CLOSED-CONTRACT',
        ]);
    }

    public function test_rejected_act_stores_reason_and_can_not_be_approved_after_signing_lock(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('REJECT-1');
        $this->withoutMiddleware();
        $this->allowPermissions();
        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'REJECT-ACT', 1);

        $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/submit")
            ->assertOk();

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

    public function test_draft_act_cannot_be_rejected_before_submission(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('REJECT-SKIP');
        $this->withoutMiddleware();
        $this->allowPermissions();
        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'REJECT-SKIP-ACT', 1);

        $response = $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/reject", [
                'reason' => 'Отклонение без согласования',
            ]);

        $response->assertStatus(422);
        self::assertSame(ContractPerformanceAct::STATUS_DRAFT, $act->fresh()->status);
    }

    public function test_status_workflow_requires_exact_transition_permissions(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('WORKFLOW-RBAC');
        $this->withoutMiddleware();
        $this->allowPermissions();
        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'WORKFLOW-RBAC-ACT', 1);

        $this->allowPermissionsExcept('act_reports.edit');

        $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/submit")
            ->assertForbidden();

        $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/reject", [
                'reason' => 'Недостаточно прав',
            ])
            ->assertForbidden();

        $this->allowPermissionsExcept('act_reports.approve');

        $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/act-reports/{$act->id}/approve")
            ->assertForbidden();
    }

    public function test_export_requires_exact_export_permission_before_generating_document(): void
    {
        [$organization, $user, $contract, $project] = $this->createContractFixture('EXPORT-RBAC');
        $this->withoutMiddleware();
        $this->allowPermissions();
        $act = $this->createActWithWork($organization->id, $user, $contract, $project, 'EXPORT-RBAC-ACT', 1);

        $this->allowPermissionsExcept('act_reports.export.pdf');

        $this->actingAs($user, 'api_admin')
            ->getJson("/api/v1/admin/act-reports/{$act->id}/export/pdf")
            ->assertForbidden();

        $this->allowPermissionsExcept('act_reports.export.excel');

        $this->actingAs($user, 'api_admin')
            ->getJson("/api/v1/admin/act-reports/{$act->id}/export/excel")
            ->assertForbidden();

        $this->actingAs($user, 'api_admin')
            ->getJson("/api/v1/admin/act-reports/{$act->id}/export/ks3")
            ->assertForbidden();
    }

    private function allowPermissions(bool $allowed = true): void
    {
        $this->mock(AuthorizationService::class, function ($mock) use ($allowed): void {
            $mock->shouldReceive('can')->andReturn($allowed);
        });
    }

    private function allowPermissionsExcept(string $deniedPermission): void
    {
        $this->mock(AuthorizationService::class, function ($mock) use ($deniedPermission): void {
            $mock->shouldReceive('can')
                ->andReturnUsing(static fn (User $user, string $permission): bool => $permission !== $deniedPermission);
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

    private function approveEstimateSnapshot(Estimate $estimate, User $user): void
    {
        $items = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->orderBy('id')
            ->get()
            ->map(static function (EstimateItem $item): array {
                $contractLinks = ContractEstimateItem::query()
                    ->where('estimate_item_id', $item->id)
                    ->get()
                    ->map(static fn (ContractEstimateItem $link): array => [
                        'contract_id' => (int) $link->contract_id,
                        'quantity' => (string) ($link->quantity ?? 0),
                        'amount' => (string) ($link->amount ?? 0),
                    ])
                    ->all();

                return [
                    'id' => (int) $item->id,
                    'position_number' => $item->position_number,
                    'name' => $item->name,
                    'quantity' => (string) $item->quantity,
                    'quantity_total' => (string) ($item->quantity_total ?? $item->quantity),
                    'unit_price' => (string) $item->unit_price,
                    'total_amount' => (string) $item->total_amount,
                    'contract_links' => $contractLinks,
                    'children' => [],
                ];
            })
            ->all();
        $snapshot = [
            'schema_version' => 2,
            'rates' => ['vat_rate' => (string) ($estimate->vat_rate ?? 0)],
            'sections' => [],
            'unsectioned_items' => $items,
        ];
        $versionId = DB::table('estimate_versions')->insertGetId([
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'created_by_user_id' => $user->id,
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
            'version_number' => 1,
            'label' => 'Утверждённая версия',
            'snapshot_type' => 'approval',
            'estimate_status' => 'approved',
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'total_amount' => $estimate->total_amount,
            'total_amount_with_vat' => $estimate->total_amount_with_vat ?? $estimate->total_amount,
            'total_direct_costs' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $estimate->forceFill([
            'current_version_id' => $versionId,
        ])->saveQuietly();
    }
}
