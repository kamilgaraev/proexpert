<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\FinancialBalanceQuery;
use App\BusinessModules\Core\Payments\Services\PaymentDashboardQueryService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Contractor;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\ContractProjectAllocation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Enums\ProjectOrganizationRole;
use App\Enums\UserProjectAccessMode;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class DashboardControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_only_current_organization_documents_and_transactions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);

        $payer = $this->createContractor($context->organization->id, 'Dashboard payer');
        $payee = $this->createContractor($context->organization->id, 'Dashboard payee');
        $incoming = $this->createDocument($context, [
            'direction' => InvoiceDirection::INCOMING,
            'payer_contractor_id' => $payer->id,
            'contractor_id' => $payer->id,
            'amount' => 1000,
            'paid_amount' => 200,
            'remaining_amount' => 800,
        ]);
        $outgoing = $this->createDocument($context, [
            'direction' => InvoiceDirection::OUTGOING,
            'payee_contractor_id' => $payee->id,
            'contractor_id' => $payee->id,
            'amount' => 400,
            'paid_amount' => 100,
            'remaining_amount' => 300,
        ]);
        $paid = $this->createDocument($context, [
            'direction' => InvoiceDirection::OUTGOING,
            'status' => PaymentDocumentStatus::PAID,
            'amount' => 50,
            'paid_amount' => 50,
            'remaining_amount' => 0,
            'paid_at' => now(),
        ]);

        $foreignDocument = $this->createDocument($foreignContext, [
            'direction' => InvoiceDirection::INCOMING,
            'amount' => 9900,
            'paid_amount' => 0,
            'remaining_amount' => 9900,
        ]);

        $this->createTransaction($incoming, 200);
        $this->createTransaction($outgoing, 100);
        $this->createTransaction($paid, 50);
        $this->createTransaction($foreignDocument, 9900);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/dashboard?period=7');

        $response->assertOk();
        $response->assertJsonPath('data.summary.total_receivable', 800);
        $response->assertJsonPath('data.summary.total_payable', 300);
        $response->assertJsonPath('data.summary.net_position', 500);
        $response->assertJsonPath('data.summary.active_documents_count', 2);
        $response->assertJsonPath('data.summary.paid_documents_count', 1);
        $response->assertJsonPath('data.documents_by_status.approved.count', 2);
        $response->assertJsonPath('data.documents_by_status.paid.count', 1);
        $response->assertJsonPath('data.cash_flow.period_days', 7);
        $response->assertJsonPath('data.cash_flow.incoming', 200);
        $response->assertJsonPath('data.cash_flow.outgoing', 150);
        $response->assertJsonPath('data.cash_flow.net_cash_flow', 50);
    }

    public function test_dashboard_counts_linked_request_order_once_without_losing_registered_payments(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $payee = $this->createContractor($context->organization->id, 'Linked order payee');
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $payee->id,
            'number' => 'LINKED-LEDGER',
            'date' => now()->toDateString(),
            'total_amount' => 100000,
        ]);
        $identity = [
            'project_id' => $project->id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $payee->id,
            'contractor_id' => $payee->id,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
        ];
        $this->createDocument($context, [...$identity, 'amount' => 100000, 'remaining_amount' => 100000]);
        $request = $this->createDocument($context, [
            ...$identity,
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'source_type' => Contract::class,
            'source_id' => $contract->id,
        ]);
        $order = $this->createDocument($context, [
            ...$identity,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'source_type' => PaymentDocument::class,
            'source_id' => $request->id,
        ]);
        $endpoint = "/api/v1/admin/payments/dashboard?project_id={$project->id}";

        $this->withHeaders($context->authHeaders())->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.summary.total_payable', 101000);

        $this->createTransaction($request, 125);
        $this->createTransaction($order, 250);
        $this->createTransaction($order, -25, PaymentTransactionStatus::REFUNDED);
        $request->update([
            'status' => PaymentDocumentStatus::PARTIALLY_PAID,
            'paid_amount' => 125,
            'remaining_amount' => 875,
        ]);
        $order->update([
            'status' => PaymentDocumentStatus::PARTIALLY_PAID,
            'paid_amount' => 225,
            'remaining_amount' => 775,
        ]);

        $this->withHeaders($context->authHeaders())->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.summary.total_payable', 100650);

        $balance = app(FinancialBalanceQuery::class)->forContract($contract);
        $this->assertSame('101000.00', $balance->invoicedAmount);
        $this->assertSame('375.00', $balance->paidAmount);
        $this->assertSame('25.00', $balance->refundedAmount);
        $this->assertSame('100650.00', $balance->debtAmount);
    }

    public function test_balance_keeps_uncovered_request_amount_for_legacy_order_fragments_in_each_committed_status(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $query = app(FinancialBalanceQuery::class);
        foreach ([PaymentDocumentStatus::SUBMITTED, PaymentDocumentStatus::PENDING_APPROVAL,
            PaymentDocumentStatus::APPROVED, PaymentDocumentStatus::SCHEDULED,
            PaymentDocumentStatus::PARTIALLY_PAID, PaymentDocumentStatus::PAID] as $status) {
            $order = $this->createLinkedRequestOrder($context);
            $paid = match ($status) {
                PaymentDocumentStatus::PARTIALLY_PAID => 100,
                PaymentDocumentStatus::PAID => 400,
                default => 0,
            };
            $order->update(['status' => $status, 'amount' => 400, 'paid_amount' => $paid, 'remaining_amount' => 400 - $paid]);
            if ($paid > 0) {
                $this->createTransaction($order, $paid);
            }
            $balance = $query->forOrganization($context->organization->id, 'outgoing', $order->project_id);
            $this->assertSame('1000.00', $balance->invoicedAmount, $status->value);
            $this->assertSame(number_format(1000 - $paid, 2, '.', ''), $balance->debtAmount, $status->value);

            $second = $order->replicate()->fill([
                'document_number' => 'FRAGMENT-'.uniqid(), 'status' => PaymentDocumentStatus::APPROVED,
                'amount' => 300, 'paid_amount' => 0, 'remaining_amount' => 300,
            ]);
            $second->save();
            $balance = $query->forContracts($context->organization->id, [$order->invoiceable_id])[$order->invoiceable_id];
            $this->assertSame('1000.00', $balance->invoicedAmount, $status->value);
            $this->assertSame(number_format(1000 - $paid, 2, '.', ''), $balance->debtAmount, $status->value);

            $second->update(['amount' => 1000, 'remaining_amount' => 1000]);
            $balance = $query->forOrganization($context->organization->id, 'outgoing', $order->project_id);
            $this->assertSame('1400.00', $balance->invoicedAmount, $status->value);
            $this->assertSame(number_format(1400 - $paid, 2, '.', ''), $balance->debtAmount, $status->value);
        }
    }

    public function test_balance_does_not_cover_requests_with_inactive_or_conflicting_orders(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignOrganization = Organization::factory()->create();
        $query = app(FinancialBalanceQuery::class);
        foreach (['draft', 'cancelled', 'rejected', 'deleted', 'foreign_org', 'other_project', 'incoming',
            'currency', 'payer_org', 'payer_contractor', 'payee_org', 'payee_contractor', 'contractor',
            'counterparty_org', 'other_contract', 'unresolved_invoiceable', 'wrong_source_type', 'invoice_source'] as $case) {
            $order = $this->createLinkedRequestOrder($context);
            $projectId = $order->project_id;
            $contractId = $order->invoiceable_id;
            $expected = '2000.00';
            if (in_array($case, ['draft', 'cancelled', 'rejected'], true)) {
                $order->update(['status' => PaymentDocumentStatus::from($case)]);
                $expected = '1000.00';
            } elseif ($case === 'deleted') {
                $order->delete();
                $expected = '1000.00';
            } elseif ($case === 'foreign_org') {
                $order->update(['organization_id' => $foreignOrganization->id]);
                $expected = '1000.00';
            } elseif ($case === 'other_project') {
                $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
                $order->update(['project_id' => $otherProject->id]);
                $expected = '1000.00';
            } elseif ($case === 'incoming') {
                $order->update(['direction' => InvoiceDirection::INCOMING]);
                $expected = '1000.00';
            } elseif ($case === 'currency') {
                $order->update(['currency' => 'USD']);
            } elseif (in_array($case, ['payer_org', 'payee_org', 'counterparty_org'], true)) {
                $field = match ($case) {
                    'payer_org' => 'payer_organization_id',
                    'payee_org' => 'payee_organization_id',
                    default => 'counterparty_organization_id',
                };
                $order->update([$field => $foreignOrganization->id]);
            } elseif (in_array($case, ['payer_contractor', 'payee_contractor', 'contractor'], true)) {
                $otherContractor = $this->createContractor($context->organization->id, 'Other '.$case);
                $order->update([$case === 'contractor' ? 'contractor_id' : $case.'_id' => $otherContractor->id]);
            } elseif ($case === 'other_contract') {
                $otherContract = Contract::query()->findOrFail($contractId)->replicate()->fill(['number' => 'OTHER-'.uniqid()]);
                $otherContract->save();
                $order->update(['invoiceable_id' => $otherContract->id]);
            } elseif ($case === 'unresolved_invoiceable') {
                $order->update(['invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => null]);
            } elseif ($case === 'wrong_source_type') {
                $order->update(['source_type' => Contract::class]);
            } else {
                PaymentDocument::query()->findOrFail($order->source_id)->update(['document_type' => PaymentDocumentType::INVOICE]);
            }

            $balance = $query->forOrganization($context->organization->id, 'outgoing', $projectId);
            $this->assertSame($expected, $balance->invoicedAmount, $case);
            $this->assertSame($expected, $balance->debtAmount, $case);
            if ($case === 'other_contract') {
                $this->assertSame('1000.00', $query->forContracts($context->organization->id, [$contractId])[$contractId]->debtAmount);
            }
        }
    }

    public function test_balance_resolves_the_same_contract_from_request_act_and_order(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $order = $this->createLinkedRequestOrder($context);
        $act = ContractPerformanceAct::query()->create([
            'contract_id' => $order->invoiceable_id, 'project_id' => $order->project_id,
            'act_document_number' => 'REQUEST-ACT', 'act_date' => now()->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
            'amount' => 1000, 'status' => ContractPerformanceAct::STATUS_APPROVED, 'is_approved' => true,
        ]);
        PaymentDocument::query()->findOrFail($order->source_id)->update([
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id,
        ]);

        $query = app(FinancialBalanceQuery::class);
        $this->assertSame('1000.00', $query->forOrganization($context->organization->id, 'outgoing', $order->project_id)->debtAmount);
        $this->assertSame('1000.00', $query->forContracts($context->organization->id, [$order->invoiceable_id])[$order->invoiceable_id]->debtAmount);
        $this->assertSame('1000.00', $query->forAct($act)->debtAmount);

        PaymentDocument::query()->findOrFail($order->source_id)->update([
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $act->contract_id,
        ]);
        $order->update(['invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id]);
        $this->assertSame('1000.00', $query->forOrganization($context->organization->id, 'outgoing', $order->project_id)->debtAmount);
        $this->assertSame('1000.00', $query->forContracts($context->organization->id, [$act->contract_id])[$act->contract_id]->debtAmount);
    }

    public function test_dashboard_scopes_every_aggregate_to_the_requested_project(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $payer = $this->createContractor($context->organization->id, 'Project payer');
        $payee = $this->createContractor($context->organization->id, 'Project payee');
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'contractor_id' => $payee->id, 'number' => 'SCOPE-1', 'date' => now()->toDateString(), 'total_amount' => 2000,
        ]);
        Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $otherProject->id,
            'contractor_id' => $payee->id, 'number' => 'SCOPE-2', 'date' => now()->toDateString(), 'total_amount' => 90000,
        ]);
        $incoming = $this->createDocument($context, [
            'project_id' => $project->id, 'direction' => InvoiceDirection::INCOMING,
            'payer_contractor_id' => $payer->id, 'contractor_id' => $payer->id,
            'amount' => 1000, 'paid_amount' => 200, 'remaining_amount' => 800, 'issued_at' => now(),
        ]);
        $outgoing = $this->createDocument($context, [
            'project_id' => $project->id, 'payee_contractor_id' => $payee->id, 'contractor_id' => $payee->id,
            'amount' => 400, 'paid_amount' => 100, 'remaining_amount' => 300,
            'due_date' => now()->subDay()->toDateString(), 'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
        ]);
        $other = $this->createDocument($context, [
            'project_id' => $otherProject->id, 'amount' => 9000, 'paid_amount' => 500, 'remaining_amount' => 8500,
            'due_date' => now()->subDays(2)->toDateString(), 'issued_at' => now(), 'paid_at' => now(),
        ]);
        $unassigned = $this->createDocument($context, ['amount' => 7000]);
        $this->createTransaction($incoming, 200);
        $this->createTransaction($outgoing, 100);
        $this->createTransaction($other, 500);
        $this->createTransaction($unassigned, 700);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/dashboard?period=7&project_id={$project->id}");

        $response->assertOk()
            ->assertJsonPath('data.summary.total_receivable', 800)
            ->assertJsonPath('data.summary.total_payable', 300)
            ->assertJsonPath('data.summary.overdue_amount', 300)
            ->assertJsonPath('data.summary.upcoming_payments_7days', 800)
            ->assertJsonPath('data.summary.issued_this_month', 1000)
            ->assertJsonPath('data.summary.paid_this_month', 0)
            ->assertJsonPath('data.summary.active_documents_count', 2)
            ->assertJsonPath('data.documents_by_status.approved.count', 2)
            ->assertJsonPath('data.documents_by_type.invoice.count', 2)
            ->assertJsonCount(1, 'data.overdue_documents')
            ->assertJsonPath('data.overdue_documents.0.id', $outgoing->id)
            ->assertJsonCount(1, 'data.upcoming_documents')
            ->assertJsonPath('data.upcoming_documents.0.id', $incoming->id)
            ->assertJsonPath('data.cash_flow.incoming', 200)
            ->assertJsonPath('data.cash_flow.outgoing', 100)
            ->assertJsonPath('data.top_debtors.0.debt', 800)
            ->assertJsonPath('data.top_creditors.0.payable', 300)
            ->assertJsonCount(1, 'data.by_projects')
            ->assertJsonPath('data.by_projects.0.project_id', $project->id)
            ->assertJsonPath('data.payment_trends.0.incoming', 200)
            ->assertJsonPath('data.payment_trends.0.outgoing', 100)
            ->assertJsonPath('data.contract_comparison.contracts_total', 2000)
            ->assertJsonPath('data.contract_comparison.invoiced_amount', 400);
    }

    public function test_project_filters_reject_foreign_projects_for_all_new_scoped_endpoints(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        foreach (['dashboard', 'approvals/my', 'schedules/upcoming', 'schedules/overdue'] as $endpoint) {
            $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/payments/{$endpoint}?project_id={$foreignProject->id}")
                ->assertStatus(422);
        }
    }

    public function test_dashboard_uses_allocated_project_amounts_for_shared_contracts(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization->id, 'Allocation contractor');
        foreach (['fixed', 'percentage', 'custom', 'auto'] as $type) {
            $contract = Contract::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $otherProject->id,
                'contractor_id' => $contractor->id, 'number' => 'SCOPE-' . $type, 'date' => now()->toDateString(),
                'total_amount' => 1000, 'is_multi_project' => true,
            ]);
            $contract->projects()->sync([$project->id, $otherProject->id]);
            if ($type !== 'auto') {
                ContractProjectAllocation::query()->create([
                    'contract_id' => $contract->id, 'project_id' => $project->id, 'allocation_type' => $type,
                    'allocated_amount' => $type === 'fixed' ? 200 : null,
                    'allocated_percentage' => $type === 'percentage' ? 25 : null,
                    'custom_formula' => $type === 'custom' ? ['type' => 'coefficient', 'coefficient' => 0.1] : null,
                    'is_active' => true,
                ]);
            }
        }

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/dashboard?project_id={$project->id}")
            ->assertOk()->assertJsonPath('data.contract_comparison.contracts_total', 1050);
    }

    public function test_shared_project_filters_keep_organization_and_recipient_boundaries(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $project = $this->sharedProject($context, $foreignContext->organization->id);
        $own = $this->createDocument($context, [
            'project_id' => $project->id, 'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'payer_organization_id' => $context->organization->id, 'payee_organization_id' => $context->organization->id,
        ]);
        $ownTransaction = $this->createTransaction($own, 100);
        $foreign = $this->createDocument($foreignContext, ['project_id' => $project->id]);
        $this->createTransaction($foreign, 9000);
        $incoming = $this->createDocument($foreignContext, [
            'project_id' => $project->id, 'payee_organization_id' => $context->organization->id,
        ]);

        foreach (['requests/incoming', 'requests/outgoing'] as $endpoint) {
            $response = $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/payments/{$endpoint}?project_id={$project->id}")->assertOk();
            $this->assertSame([$own->id], collect($response->json('data'))->pluck('id')->all());
        }
        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/incoming/documents?project_id={$project->id}")->assertOk();
        $this->assertEqualsCanonicalizing([$own->id, $incoming->id], collect($response->json('data'))->pluck('id')->all());
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/transactions?project_id={$project->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownTransaction->id);
    }

    public function test_request_creation_accepts_active_shared_project_and_rejects_revoked_access(): void
    {
        Notification::fake();
        Queue::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $project = $this->sharedProject($context, $foreignContext->organization->id);
        $contractor = $this->createContractor($context->organization->id, 'Scope request contractor');
        $payload = ['project_id' => $project->id, 'contractor_id' => $contractor->id, 'amount' => 250, 'payment_purpose' => 'Scope request'];
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/requests', $payload)->assertCreated();
        $this->assertDatabaseHas('payment_documents', [
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'payment_purpose' => 'Scope request',
        ]);
        $project->organizations()->updateExistingPivot($context->organization->id, ['is_active' => false]);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/requests', $payload)->assertStatus(422);
        $this->assertSame(1, PaymentDocument::query()->where('organization_id', $context->organization->id)->count());
        foreach (['requests/incoming', 'requests/outgoing', 'incoming/documents'] as $endpoint) {
            $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/payments/{$endpoint}?project_id={$project->id}")->assertStatus(422);
        }
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/payments/transactions?project_id={$project->id}")->assertNotFound();
    }

    public function test_archived_project_history_remains_readable_but_new_requests_are_rejected(): void
    {
        Notification::fake();
        Queue::fake();
        foreach ([false, true] as $shared) {
            $context = AdminApiTestContext::create(roleSlug: 'web_admin');
            $this->activatePaymentsModule($context->organization->id);
            $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value]);
            $project = $shared
                ? $this->sharedProject($context, AdminApiTestContext::create(roleSlug: 'web_admin')->organization->id)
                : Project::factory()->create(['organization_id' => $context->organization->id]);
            $project->forceFill(['is_archived' => true])->save();
            $document = $this->createDocument($context, [
                'project_id' => $project->id, 'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
                'payer_organization_id' => $context->organization->id, 'payee_organization_id' => $context->organization->id,
            ]);
            $transaction = $this->createTransaction($document, 100);
            foreach (['dashboard', 'documents', 'approvals/my', 'schedules/upcoming', 'schedules/overdue', 'requests/incoming', 'requests/outgoing', 'incoming/documents'] as $endpoint) {
                $this->withHeaders($context->authHeaders())
                    ->getJson("/api/v1/admin/payments/{$endpoint}?project_id={$project->id}")->assertOk();
            }
            $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/payments/transactions?project_id={$project->id}")
                ->assertOk()->assertJsonPath('data.0.id', $transaction->id);
            $contractor = $this->createContractor($context->organization->id, 'Archived contractor');
            $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/requests', [
                'project_id' => $project->id, 'contractor_id' => $contractor->id, 'amount' => 250, 'payment_purpose' => 'Archived request',
            ])->assertStatus(422);
            $this->assertSame(1, PaymentDocument::query()->where('organization_id', $context->organization->id)->count());
        }
    }

    public function test_dashboard_service_rejects_foreign_inactive_organization_membership_and_actor_before_financial_reads(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->assertDashboardServiceDenied($context->user, $foreignContext->organization->id);
        $this->assertDashboardServiceDenied($context->user, 0);

        $context->organization->users()->updateExistingPivot($context->user->id, ['is_active' => false]);
        $this->assertDashboardServiceDenied($context->user, $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['is_active' => true]);
        $context->organization->forceFill(['is_active' => false])->save();
        $this->assertDashboardServiceDenied($context->user, $context->organization->id);
        $context->organization->forceFill(['is_active' => true])->save();
        $context->user->forceFill(['is_active' => false])->save();
        $this->assertDashboardServiceDenied($context->user, $context->organization->id);
    }

    public function test_dashboard_service_requires_the_explicit_actors_dashboard_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $otherActor = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['is_owner' => false]);
        $context->user->roleAssignments()->delete();
        $role = OrganizationCustomRole::createRole(
            organizationId: $context->organization->id,
            name: 'Dashboard denied test role',
            modulePermissions: ['payments' => ['payments.invoice.view']],
            interfaceAccess: ['admin'],
            createdBy: $context->user,
        );
        UserRoleAssignment::assignRole(
            user: $context->user, roleSlug: $role->slug,
            context: AuthorizationContext::getOrganizationContext($context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );
        Cache::driver('array')->flush();
        Cache::flush();
        Auth::setUser($otherActor->user);

        $this->assertDashboardServiceDenied($context->user, $context->organization->id);
    }

    public function test_dashboard_service_checks_project_access_and_preserves_shared_archived_history(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'is_archived' => true]);
        $this->createDocument($context, ['project_id' => $project->id]);
        $this->assertDashboardServiceDenied($context->user, $context->organization->id, $project->id);
        $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true, 'assigned_at' => now()]);
        $service = app(PaymentDashboardQueryService::class);
        $this->assertSame(1, $service->getDashboard($context->user, $context->organization->id, 30, $project->id)['summary']['total_documents_count']);

        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->assertDashboardServiceDenied($context->user, $context->organization->id, $foreignProject->id);
        $shared = $this->sharedProject($context, $foreignContext->organization->id);
        $shared->forceFill(['is_archived' => true])->save();
        $this->createDocument($context, ['project_id' => $shared->id]);
        $this->assertSame(1, $service->getDashboard($context->user, $context->organization->id, 30, $shared->id)['summary']['total_documents_count']);
        $shared->organizations()->updateExistingPivot($context->organization->id, ['is_active' => false]);
        $this->assertDashboardServiceDenied($context->user, $context->organization->id, $shared->id);
    }

    public function test_project_dashboard_permission_does_not_grant_other_projects_or_organization_dashboard(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, [
            'is_owner' => false, 'project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value,
        ]);
        $context->user->roleAssignments()->delete();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $interfaceRole = OrganizationCustomRole::createRole(
            organizationId: $context->organization->id,
            name: 'Dashboard interface test role',
            modulePermissions: [],
            interfaceAccess: ['admin'],
            createdBy: $context->user,
        );
        UserRoleAssignment::assignRole(
            user: $context->user, roleSlug: $interfaceRole->slug,
            context: AuthorizationContext::getOrganizationContext($context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );
        $role = OrganizationCustomRole::createRole(
            organizationId: $context->organization->id,
            name: 'Project dashboard test role',
            modulePermissions: ['payments' => ['payments.dashboard.view']],
            interfaceAccess: ['admin'],
            createdBy: $context->user,
        );
        UserRoleAssignment::assignRole(
            user: $context->user, roleSlug: $role->slug,
            context: AuthorizationContext::getProjectContext($project->id, $context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );
        Cache::driver('array')->flush();
        Cache::flush();
        $this->createDocument($context, ['project_id' => $project->id]);
        $this->assertDashboardServiceDenied($context->user, $context->organization->id);
        $this->assertDashboardServiceDenied($context->user, $context->organization->id, $otherProject->id);
        $result = app(PaymentDashboardQueryService::class)->getDashboard($context->user, $context->organization->id, 30, $project->id);
        $this->assertSame(1, $result['summary']['total_documents_count']);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/payments/dashboard')->assertForbidden();
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/dashboard?project_id={$otherProject->id}")->assertForbidden();
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/dashboard?project_id={$project->id}")
            ->assertOk()->assertJsonPath('data.summary.total_documents_count', 1);

        $shared = $this->sharedProject($context, AdminApiTestContext::create(roleSlug: 'web_admin')->organization->id);
        UserRoleAssignment::assignRole(
            user: $context->user, roleSlug: $role->slug,
            context: AuthorizationContext::getProjectContext($shared->id, $context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );
        Cache::driver('array')->flush();
        Cache::flush();
        $this->createDocument($context, ['project_id' => $shared->id]);
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/dashboard?project_id={$shared->id}")
            ->assertOk()->assertJsonPath('data.summary.total_documents_count', 1);
        $shared->forceFill(['is_archived' => true])->save();
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/dashboard?project_id={$shared->id}")
            ->assertOk()->assertJsonPath('data.summary.total_documents_count', 1);
        $shared->organizations()->updateExistingPivot($context->organization->id, ['is_active' => false]);
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/payments/dashboard?project_id={$shared->id}")->assertStatus(422);
    }

    private function assertDashboardServiceDenied(User $actor, int $organizationId, ?int $projectId = null): void
    {
        $denied = false;
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(PaymentDashboardQueryService::class)->getDashboard($actor, $organizationId, 30, $projectId);
        } catch (AuthorizationException) {
            $denied = true;
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertTrue($denied);
        $this->assertFalse(collect($queries)->contains(fn (array $query): bool => preg_match('/\b(payment_documents|payment_transactions)\b/i', $query['query']) === 1));
    }

    private function sharedProject(AdminApiTestContext $context, int $ownerOrganizationId): Project
    {
        $project = Project::factory()->create(['organization_id' => $ownerOrganizationId]);
        $project->organizations()->attach($context->organization->id, [
            'role' => ProjectOrganizationRole::CONTRACTOR->value, 'role_new' => ProjectOrganizationRole::CONTRACTOR->value,
            'is_active' => true, 'invited_at' => now(), 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value]);

        return $project;
    }

    private function createLinkedRequestOrder(AdminApiTestContext $context): PaymentDocument
    {
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $payee = $this->createContractor($context->organization->id, 'Ledger payee');
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'contractor_id' => $payee->id, 'number' => 'LEDGER-'.uniqid(),
            'date' => now()->toDateString(), 'total_amount' => 1000,
        ]);
        $identity = [
            'project_id' => $project->id, 'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $payee->id, 'contractor_id' => $payee->id,
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
        ];
        $request = $this->createDocument($context, [
            ...$identity, 'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'source_type' => Contract::class, 'source_id' => $contract->id,
        ]);

        return $this->createDocument($context, [
            ...$identity, 'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'source_type' => PaymentDocument::class, 'source_id' => $request->id,
        ]);
    }

    private function createDocument(AdminApiTestContext $context, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'DASH-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => now()->addDays(3)->toDateString(),
        ], $overrides));
    }

    private function createTransaction(
        PaymentDocument $document,
        float $amount,
        PaymentTransactionStatus $status = PaymentTransactionStatus::COMPLETED,
    ): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'amount' => $amount,
            'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'transaction_date' => now()->toDateString(),
            'status' => $status,
        ]);
    }

    private function createContractor(int $organizationId, string $name): Contractor
    {
        return Contractor::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'inn' => (string) random_int(1000000000, 9999999999),
        ]);
    }

    private function activatePaymentsModule(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'payments'],
            [
                'name' => 'Payments',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => ['payments.dashboard.view'],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
