<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Services\PaymentExportService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentAccessService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentQueryService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentWorkflowService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Enums\EstimatePositionItemType;
use App\Models\Contract;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class PaymentDocumentProjectScopeSecurityTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    public function test_document_in_an_unassigned_project_is_not_readable_with_organization_invoice_permission(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => ['payments.invoice.view']]);
        $allowed = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $restricted = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $allowed->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);
        $allowedDocument = $this->document($allowed);
        $restrictedDocument = $this->document($restricted);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($actor, 'payments.invoice.view', ['organization_id' => $fixture->organization->id]));
        self::assertTrue(app(UserProjectAccessService::class)->canAccessCurrentOrganizationProject($actor, $allowed));
        self::assertFalse(app(UserProjectAccessService::class)->canAccessCurrentOrganizationProject($actor, $restricted));
        $headers = $this->headers($actor);

        $this->getJson('/api/v1/admin/payments/documents/'.$allowedDocument->id, $headers)->assertOk();
        $this->getJson('/api/v1/admin/payments/documents/'.$restrictedDocument->id, $headers)->assertNotFound();
    }

    public function test_lists_totals_search_and_due_collections_only_include_accessible_projects(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.invoice.view']);
        $headers = $this->headers($actor);
        $queries = app(PaymentDocumentQueryService::class);
        $page = $queries->listForOrganization($fixture->organization->id, ['per_page' => 1], $actor);
        self::assertSame(1, $page->total());
        self::assertSame([$visible->id], $page->getCollection()->modelKeys());
        $this->getJson('/api/v1/admin/payments/documents', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/payments/documents?search='.$hidden->document_number, $headers)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/payments/documents/statistics', $headers)
            ->assertOk()->assertJsonPath('data.total_count', 1)->assertJsonPath('data.total_amount', 98543);
        self::assertSame([$visible->id], $queries->overdue($fixture->organization->id, $actor)->modelKeys());
        PaymentDocument::query()->whereKey([$visible->id, $hidden->id])->update(['due_date' => now()->addDay()->toDateString()]);
        self::assertSame([$visible->id], $queries->upcoming($fixture->organization->id, 7, $actor)->modelKeys());
        self::assertSame(0, $queries->listForOrganization($fixture->organization->id, ['project_ids' => [$restricted->id]], $actor)->total());
    }

    public function test_all_document_actions_deny_unassigned_sources_before_validation_without_side_effects(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context($this->operationPermissions());
        $headers = $this->headers($actor);
        $before = $hidden->fresh()->getAttributes();
        $base = '/api/v1/admin/payments/documents/'.$hidden->id;
        $this->putJson($base, ['amount' => 'invalid'], $headers)->assertNotFound();
        $this->deleteJson($base, [], $headers)->assertNotFound();
        $this->getJson($base.'/print-order', $headers)->assertNotFound();
        foreach (['submit', 'schedule', 'register-payment', 'payment-budget-preview', 'cancel'] as $action) {
            $this->postJson($base.'/'.$action, ['amount' => 'invalid'], $headers)->assertNotFound();
        }
        self::assertSame($before, $hidden->fresh()->getAttributes());
        self::assertSame(0, $hidden->transactions()->count());
        foreach ([[$visible->id, $hidden->id], [$visible->id, $hidden->id, 'bad'], [$visible->id, 2147483647]] as $ids) {
            $this->postJson('/api/v1/admin/payments/documents/bulk', ['ids' => $ids, 'action' => 'cancel'], $headers)->assertNotFound();
            self::assertSame(PaymentDocumentStatus::APPROVED, $visible->fresh()->status);
        }
    }

    public function test_references_and_target_moves_are_checked_before_validation_or_price_analysis(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.invoice.create', 'payments.invoice.edit']);
        $contract = Contract::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $restricted->id,
            'number' => 'SECRET-B', 'date' => now()->toDateString(), 'subject' => 'Private work',
            'total_amount' => 1000, 'status' => 'active',
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $restricted->id,
            'number' => 'PRIVATE-EST', 'name' => 'Private estimate', 'estimate_date' => now()->toDateString(),
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id, 'item_type' => EstimatePositionItemType::WORK->value,
            'position_number' => '1', 'name' => 'Private price', 'quantity' => 1, 'unit_price' => 8000, 'total_amount' => 8000,
        ]);
        $headers = $this->headers($actor);
        foreach ([
            ['contract_id' => $contract->id],
            ['source_type' => Contract::class, 'source_id' => $contract->id],
            ['invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id],
            ['source_type' => PaymentDocument::class, 'source_id' => $hidden->id],
            ['estimate_id' => $estimate->id],
            ['estimate_splits' => [['estimate_item_id' => $item->id, 'quantity' => 1, 'unit_price_actual' => 0]]],
        ] as $reference) {
            $response = $this->postJson('/api/v1/admin/payments/documents', [
                'project_id' => $allowed->id, 'document_type' => 'invoice', 'amount' => 10, ...$reference,
            ], $headers)->assertNotFound();
            self::assertStringNotContainsString('Private price', $response->getContent());
        }
        $this->putJson('/api/v1/admin/payments/documents/'.$visible->id, ['project_id' => $restricted->id], $headers)->assertNotFound();
        self::assertSame($allowed->id, $visible->fresh()->project_id);
        self::assertSame(2, PaymentDocument::query()->count());
    }

    public function test_approval_actions_history_status_and_queue_are_scoped_before_fallback_writes(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context([
            'payments.transaction.view', 'payments.transaction.approve', 'payments.transaction.reject', 'payments.settings.manage',
        ]);
        $visible->update(['status' => PaymentDocumentStatus::PENDING_APPROVAL]);
        $hidden->update(['status' => PaymentDocumentStatus::PENDING_APPROVAL]);
        $headers = $this->headers($actor);
        $base = '/api/v1/admin/payments/approvals/documents/'.$hidden->id;
        $this->getJson($base.'/history', $headers)->assertNotFound();
        $this->getJson($base.'/status', $headers)->assertNotFound();
        foreach (['approve', 'reject', 'send-reminders'] as $action) {
            $this->postJson($base.'/'.$action, ['reason' => []], $headers)->assertNotFound();
        }
        self::assertSame(0, $hidden->approvals()->count());
        $this->getJson('/api/v1/admin/payments/approvals/my', $headers)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.payment_document_id', $visible->id);
        self::assertSame(0, $hidden->approvals()->count());
        self::assertSame(1, $visible->approvals()->count());
        $this->postJson('/api/v1/admin/payments/approvals/documents/'.$visible->id.'/approve', [], $headers)->assertOk();
        self::assertSame(PaymentDocumentStatus::APPROVED, $visible->fresh()->status);
        self::assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $hidden->fresh()->status);
    }

    public function test_live_assignment_role_and_membership_revocations_override_warmed_permissions(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.invoice.view']);
        $access = app(PaymentDocumentAccessService::class);
        self::assertTrue($actor->can('payments.invoice.view', ['organization_id' => $fixture->organization->id]));
        self::assertSame($visible->id, $access->find($fixture->organization->id, $actor, $visible->id, 'payments.invoice.view')->id);
        $allowed->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        self::assertSame(0, $access->query($fixture->organization->id, $actor, 'payments.invoice.view')->count());
        $allowed->users()->updateExistingPivot($actor->id, ['is_active' => true]);
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => false]);
        self::assertSame(0, $access->query($fixture->organization->id, $actor, 'payments.invoice.view')->count());
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => true]);
        self::assertSame(1, $access->query($fixture->organization->id, $actor, 'payments.invoice.view')->count());
        $fixture->organization->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        self::assertSame(0, $access->query($fixture->organization->id, $actor, 'payments.invoice.view')->count());
    }

    public function test_assigned_approval_queue_never_includes_another_organization(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.view']);
        $visible->update(['status' => PaymentDocumentStatus::PENDING_APPROVAL]);
        $foreign = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $foreignDocument = $this->document($foreign, ['project_id' => null, 'status' => PaymentDocumentStatus::PENDING_APPROVAL]);
        foreach ([$visible, $foreignDocument] as $document) {
            PaymentApproval::query()->create([
                'payment_document_id' => $document->id, 'organization_id' => $document->organization_id,
                'approver_user_id' => $actor->id, 'approval_role' => null,
                'approval_permission' => 'payments.transaction.approve', 'approval_level' => 1,
                'approval_order' => 1, 'status' => 'pending',
            ]);
        }
        $this->getJson('/api/v1/admin/payments/approvals/my', $this->headers($actor))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.payment_document_id', $visible->id);
    }

    public function test_project_role_allows_only_its_project_and_write_does_not_require_view(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context([]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Project payment operator',
            'slug' => 'payment_project_'.Str::lower(Str::random(12)), 'system_permissions' => [],
            'module_permissions' => ['payments' => ['payments.invoice.edit', 'payments.invoice.create']],
            'interface_access' => ['admin', 'mobile'], 'is_active' => true, 'created_by' => $fixture->owner->id,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $actor->id, 'role_slug' => $role->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getProjectContext($allowed->id, $fixture->organization->id)->id,
            'assigned_by' => $fixture->owner->id, 'is_active' => true,
        ]);
        $visible->update([
            'status' => PaymentDocumentStatus::DRAFT,
            'payer_organization_id' => $fixture->organization->id,
            'payee_organization_id' => $fixture->foreignOrganization->id,
        ]);
        $workflow = app(PaymentDocumentWorkflowService::class);
        $updated = $workflow->update($visible, ['description' => 'Allowed without view'], $actor);
        self::assertSame('Allowed without view', $updated->description);
        $httpActor = $fixture->addMember(['payments' => ['payments.invoice.edit']]);
        $allowed->users()->attach($httpActor->id, ['role' => 'member', 'is_active' => true]);
        $this->putJson('/api/v1/admin/payments/documents/'.$visible->id, ['description' => 'HTTP edit without view'], $this->headers($httpActor))->assertOk();
        self::assertSame('HTTP edit without view', $visible->fresh()->description);
        $contractor = \App\Models\Contractor::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Saved executor',
            'source_organization_id' => $fixture->organization->id,
        ]);
        $contract = Contract::query()->create([
            'contractor_id' => $contractor->id, 'organization_id' => $fixture->organization->id,
            'project_id' => $allowed->id, 'number' => 'DERIVED-PROJECT', 'date' => now()->toDateString(),
            'subject' => 'Allowed work', 'total_amount' => 1000, 'status' => 'active', 'contract_side_type' => 'subcontract',
        ]);
        foreach ([['first', 'contractor', $fixture->foreignOrganization], ['second', 'subcontractor', $fixture->organization]] as [$side, $partyRole, $organization]) {
            \App\Models\ContractParty::query()->create([
                'contract_id' => $contract->id, 'side' => $side, 'role' => $partyRole,
                'linked_organization_id' => $organization->id, 'name' => $organization->name, 'snapshot' => [],
            ]);
        }
        $created = $workflow->create($fixture->organization->id, $actor->id, [
            'contract_id' => $contract->id, 'document_type' => 'invoice', 'invoice_type' => 'other',
            'document_date' => now()->toDateString(), 'amount' => 25, 'direction' => 'outgoing', 'status' => 'draft',
        ], $actor);
        self::assertSame($allowed->id, $created['document']->project_id);
        self::assertFalse(app(AuthorizationService::class)->canCurrent($actor, 'payments.invoice.view', ['organization_id' => $fixture->organization->id]));
        try {
            $workflow->update($hidden, ['description' => 'Forbidden'], $actor);
            self::fail('Project permission escaped its context');
        } catch (ModelNotFoundException) {
            self::assertNull($hidden->fresh()->description);
        }
    }

    public function test_registration_rechecks_access_after_locked_reload_and_preserves_document_state(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.register']);
        $before = $visible->fresh()->getAttributes();
        $revoked = false;
        PaymentDocument::retrieved(function (PaymentDocument $loaded) use ($visible, $actor, $allowed, &$revoked): void {
            if ($loaded->id !== $visible->id || $revoked || DB::transactionLevel() < 2) {
                return;
            }
            $revoked = true;
            $allowed->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        });
        try {
            app(PaymentDocumentWorkflowService::class)->registerPayment($visible, $actor->id, [
                'amount' => 100, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer',
            ], $actor);
            self::fail('Access revoked on locked reload was ignored');
        } catch (ModelNotFoundException) {
            self::assertTrue($revoked);
            self::assertSame($before, $visible->fresh()->getAttributes());
            self::assertSame(0, $visible->transactions()->count());
        }
    }

    public function test_all_projects_projectless_and_archived_documents_remain_available_to_authorized_members(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.invoice.view']);
        $fixture->organization->users()->updateExistingPivot($actor->id, ['project_access_mode' => 'all_projects']);
        $allowed->update(['is_archived' => true]);
        $projectless = $this->document($allowed, ['project_id' => null]);
        $access = app(PaymentDocumentAccessService::class);
        self::assertEqualsCanonicalizing([$visible->id, $hidden->id, $projectless->id],
            $access->query($fixture->organization->id, $actor, 'payments.invoice.view')->pluck('payment_documents.id')->all());
        $foreign = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $this->document($foreign);
        self::assertSame(3, $access->query($fixture->organization->id, $actor, 'payments.invoice.view')->count());
        self::assertSame(0, $access->query($fixture->foreignOrganization->id, $actor, 'payments.invoice.view')->count());
    }

    public function test_contract_availability_does_not_reveal_unassigned_or_unfiltered_multi_project_amounts(): void
    {
        [$fixture, $actor, $allowed, $restricted] = $this->context(['payments.invoice.view']);
        $contract = Contract::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $restricted->id,
            'number' => 'UNASSIGNED-AVAILABILITY', 'date' => now()->toDateString(),
            'subject' => 'Private', 'total_amount' => 1000, 'status' => 'active',
        ]);
        $headers = $this->headers($actor);
        $base = '/api/v1/admin/payments/contracts/'.$contract->id.'/availability';
        $this->getJson($base, $headers)->assertNotFound();
        $contract->update(['is_multi_project' => true, 'project_id' => null]);
        $contract->projects()->attach([$allowed->id, $restricted->id]);
        $this->getJson($base, $headers)->assertNotFound();
        $this->getJson($base.'?project_id='.$allowed->id, $headers)->assertOk();
    }

    public function test_calendar_detail_and_rescheduling_preserve_their_own_permissions_and_hide_unassigned_documents(): void
    {
        $this->enableImmutableAuditWriter();
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.schedule.view', 'payments.schedule.edit']);
        $headers = $this->headers($actor);
        $this->getJson('/api/v1/admin/payments/schedules/documents/'.$visible->id, $headers)->assertOk();
        $this->getJson('/api/v1/admin/payments/schedules/documents/'.$hidden->id, $headers)->assertNotFound();
        $before = $hidden->fresh()->getAttributes();
        $this->postJson('/api/v1/admin/payments/documents/'.$hidden->id.'/reschedule', ['date' => []], $headers)->assertNotFound();
        $this->postJson('/api/v1/admin/payments/documents/'.$visible->id.'/reschedule', [
            'date' => now()->addDays(3)->toDateString(), 'reason' => 'Authorized calendar edit',
        ], $headers)->assertOk();
        self::assertSame(PaymentDocumentStatus::SCHEDULED, $visible->fresh()->status);
        self::assertSame($before, $hidden->fresh()->getAttributes());
    }

    public function test_calendar_and_schedule_collections_scope_documents_before_summaries(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.schedule.view']);
        $headers = $this->headers($actor);
        $calendar = '/api/v1/admin/payments/documents/calendar?start=2026-10-01&end=2026-10-31';
        $response = $this->getJson($calendar.'&source_type=payment_document', $headers)->assertOk()->assertJsonCount(1, 'data.items');
        self::assertStringNotContainsString($hidden->document_number, $response->getContent());
        foreach ([$visible, $hidden] as $document) {
            PaymentSchedule::query()->create([
                'payment_document_id' => $document->id, 'installment_number' => 1,
                'due_date' => now()->subDay()->toDateString(), 'amount' => 98543, 'status' => 'pending',
            ]);
        }
        $this->getJson('/api/v1/admin/payments/schedules', $headers)->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/admin/payments/schedules/overdue', $headers)->assertOk()->assertJsonCount(1, 'data');
        $response = $this->getJson($calendar.'&source_type=payment_schedule', $headers)->assertOk()->assertJsonCount(1, 'data.items');
        self::assertStringNotContainsString($hidden->document_number, $response->getContent());
        PaymentSchedule::query()->update(['due_date' => now()->addDay()->toDateString()]);
        $this->getJson('/api/v1/admin/payments/schedules/upcoming', $headers)->assertOk()->assertJsonCount(1, 'data');
        PaymentSchedule::query()->update(['due_date' => null]);
        $response = $this->getJson($calendar, $headers)->assertOk();
        self::assertStringNotContainsString($hidden->document_number, $response->getContent());
    }

    public function test_schedule_replacement_checks_access_before_validation_and_retains_authorized_creation(): void
    {
        $this->enableImmutableAuditWriter();
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.schedule.create']);
        $headers = $this->headers($actor);
        $this->postJson('/api/v1/admin/payments/schedules', ['payment_document_id' => $hidden->id], $headers)->assertNotFound();
        self::assertSame(0, PaymentSchedule::query()->where('payment_document_id', $hidden->id)->count());
        $this->postJson('/api/v1/admin/payments/schedules', [
            'payment_document_id' => $visible->id, 'installments' => [[
                'installment_number' => 1, 'due_date' => now()->addDay()->toDateString(), 'amount' => 98543,
            ]],
        ], $headers)->assertCreated();
        self::assertSame(1, PaymentSchedule::query()->where('payment_document_id', $visible->id)->count());
        self::assertSame(PaymentDocumentStatus::SCHEDULED, $visible->fresh()->status);
        self::assertSame(PaymentDocumentStatus::APPROVED, $hidden->fresh()->status);
    }

    public function test_report_exports_enforce_document_scope_without_requiring_invoice_view(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.reports.export']);
        $headers = $this->headers($actor);
        $export = Mockery::mock(PaymentExportService::class);
        $export->shouldNotReceive('exportPaymentOrderToPdf');
        $export->shouldNotReceive('exportPaymentRegistry1C');
        $path = tempnam(sys_get_temp_dir(), 'security-payment-export-');
        self::assertNotFalse($path);
        file_put_contents($path, 'export fixture');
        $export->shouldReceive('exportDocumentsToExcel')->once()
            ->withArgs(fn ($documents): bool => $documents->modelKeys() === [$visible->id])->andReturn($path);
        app()->instance(PaymentExportService::class, $export);
        try {
            $this->postJson('/api/v1/admin/payments/export/pdf/'.$hidden->id, [], $headers)->assertNotFound();
            $this->postJson('/api/v1/admin/payments/export/1c', ['document_ids' => [$visible->id, $hidden->id]], $headers)->assertNotFound();
            $this->postJson('/api/v1/admin/payments/export/excel', ['filters' => ['project_id' => $restricted->id]], $headers)->assertNotFound();
            $this->postJson('/api/v1/admin/payments/export/excel', [], $headers)->assertOk();
        } finally {
            unlink($path);
        }
    }

    private function context(array $permissions): array
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => $permissions]);
        $allowed = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $restricted = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $allowed->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);

        return [$fixture, $actor, $allowed, $restricted, $this->document($allowed), $this->document($restricted)];
    }

    private function operationPermissions(): array
    {
        return [
            'payments.invoice.edit', 'payments.invoice.delete', 'payments.invoice.export', 'payments.invoice.issue',
            'payments.schedule.create', 'payments.transaction.register', 'payments.invoice.cancel',
        ];
    }

    private function document(Project $project, array $overrides = []): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'document_type' => PaymentDocumentType::INVOICE, 'document_number' => 'SEC-'.Str::uuid(),
            'document_date' => '2026-10-01', 'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::OTHER, 'amount' => 98543, 'paid_amount' => 0,
            'remaining_amount' => 98543, 'currency' => 'RUB', 'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => '2026-10-02',
            ...$overrides,
        ]);
    }

    private function headers(User $actor): array
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Security regression', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $sessionUuid, (int) $actor->current_organization_id, false);

        return ['Authorization' => 'Bearer '.$tokens->accessToken, 'Accept' => 'application/json', 'Origin' => 'https://admin.1мост.рф'];
    }
}
