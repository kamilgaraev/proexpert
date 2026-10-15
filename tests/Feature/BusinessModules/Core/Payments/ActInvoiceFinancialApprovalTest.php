<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Events\PaymentApprovalRequested;
use App\BusinessModules\Core\Payments\Models\PaymentApprovalRule;
use App\BusinessModules\Core\Payments\Notifications\PaymentApprovalRequiredNotification;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentActionPresenter;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Module;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractParty;
use App\Models\ContractPerformanceAct;
use App\Models\Project;
use App\Models\User;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class ActInvoiceFinancialApprovalTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
    }

    public function test_api_creation_starts_approval_and_replay_reuses_the_entire_workflow(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $payee = Organization::factory()->verified()->create();
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => $payee->name,
            'source_organization_id' => $payee->id,
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'ACT-FINANCIAL-CONTRACT',
            'date' => now()->toDateString(),
            'subject' => 'Работы по акту',
            'total_amount' => '200000.00',
            'status' => 'active',
        ]);
        foreach ([['first', 'customer', $context->organization], ['second', 'contractor', $payee]] as [$side, $role, $organization]) {
            ContractParty::query()->create([
                'contract_id' => $contract->id,
                'side' => $side,
                'role' => $role,
                'linked_organization_id' => $organization->id,
                'name' => $organization->name,
                'snapshot' => [],
            ]);
        }
        $act = ContractPerformanceAct::withoutEvents(static fn () => ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'ACT-FINANCIAL-1',
            'act_date' => now()->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'amount' => '100000.00',
            'vat_rate' => '0.00',
            'vat_amount' => '0.00',
            'amount_without_vat' => '100000.00',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
        ]));
        $payload = [
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
            'idempotency_key' => 'financial-approval-api',
            'document_type' => 'invoice',
            'document_date' => now()->toDateString(),
            'amount' => '100000.00',
            'currency' => 'RUB',
            'due_date' => now()->addDays(7)->toDateString(),
            'payment_purpose' => 'Оплата работ по акту',
            'bank_account' => '40702810900000001002',
            'bank_bik' => '044525225',
        ];

        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', $payload)->assertCreated();
        $document = PaymentDocument::query()->findOrFail($response->json('data.id'));
        $this->assertSame(InvoiceDirection::OUTGOING, $document->direction);
        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $document->status);
        $approvals = $document->approvals()->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $approvals);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', $payload)->assertCreated();
        $this->assertSame($approvals, $document->approvals()->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, PaymentDocument::query()->where('invoiceable_type', ContractPerformanceAct::class)->where('invoiceable_id', $act->id)->count());
        $this->assertSame('0.00', $document->fresh()->paid_amount);
        $secondAct = $act->replicate();
        $secondAct->act_document_number = 'ACT-FINANCIAL-2';
        $secondAct->saveQuietly();
        $secondPayload = array_replace($payload, [
            'invoiceable_id' => $secondAct->id, 'idempotency_key' => 'financial-approval-api-second-act',
        ]);
        $secondResponse = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', $secondPayload)->assertCreated();
        $secondDocument = PaymentDocument::query()->findOrFail($secondResponse->json('data.id'));
        $this->assertNotSame($document->id, $secondDocument->id);
        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $secondDocument->status);
        $this->assertSame(2, $secondDocument->approvals()->count());
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', array_replace($payload, [
            'idempotency_key' => 'financial-approval-api-duplicate-act',
        ]))->assertStatus(422);
        $this->assertSame(2, PaymentDocument::query()->where('organization_id', $context->organization->id)
            ->where('invoiceable_type', ContractPerformanceAct::class)
            ->whereIn('invoiceable_id', [$act->id, $secondAct->id])->count());
    }

    public function test_submitted_act_invoice_starts_financial_approval_once_before_payment_planning(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        $submitUrl = "/api/v1/admin/payments/documents/{$document->id}/submit";

        $this->withHeaders($context->authHeaders())->postJson($submitUrl)->assertOk();

        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $document->fresh()->status);
        $approvals = $document->approvals()->orderBy('approval_level')->get();
        $this->assertSame([1, 2], $approvals->pluck('approval_level')->all());
        $this->assertSame(['pending', 'pending'], $approvals->pluck('status')->all());
        $this->withHeaders($context->authHeaders())->postJson($submitUrl)->assertOk();
        $this->assertSame($approvals->modelKeys(), $document->approvals()->orderBy('approval_level')->pluck('id')->all());

        try {
            app(PaymentDocumentService::class)->schedule($document->fresh(), null, $context->user);
            $this->fail('Payment planning accepted an invoice before its financial approval.');
        } catch (\DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $workflow = app(ApprovalWorkflowService::class);
        $workflow->approveByUser($document->fresh(), $context->user->id);
        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $document->fresh()->status);
        $workflow->approveByUser($document->fresh(), $context->user->id);
        $this->assertSame(PaymentDocumentStatus::APPROVED, $document->fresh()->status);
        $this->assertFalse($document->fresh()->canBePaid());
        $this->assertSame('schedule_payment', app(PaymentDocumentActionPresenter::class)->present($document->fresh(), $context->user)['primary_action']['key']);
        try {
            app(PaymentDocumentService::class)->registerPayment($document->fresh(), '1000.00', [
                'payment_method' => 'bank_transfer', 'transaction_date' => now()->toDateString(),
                'idempotency_key' => 'act-before-planning',
            ]);
            $this->fail('An approved act invoice was paid before planning.');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('payments.validation.act_invoice_payment_planning_required'), $exception->getMessage());
        }
        app(PaymentDocumentService::class)->schedule($document->fresh(), null, $context->user);
        $this->assertSame(PaymentDocumentStatus::SCHEDULED, $document->fresh()->status);
        $this->assertTrue($document->fresh()->canBePaid());
        $this->assertSame('register_payment', app(PaymentDocumentActionPresenter::class)->present($document->fresh(), $context->user)['primary_action']['key']);
        $this->assertSame('0.00', $document->fresh()->paid_amount);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_unpaid_legacy_approved_act_invoice_recovers_its_financial_route_before_planning(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['status' => PaymentDocumentStatus::APPROVED, 'approved_at' => now()])->save();
        $this->assertSame('submit_payment_document', app(PaymentDocumentActionPresenter::class)->present($document->fresh(), $context->user)['primary_action']['key']);
        try {
            app(PaymentDocumentService::class)->schedule($document->fresh(), null, $context->user);
            $this->fail('A legacy act invoice was planned without financial approval.');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('payments.validation.act_invoice_financial_approval_required'), $exception->getMessage());
        }
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/payments/documents/{$document->id}/submit")->assertOk();
        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $document->fresh()->status);
        $this->assertNull($document->fresh()->approved_at);
        $this->assertSame(2, $document->approvals()->count());
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_planning_checks_the_current_status_and_preserves_an_already_planned_legacy_invoice(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['status' => PaymentDocumentStatus::APPROVED])->save();
        $stale = $document->fresh();
        $document->forceFill(['status' => PaymentDocumentStatus::CANCELLED])->save();
        try {
            app(PaymentDocumentService::class)->schedule($stale, null, $context->user);
            $this->fail('A stale approved model overwrote cancellation during planning.');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('payments.validation.schedule_only_approved'), $exception->getMessage());
        }
        $this->assertSame(PaymentDocumentStatus::CANCELLED, $document->fresh()->status);
        $legacy = $this->createSubmittedActInvoice($context);
        $legacy->forceFill(['status' => PaymentDocumentStatus::SCHEDULED])->save();
        app(PaymentDocumentService::class)->schedule($legacy->fresh(), now()->addDays(2), $context->user);
        $this->assertSame(PaymentDocumentStatus::SCHEDULED, $legacy->fresh()->status);
        $this->assertSame(0, $legacy->approvals()->count());
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_legacy_act_invoice_requires_recovery_instead_of_administrative_approval(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);

        try {
            app(PaymentDocumentService::class)->approve($document, $context->user->id);
            $this->fail('A legacy invoice without a route was approved.');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('payments.validation.act_invoice_financial_approval_required'), $exception->getMessage());
        }
        $document->forceFill(['status' => PaymentDocumentStatus::PENDING_APPROVAL])->save();
        $queue = app(ApprovalWorkflowService::class)->getPendingApprovalsForUser($context->user->id, $context->organization->id);
        $this->assertCount(0, $queue);
        $this->assertSame(0, $document->approvals()->count());
        $action = app(PaymentDocumentActionPresenter::class)->present($document->fresh(), $context->user)['primary_action'];
        $this->assertSame('submit_payment_document', $action['key']);
        $this->assertSame('На финансовое согласование', $action['label']);
        $this->assertTrue($action['is_enabled']);
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/payments/documents/{$document->id}")
            ->assertOk()->assertJsonPath('data.can_be_approved_by_current_user', false);
    }

    public function test_bulk_approval_performs_one_financial_step_instead_of_bypassing_the_route(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        $service = app(PaymentDocumentService::class);
        $service->submit($document, $context->user);
        $service->approve($document->fresh(), $context->user->id);
        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $document->fresh()->status);
        $this->assertSame(1, $document->approvals()->where('status', 'approved')->count());
        $service->approve($document->fresh(), $context->user->id);
        $this->assertSame(PaymentDocumentStatus::APPROVED, $document->fresh()->status);
        $this->assertSame(2, $document->approvals()->where('status', 'approved')->count());
        $this->assertSame('0.00', $document->fresh()->paid_amount);
    }

    public function test_notifications_wait_for_commit_and_follow_the_current_financial_level_once(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        PaymentApprovalRule::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Согласование счёта по акту',
            'conditions' => [],
            'approval_chain' => [
                ['level' => 1, 'order' => 1, 'approver_user_id' => $context->user->id],
                ['level' => 2, 'order' => 1, 'approver_user_id' => $context->user->id],
            ],
            'is_active' => true,
        ]);
        $initialLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            app(PaymentDocumentService::class)->submit($document, $context->user);
            Notification::assertNothingSent();
            $this->assertSame(2, $document->approvals()->count());
            $this->assertSame(0, $document->approvals()->whereNotNull('notified_at')->count());
            DB::commit();
        } catch (\Throwable $exception) {
            while (DB::transactionLevel() > $initialLevel) {
                DB::rollBack();
            }
            throw $exception;
        }
        Notification::assertSentToTimes($context->user, PaymentApprovalRequiredNotification::class, 1);
        $this->assertNotNull($document->approvals()->where('approval_level', 1)->firstOrFail()->notified_at);
        $this->assertNull($document->approvals()->where('approval_level', 2)->firstOrFail()->notified_at);
        app(ApprovalWorkflowService::class)->approveByUser($document->fresh(), $context->user->id);
        Notification::assertSentToTimes($context->user, PaymentApprovalRequiredNotification::class, 2);
        $this->assertNotNull($document->approvals()->where('approval_level', 2)->firstOrFail()->notified_at);
        $ids = $document->approvals()->orderBy('id')->pluck('id')->all();
        app(ApprovalWorkflowService::class)->initiateApproval($document->fresh(), $context->user);
        $this->assertSame($ids, $document->approvals()->orderBy('id')->pluck('id')->all());
        Notification::assertSentToTimes($context->user, PaymentApprovalRequiredNotification::class, 2);
    }

    public function test_incoming_act_invoice_keeps_existing_approval_behavior(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['direction' => InvoiceDirection::INCOMING])->save();
        $this->assertFalse($document->requiresApproval());
        $approvals = app(ApprovalWorkflowService::class)->initiateApproval($document);
        $this->assertCount(0, $approvals);
        $this->assertSame(PaymentDocumentStatus::APPROVED, $document->fresh()->status);
    }

    public function test_foreign_approver_rule_rolls_back_the_financial_route_without_notifying_anyone(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        $foreign = User::factory()->create();
        $this->assertFalse($foreign->organizations()->whereKey($context->organization->id)->exists());
        PaymentApprovalRule::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Недопустимый утверждающий',
            'conditions' => [],
            'approval_chain' => [['level' => 1, 'order' => 1, 'approver_user_id' => $foreign->id]],
            'is_active' => true,
        ]);

        try {
            app(PaymentDocumentService::class)->submit($document, $context->user);
            $this->fail('An approver outside the document organization was accepted.');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('payments.validation.approver_organization_mismatch'), $exception->getMessage());
        }
        $this->assertSame(PaymentDocumentStatus::SUBMITTED, $document->fresh()->status);
        $this->assertSame(0, $document->approvals()->count());
        Notification::assertNothingSent();
    }

    public function test_historical_foreign_assignment_does_not_receive_the_document_notification(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['status' => PaymentDocumentStatus::PENDING_APPROVAL])->save();
        $foreign = User::factory()->create();
        $this->assertFalse($foreign->organizations()->whereKey($context->organization->id)->exists());
        foreach ([$context->user, $foreign] as $user) {
            PaymentApproval::query()->create([
                'payment_document_id' => $document->id,
                'organization_id' => $context->organization->id,
                'approver_user_id' => $user->id,
                'approval_level' => 1,
                'approval_order' => 1,
                'status' => 'pending',
            ]);
        }
        DB::transaction(static function () use ($document): void {
            event(new PaymentApprovalRequested($document, 1));
        });
        Notification::assertSentToTimes($context->user, PaymentApprovalRequiredNotification::class, 1);
        Notification::assertNotSentTo($foreign, PaymentApprovalRequiredNotification::class);
        $this->assertNull($document->approvals()->where('approver_user_id', $foreign->id)->firstOrFail()->notified_at);
    }

    private function createSubmittedActInvoice(AdminApiTestContext $context): PaymentDocument
    {
        $payee = Organization::factory()->verified()->create();

        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $payee->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'ACT-FINANCIAL-'.uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::ACT,
            'amount' => '100000.00',
            'paid_amount' => '0.00',
            'remaining_amount' => '100000.00',
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::SUBMITTED,
            'due_date' => now()->addDays(7)->toDateString(),
            'payment_purpose' => 'Оплата работ по утверждённому акту',
            'bank_account' => '40702810900000001002',
            'bank_bik' => '044525225',
            'created_by_user_id' => $context->user->id,
        ]);
    }

    private function activatePayments(AdminApiTestContext $context): void
    {
        $module = Module::query()->firstOrCreate(['slug' => 'payments'], [
            'name' => 'Payments',
            'version' => '1.0.0',
            'type' => 'core',
            'billing_model' => 'free',
            'category' => 'finance',
            'is_active' => true,
        ]);
        $module->forceFill(['permissions' => [
            'payments.invoice.view',
            'payments.invoice.issue',
            'payments.invoice.edit',
            'payments.transaction.approve',
            'payments.schedule.create',
        ]])->save();
        OrganizationModuleActivation::query()->updateOrCreate([
            'organization_id' => $context->organization->id,
            'module_id' => $module->id,
        ], ['status' => 'active', 'activated_at' => now(), 'expires_at' => null]);
    }
}
