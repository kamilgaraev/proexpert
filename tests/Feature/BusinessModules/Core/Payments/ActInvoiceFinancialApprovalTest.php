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
use App\BusinessModules\Core\Payments\Events\PaymentDocumentApproved;
use App\BusinessModules\Core\Payments\Models\PaymentApprovalRule;
use App\BusinessModules\Core\Payments\Notifications\PaymentApprovalRequiredNotification;
use App\BusinessModules\Core\Payments\Notifications\PaymentApprovedNotification;
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
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ActInvoiceFinancialApprovalTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
    }

    public static function creationPermissions(): array
    {
        return ['creator can issue' => [true], 'creator cannot issue' => [false]];
    }

    #[DataProvider('creationPermissions')]
    public function test_api_creation_starts_approval_and_replay_reuses_the_entire_workflow(bool $canIssue): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        if (! $canIssue) {
            $this->assignPaymentPermissions($context, ['payments.invoice.create']);
        }
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
        if (! $canIssue) {
            $this->assertSame(PaymentDocumentStatus::SUBMITTED, $document->status);
            $this->assertSame(0, $document->approvals()->count());
            $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents', $payload)
                ->assertCreated()->assertJsonPath('data.id', $document->id);
            $this->assertSame(0, $document->approvals()->count());
            $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/payments/documents/'.$document->id.'/submit')->assertForbidden();
            Notification::assertNotSentTo($context->user, PaymentApprovalRequiredNotification::class);

            return;
        }
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
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/payments/approvals/documents/{$document->id}/status")
            ->assertOk()->assertJsonPath('data.is_fully_approved', false);
        $this->assertSame(0, $document->approvals()->count());
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

    public function test_historical_retrospective_record_does_not_replace_the_financial_route(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['status' => PaymentDocumentStatus::APPROVED, 'approved_at' => now()])->save();
        $history = PaymentApproval::query()->create([
            'payment_document_id' => $document->id, 'organization_id' => $context->organization->id,
            'approval_permission' => 'payments.transaction.approve', 'approver_user_id' => $context->user->id,
            'approval_level' => 1, 'approval_order' => 1, 'status' => 'approved',
            'decision_comment' => PaymentApproval::RETROSPECTIVE_DECISION_COMMENT, 'decided_at' => now(),
        ]);
        $headers = $context->authHeaders();
        $this->withHeaders($headers)->getJson("/api/v1/admin/payments/approvals/documents/{$document->id}/status")
            ->assertOk()->assertJsonPath('data.is_fully_approved', false)->assertJsonPath('data.total', 0);
        $this->assertFalse($document->fresh()->hasCompletedFinancialApproval());
        $this->assertTrue($document->fresh()->canRecoverActInvoiceFinancialApproval());
        $this->withHeaders($headers)->postJson("/api/v1/admin/payments/documents/{$document->id}/schedule", [
            'scheduled_at' => now()->addDays(5)->toDateString(),
        ])->assertUnprocessable();
        $this->withHeaders($headers)->postJson("/api/v1/admin/payments/documents/{$document->id}/submit")->assertOk();
        $this->assertSame(3, $document->approvals()->count());
        $this->assertSame(2, $document->approvals()->where('status', 'pending')->count());
        $this->assertModelExists($history);
        $workflow = app(ApprovalWorkflowService::class);
        $workflow->approveByUser($document->fresh(), $context->user->id);
        $this->assertFalse($document->fresh()->hasCompletedFinancialApproval());
        $workflow->approveByUser($document->fresh(), $context->user->id);
        $this->assertTrue($document->fresh()->hasCompletedFinancialApproval());
        app(PaymentDocumentService::class)->schedule($document->fresh(), null, $context->user);
        $this->assertSame(PaymentDocumentStatus::SCHEDULED, $document->fresh()->status);
        $this->assertSame('0.00', $document->fresh()->paid_amount);
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
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/payments/approvals/documents/{$document->id}/status")
            ->assertOk()->assertJsonPath('data.total', 0)->assertJsonPath('data.is_fully_approved', false);
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
        $this->activatePayments($context);
        $this->assertTrue($context->user->can('payments.transaction.approve', ['organization_id' => $context->organization->id]));
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

    public function test_mobile_financial_approval_can_proceed_to_planning_without_a_payment(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        $url = '/api/v1/mobile/payments/documents/'.$document->id;
        $headers = $context->mobileAuthHeaders();
        $listUrl = '/api/v1/mobile/payments/documents?project_id='.$document->project_id;
        $this->withHeaders($headers)->getJson($listUrl)->assertOk()
            ->assertJsonPath('data.items.0.action_summary.primary_action.href', $url.'/submit');
        $this->withHeaders($headers)->postJson($url.'/schedule', ['scheduled_at' => now()->addDays(5)->toDateString()])->assertStatus(422);
        $this->withHeaders($headers)->postJson($url.'/submit')->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->withHeaders($headers)->getJson($listUrl)->assertOk()
            ->assertJsonPath('data.items.0.action_summary.primary_action.href', $url.'/approve');
        $this->withHeaders($headers)->postJson($url.'/approve')->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $approved = $this->withHeaders($headers)->postJson($url.'/approve')->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.can_schedule_payment', true)
            ->assertJsonPath('data.action_summary.primary_action.href', $url.'/schedule');
        $this->withHeaders($headers)->getJson($listUrl)->assertOk()
            ->assertJsonPath('data.items.0.action_summary.primary_action.href', $url.'/schedule');
        $this->withHeaders($headers)->postJson($url.'/schedule', ['scheduled_at' => 'not-a-date'])->assertStatus(422);
        $date = now()->addDays(5)->toDateString();
        $this->withHeaders($headers)->postJson($approved->json('data.action_summary.primary_action.href'), ['scheduled_at' => $date])
            ->assertOk()->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.action_summary.primary_action.href', $url.'/payments');
        $this->withHeaders($headers)->getJson($listUrl)->assertOk()
            ->assertJsonPath('data.items.0.action_summary.primary_action.href', $url.'/payments');
        $this->assertSame($date, $document->fresh()->scheduled_at->toDateString());
        $this->assertSame('0.00', $document->fresh()->paid_amount);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_mobile_planning_does_not_disclose_foreign_documents_or_allow_a_role_without_planning_permission(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $foreign = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignDocument = $this->createSubmittedActInvoice($foreign);
        $this->withHeaders($context->mobileAuthHeaders())->postJson('/api/v1/mobile/payments/documents/'.$foreignDocument->id.'/schedule')->assertNotFound();
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['status' => PaymentDocumentStatus::SCHEDULED])->save();
        $this->assignPaymentPermissions($context, ['payments.invoice.view']);
        $this->withHeaders($context->mobileAuthHeaders())->postJson('/api/v1/mobile/payments/documents/'.$document->id.'/schedule')->assertNotFound();
        $this->assertSame(PaymentDocumentStatus::SCHEDULED, $document->fresh()->status);
        $this->assertNull($document->fresh()->scheduled_at);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_final_financial_approval_notification_waits_for_commit_and_describes_planning(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        app(PaymentDocumentService::class)->submit($document, $context->user);
        $workflow = app(ApprovalWorkflowService::class);
        $workflow->approveByUser($document->fresh(), $context->user->id);
        $initialLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $workflow->approveByUser($document->fresh(), $context->user->id);
            Notification::assertNotSentTo($context->user, PaymentApprovedNotification::class);
            DB::rollBack();
        } finally {
            while (DB::transactionLevel() > $initialLevel) {
                DB::rollBack();
            }
        }
        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $document->fresh()->status);
        Notification::assertNotSentTo($context->user, PaymentApprovedNotification::class);
        $workflow->approveByUser($document->fresh(), $context->user->id);
        Notification::assertSentTo($context->user, PaymentApprovedNotification::class, static function (PaymentApprovedNotification $notification): bool {
            return in_array('Финансовое согласование завершено. Запланируйте дату оплаты документа.', $notification->toMail(null)->outroLines, true);
        });
        $this->assertSame('0.00', $document->fresh()->paid_amount);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public static function adminNotificationUrls(): array
    {
        return [
            'canonical admin' => ['https://admin.1мост.рф'],
            'configured admin with trailing slash' => ['https://admin.example.test/'],
        ];
    }

    #[DataProvider('adminNotificationUrls')]
    public function test_payment_notifications_link_to_admin_independently_of_the_backend_or_customer_host(string $adminUrl): void
    {
        config([
            'app.url' => 'http://localhost',
            'app.frontend_url' => 'https://lk.example.test',
            'app.admin_frontend_url' => $adminUrl,
        ]);
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $document = $this->createSubmittedActInvoice($context);
        $expected = rtrim($adminUrl, '/').'/payments?document_id='.$document->id;
        foreach ([new PaymentApprovalRequiredNotification($document), new PaymentApprovedNotification($document)] as $notification) {
            $this->assertSame($expected, $notification->toMail($context->user)->actionUrl);
            $this->assertSame($expected, $notification->toArray($context->user)['url']);
        }
    }

    public static function futureApprovalDecisions(): array
    {
        return ['approve' => ['approve'], 'reject' => ['reject'],
            'approve legacy role' => ['approve', true], 'reject legacy role' => ['reject', true]];
    }

    #[DataProvider('futureApprovalDecisions')]
    public function test_a_future_assignee_waits_for_the_current_financial_level(string $decision, bool $legacyRole = false): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $document = $this->createSubmittedActInvoice($context);
        $second = $this->createOrganizationApprover($context);
        PaymentApprovalRule::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'Последовательное финансовое согласование',
            'conditions' => [], 'is_active' => true, 'approval_chain' => [
                ['level' => 1, 'order' => 1, 'approver_user_id' => $context->user->id],
                ['level' => 2, 'order' => 1, 'approver_user_id' => $second->id],
            ],
        ]);
        $workflow = app(ApprovalWorkflowService::class);
        app(PaymentDocumentService::class)->submit($document, $context->user);
        $url = '/api/v1/mobile/payments/documents/'.$document->id;
        $secondContext = new AdminApiTestContext($context->organization, $second, '');
        if ($legacyRole) $document->approvals()->update(['approval_role' => 'web_admin', 'approval_permission' => null]);
        $this->assertFalse(app(\App\BusinessModules\Core\Payments\Services\PaymentDocumentPresenter::class)
            ->detailed($document->fresh(), $second)['can_be_approved_by_current_user']);
        $headers = $secondContext->mobileAuthHeaders();
        $this->withHeaders($headers)->getJson($url)->assertOk()
            ->assertJsonPath('data.can_approve', false)->assertJsonPath('data.can_reject', false)
            ->assertJsonPath('data.action_summary.primary_action.is_enabled', false);
        $this->assertFalse($workflow->getApprovalStatus($document->fresh(), $second->id)['can_be_approved_by_current_user']);
        $this->assertCount(0, $workflow->getPendingApprovalsForUser($second->id, $context->organization->id));
        try {
            if ($decision === 'approve') {
                $workflow->approveByUser($document->fresh(), $second->id);
            } else {
                $workflow->rejectByUser($document->fresh(), $second->id, 'Проверка порядка этапов');
            }
            $this->fail('A future approver decided before the current financial level.');
        } catch (\DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->withHeaders($headers)->postJson($url.'/'.$decision, ['reason' => 'Проверка порядка этапов'])->assertForbidden();
        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $document->fresh()->status);
        $this->assertSame(2, $document->approvals()->where('status', 'pending')->count());
        $workflow->approveByUser($document->fresh(), $context->user->id);
        $this->assertTrue(app(\App\BusinessModules\Core\Payments\Services\PaymentDocumentPresenter::class)
            ->detailed($document->fresh(), $second)['can_be_approved_by_current_user']);
        $this->withHeaders($headers)->getJson($url)->assertOk()->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.can_reject', true)->assertJsonPath('data.action_summary.primary_action.href', $url.'/approve');
        $this->assertCount(1, $workflow->getPendingApprovalsForUser($second->id, $context->organization->id));
        $this->withHeaders($headers)->postJson($url.'/'.$decision, ['reason' => 'Проверка порядка этапов'])->assertOk()
            ->assertJsonPath('data.status', $decision === 'approve' ? 'approved' : 'rejected');
        $this->assertSame('0.00', $document->fresh()->paid_amount);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_approval_notifications_respect_project_scope_and_allow_recipients_with_another_selected_organization(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['project_id' => $project->id])->save();
        $denied = $this->createOrganizationApprover($context, 'assigned_projects');
        $allowed = $this->createOrganizationApprover($context);
        $otherOrganization = Organization::factory()->verified()->create();
        $otherOrganization->users()->attach($allowed->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $allowed->forceFill(['current_organization_id' => $otherOrganization->id])->save();
        $projectOnly = $this->createOrganizationApprover($context);
        $this->assignPaymentPermissions(new AdminApiTestContext($context->organization, $projectOnly, ''),
            ['payments.transaction.approve', 'payments.transaction.register'], $project->id);
        app(PaymentDocumentService::class)->submit($document, $context->user);
        Notification::assertNotSentTo($denied, PaymentApprovalRequiredNotification::class);
        Notification::assertSentToTimes($allowed, PaymentApprovalRequiredNotification::class, 1);
        Notification::assertSentToTimes($projectOnly, PaymentApprovalRequiredNotification::class, 1);
        PaymentApproval::query()->create([
            'payment_document_id' => $document->id, 'organization_id' => $context->organization->id,
            'approver_user_id' => $denied->id, 'approval_level' => 1, 'approval_order' => 2, 'status' => 'pending',
        ]);
        event(new PaymentApprovalRequested($document->fresh(), 1));
        Notification::assertNotSentTo($denied, PaymentApprovalRequiredNotification::class);
        $this->assertNull($document->approvals()->where('approver_user_id', $denied->id)->firstOrFail()->notified_at);
        $document->forceFill(['status' => PaymentDocumentStatus::APPROVED])->save();
        event(new PaymentDocumentApproved($document->fresh(), $context->user->id));
        Notification::assertNotSentTo($denied, PaymentApprovedNotification::class);
        Notification::assertSentToTimes($context->user, PaymentApprovedNotification::class, 1);
        Notification::assertSentToTimes($allowed, PaymentApprovedNotification::class, 1);
        Notification::assertSentToTimes($projectOnly, PaymentApprovedNotification::class, 1);
    }

    public function test_reminders_recheck_scope_permissions_and_only_notify_the_current_level(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['project_id' => $project->id, 'status' => PaymentDocumentStatus::PENDING_APPROVAL,
            'submitted_at' => now()->subDays(2)])->save();
        $allowed = $this->createOrganizationApprover($context);
        $denied = $this->createOrganizationApprover($context, 'assigned_projects');
        $noPermission = $this->createOrganizationApprover($context);
        $this->assignPaymentPermissions(new AdminApiTestContext($context->organization, $noPermission, ''), ['payments.invoice.view']);
        $inactive = $this->createOrganizationApprover($context);
        $context->organization->users()->updateExistingPivot($inactive->id, ['is_active' => false]);
        $future = $this->createOrganizationApprover($context);
        $foreign = User::factory()->create();
        $rows = [];
        foreach ([$allowed, $denied, $noPermission, $inactive, $foreign, $future] as $user) {
            $rows[$user->id] = PaymentApproval::query()->create([
                'payment_document_id' => $document->id, 'organization_id' => $context->organization->id,
                'approver_user_id' => $user->id, 'approval_level' => $user->is($future) ? 2 : 1,
                'approval_order' => 1, 'status' => 'pending',
            ]);
        }
        app()->call([new \App\BusinessModules\Core\Payments\Jobs\SendPaymentRemindersJob, 'handle']);
        Notification::assertSentToTimes($allowed, PaymentApprovalRequiredNotification::class, 1);
        foreach ([$denied, $noPermission, $inactive, $foreign, $future] as $user) {
            Notification::assertNotSentTo($user, PaymentApprovalRequiredNotification::class);
            $this->assertNull($rows[$user->id]->fresh()->last_reminder_at);
        }
        $this->assertSame(1, $rows[$allowed->id]->fresh()->reminder_count);
        app()->call([new \App\BusinessModules\Core\Payments\Jobs\SendPaymentRemindersJob, 'handle']);
        Notification::assertSentToTimes($allowed, PaymentApprovalRequiredNotification::class, 1);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_permission_based_reminders_use_the_same_project_scope_and_current_level(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['project_id' => $project->id])->save();
        $denied = $this->createOrganizationApprover($context, 'assigned_projects');
        app(PaymentDocumentService::class)->submit($document, $context->user);
        $document->forceFill(['submitted_at' => now()->subDays(2)])->save();
        Notification::fake();
        app()->call([new \App\BusinessModules\Core\Payments\Jobs\SendPaymentRemindersJob, 'handle']);
        Notification::assertSentToTimes($context->user, PaymentApprovalRequiredNotification::class, 1);
        Notification::assertNotSentTo($denied, PaymentApprovalRequiredNotification::class);
        $this->assertSame(1, $document->approvals()->where('approval_level', 1)->firstOrFail()->reminder_count);
        $this->assertNull($document->approvals()->where('approval_level', 2)->firstOrFail()->last_reminder_at);
    }

    public static function paymentListConsumers(): array
    {
        return ['admin' => ['admin'], 'mobile' => ['mobile']];
    }

    #[DataProvider('paymentListConsumers')]
    public function test_list_action_queries_are_batched_instead_of_growing_per_document(string $consumer): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        for ($index = 0; $index < 6; $index++) {
            $document = $this->createSubmittedActInvoice($context);
            $document->forceFill(['project_id' => $project->id, 'status' => PaymentDocumentStatus::PENDING_APPROVAL])->save();
            PaymentApproval::query()->create([
                'payment_document_id' => $document->id, 'organization_id' => $context->organization->id,
                'approver_user_id' => $context->user->id, 'approval_level' => 1, 'approval_order' => 1, 'status' => 'pending',
            ]);
        }
        $headers = $consumer === 'admin' ? $context->authHeaders() : $context->mobileAuthHeaders();
        $baseUrl = '/api/v1/'.$consumer.'/payments/documents?project_id='.$project->id;
        $this->withHeaders($headers)->getJson($baseUrl.'&per_page=1')->assertOk();
        $queries = [];
        $recording = true;
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $event) use (&$queries, &$recording): void {
            if ($recording && (str_contains($event->sql, '"payment_approvals"')
                || (str_contains($event->sql, '"payment_documents"') && str_contains($event->sql, '"organization_user"')))) {
                $queries[] = $event->sql;
            }
        });
        try {
            $this->withHeaders($headers)->getJson($baseUrl.'&per_page=1')->assertOk();
            $singleCount = count($queries);
            $this->assertGreaterThan(0, $singleCount);
            $queries = [];
            $response = $this->withHeaders($headers)->getJson($baseUrl.'&per_page=6')->assertOk();
            $items = $response->json($consumer === 'admin' ? 'data' : 'data.items');
            $this->assertCount(6, $items);
            foreach ($items as $item) $this->assertTrue($item['action_summary']['primary_action']['is_enabled']);
            $this->assertLessThanOrEqual($singleCount + 1, count($queries), implode("\n", $queries));
        } finally {
            $recording = false;
        }
    }

    public static function datedPaymentListConsumers(): array
    {
        return ['overdue' => ['overdue', -1], 'upcoming' => ['upcoming', 1]];
    }

    #[DataProvider('datedPaymentListConsumers')]
    public function test_dated_lists_batch_recovery_queries_for_approved_act_invoices(string $endpoint, int $dueInDays): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $createInvoice = function () use ($context, $project, $dueInDays): void {
            $document = $this->createSubmittedActInvoice($context);
            $document->forceFill(['project_id' => $project->id, 'status' => PaymentDocumentStatus::APPROVED,
                'due_date' => now()->addDays($dueInDays)->toDateString()])->save();
        };
        $createInvoice();
        $url = '/api/v1/admin/payments/documents/'.$endpoint;
        $this->withHeaders($context->authHeaders())->getJson($url)->assertOk();
        $queries = [];
        $recording = true;
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $event) use (&$queries, &$recording): void {
            if ($recording && (str_contains($event->sql, '"payment_approvals"')
                || str_contains($event->sql, '"payment_transactions"')
                || (str_contains($event->sql, '"payment_documents"') && str_contains($event->sql, '"organization_user"')))) {
                $queries[] = $event->sql;
            }
        });
        try {
            $this->withHeaders($context->authHeaders())->getJson($url)->assertOk()->assertJsonCount(1, 'data');
            $singleCount = count($queries);
            $this->assertGreaterThan(0, $singleCount);
            $recording = false;
            for ($index = 0; $index < 5; $index++) $createInvoice();
            $queries = [];
            $recording = true;
            $response = $this->withHeaders($context->authHeaders())->getJson($url)->assertOk()->assertJsonCount(6, 'data');
            foreach ($response->json('data') as $item) {
                $this->assertSame('submit_payment_document', $item['action_summary']['primary_action']['key']);
                $this->assertTrue($item['action_summary']['primary_action']['is_enabled']);
            }
            $this->assertLessThanOrEqual($singleCount + 1, count($queries), implode("\n", $queries));
        } finally {
            $recording = false;
        }
    }

    public function test_a_new_rule_cannot_assign_an_approver_without_project_access(): void
    {
        Queue::fake();
        Notification::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePayments($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createSubmittedActInvoice($context);
        $document->forceFill(['project_id' => $project->id])->save();
        $denied = $this->createOrganizationApprover($context, 'assigned_projects');
        PaymentApprovalRule::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'Недоступный проект', 'conditions' => [],
            'is_active' => true, 'approval_chain' => [['level' => 1, 'order' => 1, 'approver_user_id' => $denied->id]],
        ]);
        try {
            app(PaymentDocumentService::class)->submit($document, $context->user);
            $this->fail('A project-inaccessible approver was accepted.');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('payments.validation.approver_project_access_required'), $exception->getMessage());
        }
        $this->assertSame(PaymentDocumentStatus::SUBMITTED, $document->fresh()->status);
        $this->assertSame(0, $document->approvals()->count());
        Notification::assertNothingSent();
    }

    private function createOrganizationApprover(AdminApiTestContext $context, string $projectAccessMode = 'all_projects'): User
    {
        $user = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($user->id, ['is_owner' => false, 'is_active' => true, 'project_access_mode' => $projectAccessMode]);
        UserRoleAssignment::assignRole(user: $user, roleSlug: 'web_admin',
            context: AuthorizationContext::getOrganizationContext((int) $context->organization->id));

        return $user;
    }

    private function assignPaymentPermissions(AdminApiTestContext $context, array $permissions, ?int $projectId = null): void
    {
        UserRoleAssignment::query()->where('user_id', $context->user->id)->delete();
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Payment permissions regression',
            'slug' => 'payment-permissions-'.uniqid(),
            'system_permissions' => [],
            'module_permissions' => ['payments' => $permissions],
            'interface_access' => ['admin', 'mobile'],
            'conditions' => null,
            'is_active' => true,
            'created_by' => $context->user->id,
        ]);
        UserRoleAssignment::assignRole(
            user: $context->user,
            roleSlug: $role->slug,
            context: $projectId !== null ? AuthorizationContext::getProjectContext($projectId, (int) $context->organization->id)
                : AuthorizationContext::getOrganizationContext((int) $context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );
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
