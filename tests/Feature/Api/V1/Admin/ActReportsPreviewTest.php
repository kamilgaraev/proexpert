<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
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

    public function test_approve_repairs_zero_amount_act_from_estimate_contract_price(): void
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

        $response = $this->actingAs($user, 'api_admin')->postJson("/api/v1/admin/act-reports/{$act->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', \App\Models\ContractPerformanceAct::STATUS_APPROVED);
        $response->assertJsonPath('data.amount', 3000);
        $this->assertDatabaseHas('performance_act_lines', [
            'id' => 1,
            'unit_price' => 1000,
            'amount' => 3000,
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
        ];

        $ks2 = view('estimates.exports.ks2', $data)->render();
        $ks3 = view('estimates.exports.ks3', $data)->render();

        $this->assertStringContainsString('<html>', $ks2);
        $this->assertStringContainsString('<html>', $ks3);
    }

    public function test_recalculate_repairs_existing_act_amount_to_include_estimate_vat(): void
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
        ]);
        $act->completedWorks()->syncWithoutDetaching([
            $work->id => [
                'included_quantity' => 3,
                'included_amount' => 300,
            ],
        ]);

        $updatedAct = app(\App\Services\ActReport\ActReportWorkflowService::class)->recalculatePricedLines($act);

        $this->assertSame(360.0, (float) $updatedAct->amount);
        $this->assertDatabaseHas('performance_act_lines', [
            'performance_act_id' => $act->id,
            'unit_price' => 120,
            'amount' => 360,
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
