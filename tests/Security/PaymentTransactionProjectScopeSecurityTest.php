<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionAccessService;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionWorkflowService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\AuthSessionStatus;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class PaymentTransactionProjectScopeSecurityTest extends TestCase
{
    public function test_transaction_detail_hides_unassigned_project_with_organization_permission(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.transaction.view']);
        $headers = $this->headers($actor);
        $this->getJson('/api/v1/admin/payments/transactions/'.$visible->id, $headers)->assertOk();
        $this->getJson('/api/v1/admin/payments/transactions/'.$hidden->id, $headers)->assertNotFound();
    }

    public function test_list_scopes_rows_counts_filters_and_refund_aggregates_before_rendering(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.transaction.view']);
        $refund = $this->transaction($allowed, [
            'payment_document_id' => $visible->payment_document_id, 'amount' => -100, 'reverses_transaction_id' => $visible->id,
        ]);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $this->transaction($foreignProject, ['amount' => -900, 'reverses_transaction_id' => $visible->id]);
        $headers = $this->headers($actor);
        $response = $this->getJson('/api/v1/admin/payments/transactions?per_page=1', $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        self::assertNotSame($hidden->id, $response->json('data.0.id'));
        $this->getJson('/api/v1/admin/payments/transactions/'.$visible->id, $headers)
            ->assertOk()->assertJsonPath('data.refundable_amount', 200);
        $this->getJson('/api/v1/admin/payments/transactions?payment_document_id='.$visible->payment_document_id, $headers)
            ->assertOk()->assertJsonCount(2, 'data');
        foreach (['payment_document_id='.$hidden->payment_document_id, 'invoice_id='.$hidden->payment_document_id, 'project_id='.$restricted->id] as $filter) {
            $this->getJson('/api/v1/admin/payments/transactions?'.$filter.'&per_page=invalid', $headers)->assertNotFound();
        }
    }

    public function test_hidden_transaction_actions_fail_before_validation_and_make_no_changes(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context($this->permissions());
        $headers = $this->headers($actor);
        $before = $hidden->fresh()->getAttributes();
        $documentBefore = $hidden->paymentDocument->getAttributes();
        foreach (['approve', 'reject', 'cancel', 'refund'] as $action) {
            $this->postJson('/api/v1/admin/payments/transactions/'.$hidden->id.'/'.$action, [
                'notes' => [], 'reason' => [], 'amount' => 'invalid',
            ], $headers)->assertNotFound();
            $this->postJson('/api/v1/admin/payments/transactions/2147483647/'.$action, [], $headers)->assertNotFound();
        }
        self::assertSame($before, $hidden->fresh()->getAttributes());
        self::assertSame($documentBefore, $hidden->paymentDocument->fresh()->getAttributes());
        self::assertSame(2, PaymentTransaction::query()->count());
    }

    public function test_malformed_route_ids_are_not_found_before_validation_and_never_coerce_to_a_valid_transaction(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context($this->permissions());
        $headers = $this->headers($actor);
        foreach (['abc', '999999999999999999999999999', $visible->id.'invalid'] as $id) {
            $base = '/api/v1/admin/payments/transactions/'.$id;
            $this->getJson($base, $headers)->assertNotFound();
            foreach (['approve', 'reject', 'cancel', 'refund'] as $action) {
                $this->postJson($base.'/'.$action, ['notes' => [], 'reason' => [], 'amount' => 'invalid'], $headers)->assertNotFound();
            }
        }
        self::assertSame(PaymentTransactionStatus::COMPLETED, $visible->fresh()->status);
        self::assertSame(0, $visible->refunds()->count());
    }

    public function test_authorized_actions_and_refund_replay_work_without_transaction_view(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(array_values(array_diff($this->permissions(), ['payments.transaction.view'])));
        $headers = $this->headers($actor);
        foreach (['approve' => 'completed', 'reject' => 'failed', 'cancel' => 'cancelled'] as $action => $expected) {
            $transaction = $this->transaction($allowed, ['status' => PaymentTransactionStatus::PENDING]);
            $this->postJson('/api/v1/admin/payments/transactions/'.$transaction->id.'/'.$action, [
                'notes' => 'Reviewed', 'reason' => 'Authorized change',
            ], $headers)->assertOk()->assertJsonPath('data.status', $expected);
        }
        $headers['Idempotency-Key'] = 'security-refund-authorized-0001';
        $url = '/api/v1/admin/payments/transactions/'.$visible->id.'/refund';
        $body = ['amount' => 100, 'reason' => 'Authorized partial refund'];
        $response = $this->postJson($url, $body, $headers)->assertOk()
            ->assertJsonPath('data.original_transaction.refundable_amount', 200)
            ->assertJsonPath('data.refund_transaction.amount', -100);
        $refundId = $response->json('data.refund_transaction.id');
        $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('data.refund_transaction.id', $refundId);
        self::assertSame(1, $visible->refunds()->count());
        self::assertSame('200.00', $visible->paymentDocument->fresh()->paid_amount);
    }

    public function test_snapshot_and_current_parent_projects_and_organization_are_all_required(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context($this->permissions());
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $foreign = $this->transaction($foreignProject);
        $cases = [
            $this->transaction($allowed, ['payment_document_id' => $hidden->payment_document_id]),
            $this->transaction($restricted, ['payment_document_id' => $visible->payment_document_id]),
            $this->transaction($allowed, ['payment_document_id' => $hidden->payment_document_id, 'project_id' => null]),
            $this->transaction($allowed, ['payment_document_id' => $foreign->payment_document_id]),
            $foreign,
        ];
        $headers = $this->headers($actor);
        foreach ($cases as $transaction) {
            $this->getJson('/api/v1/admin/payments/transactions/'.$transaction->id, $headers)->assertNotFound();
            $this->postJson('/api/v1/admin/payments/transactions/'.$transaction->id.'/refund', [], $headers)->assertNotFound();
            $this->postJson('/api/v1/admin/payments/transactions/'.$transaction->id.'/cancel', [], $headers)->assertNotFound();
        }
        $this->getJson('/api/v1/admin/payments/transactions', $headers)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_live_assignment_role_and_membership_revocation_override_warmed_decisions(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.view']);
        self::assertTrue($actor->can('payments.transaction.view', ['organization_id' => $fixture->organization->id]));
        $access = app(PaymentTransactionAccessService::class);
        self::assertSame(1, $access->query($fixture->organization->id, $actor, 'payments.transaction.view')->count());
        $allowed->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        self::assertSame(0, $access->query($fixture->organization->id, $actor, 'payments.transaction.view')->count());
        $allowed->users()->updateExistingPivot($actor->id, ['is_active' => true]);
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => false]);
        self::assertSame(0, $access->query($fixture->organization->id, $actor, 'payments.transaction.view')->count());
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => true]);
        self::assertSame(1, $access->query($fixture->organization->id, $actor, 'payments.transaction.view')->count());
        $fixture->organization->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        self::assertSame(0, $access->query($fixture->organization->id, $actor, 'payments.transaction.view')->count());
    }

    public function test_status_mutation_rechecks_project_access_after_transaction_lock(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.approve']);
        PaymentTransaction::query()->whereKey($visible->id)->update(['status' => PaymentTransactionStatus::PENDING->value]);
        $revoked = $this->revokeOnTransactionLock($allowed, $actor);
        $this->postJson('/api/v1/admin/payments/transactions/'.$visible->id.'/approve', [], $this->headers($actor))->assertNotFound();
        self::assertTrue($revoked());
        self::assertSame(PaymentTransactionStatus::PENDING, $visible->fresh()->status);
        self::assertNull($visible->fresh()->approved_by_user_id);
    }

    public function test_refund_replay_rechecks_project_access_after_locks_before_returning_existing_result(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.refund']);
        $headers = [...$this->headers($actor), 'Idempotency-Key' => 'security-refund-revocation-0001'];
        $url = '/api/v1/admin/payments/transactions/'.$visible->id.'/refund';
        $body = ['amount' => 100, 'reason' => 'Partial refund'];
        $this->postJson($url, $body, $headers)->assertOk();
        $before = $visible->paymentDocument->fresh()->getAttributes();
        $revoked = $this->revokeOnTransactionLock($allowed, $actor);
        $this->postJson($url, $body, $headers)->assertNotFound();
        self::assertTrue($revoked());
        self::assertSame(1, $visible->refunds()->count());
        self::assertSame($before, $visible->paymentDocument->fresh()->getAttributes());
    }

    public function test_new_refund_rechecks_project_access_after_locks_before_any_financial_change(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.refund']);
        $before = $visible->paymentDocument->fresh()->getAttributes();
        $revoked = $this->revokeOnTransactionLock($allowed, $actor);
        $this->postJson('/api/v1/admin/payments/transactions/'.$visible->id.'/refund', [
            'amount' => 100, 'reason' => 'Revoked before refund',
        ], [...$this->headers($actor), 'Idempotency-Key' => 'security-new-refund-revoked-0001'])->assertNotFound();
        self::assertTrue($revoked());
        self::assertSame(0, $visible->refunds()->count());
        self::assertSame($before, $visible->paymentDocument->fresh()->getAttributes());
    }

    public function test_detail_does_not_eager_load_a_parent_moved_out_of_scope_after_transaction_query(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.view']);
        $moved = false;
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use ($visible, $restricted, &$moved): void {
            if ($moved || ! str_contains($query->sql, 'from "payment_transactions"') || ! str_contains($query->sql, '"refunded_amount"')) {
                return;
            }
            $moved = true;
            PaymentDocument::query()->whereKey($visible->payment_document_id)->update([
                'project_id' => $restricted->id, 'document_number' => 'RESTRICTED-AFTER-SELECT',
            ]);
        });
        $response = $this->getJson('/api/v1/admin/payments/transactions/'.$visible->id, $this->headers($actor))->assertNotFound();
        self::assertTrue($moved);
        self::assertStringNotContainsString('RESTRICTED-AFTER-SELECT', $response->getContent());
    }

    public function test_projectless_all_projects_and_archived_transactions_preserve_authorized_access(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context(['payments.transaction.view', 'payments.transaction.edit']);
        $allowed->update(['is_archived' => true]);
        $pending = $this->transaction($allowed, ['status' => PaymentTransactionStatus::PENDING]);
        $projectless = $this->transaction($allowed, ['project_id' => null, 'status' => PaymentTransactionStatus::PROCESSING], ['project_id' => null]);
        $restrictedPending = $this->transaction($restricted, ['status' => PaymentTransactionStatus::PENDING]);
        $headers = $this->headers($actor);
        foreach ([$visible, $pending, $projectless] as $transaction) {
            $this->getJson('/api/v1/admin/payments/transactions/'.$transaction->id, $headers)->assertOk();
        }
        $this->getJson('/api/v1/admin/payments/transactions/'.$projectless->id, $headers)->assertJsonPath('data.project_id', null);
        foreach ([$pending, $projectless] as $transaction) {
            $this->postJson('/api/v1/admin/payments/transactions/'.$transaction->id.'/cancel', [], $headers)->assertOk();
        }
        $this->postJson('/api/v1/admin/payments/transactions/'.$restrictedPending->id.'/cancel', [], $headers)->assertNotFound();
        $fixture->organization->users()->updateExistingPivot($actor->id, ['project_access_mode' => 'all_projects']);
        self::assertSame(5, app(PaymentTransactionAccessService::class)->query($fixture->organization->id, $actor, 'payments.transaction.view')->count());
    }

    public function test_strict_project_action_permission_does_not_grant_other_projects_or_projectless_transactions(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible, $hidden] = $this->context([]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Project transaction operator',
            'slug' => 'transaction_project_'.Str::lower(Str::random(12)), 'system_permissions' => [],
            'module_permissions' => ['payments' => ['payments.transaction.approve', 'payments.transaction.refund']],
            'interface_access' => ['admin'], 'is_active' => true, 'created_by' => $fixture->owner->id,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $actor->id, 'role_slug' => $role->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getProjectContext($allowed->id, $fixture->organization->id)->id,
            'assigned_by' => $fixture->owner->id, 'is_active' => true,
        ]);
        $workflow = app(PaymentTransactionWorkflowService::class);
        $pending = $this->transaction($allowed, ['status' => PaymentTransactionStatus::PENDING]);
        self::assertSame(PaymentTransactionStatus::COMPLETED, $workflow->changeStatus($fixture->organization->id, $actor, $pending->id, 'approve', [])->status);
        $payload = $workflow->refund($fixture->organization->id, $actor, $visible->id, [
            'amount' => 25, 'reason' => 'Project action', 'idempotency_key' => 'project-refund-security-0001',
        ]);
        self::assertSame('-25.00', $payload['refund_transaction']->amount);
        $projectless = $this->transaction($allowed, ['project_id' => null, 'status' => PaymentTransactionStatus::PENDING], ['project_id' => null]);
        foreach ([$hidden, $projectless] as $transaction) {
            try {
                $workflow->changeStatus($fixture->organization->id, $actor, $transaction->id, 'approve', []);
                self::fail('Project action escaped its context');
            } catch (ModelNotFoundException) {
                self::assertNull($transaction->fresh()->approved_by_user_id);
            }
        }
    }

    public function test_selected_refund_aggregate_null_does_not_trigger_per_transaction_queries(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.view']);
        $this->transaction($allowed);
        $transactions = app(PaymentTransactionAccessService::class)
            ->responseQuery($fixture->organization->id, $actor, 'payments.transaction.view')->get();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ($transactions as $transaction) {
                self::assertSame(300.0, $transaction->refundableAmount());
            }
            self::assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_refund_locks_contract_before_document_and_transaction_and_aborts_changed_contract_reference(): void
    {
        [$fixture, $actor, $allowed, $restricted, $visible] = $this->context(['payments.transaction.refund']);
        $contracts = [];
        foreach (['ORIGINAL', 'CHANGED'] as $number) {
            $contracts[] = Contract::query()->create([
                'organization_id' => $fixture->organization->id, 'project_id' => $allowed->id,
                'number' => $number, 'date' => now()->toDateString(), 'subject' => 'Refund source',
                'total_amount' => 1000, 'status' => 'active',
            ]);
        }
        $document = $visible->paymentDocument;
        $document->update(['source_type' => Contract::class, 'source_id' => $contracts[0]->id]);
        $locks = [];
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$locks): void {
            if (str_contains(strtolower($query->sql), 'for update') && preg_match('/from "([^"]+)"/', $query->sql, $match)
                && in_array($match[1], ['contracts', 'payment_documents', 'payment_transactions'], true)) {
                $locks[] = $match[1];
            }
        });
        $headers = [...$this->headers($actor), 'Idempotency-Key' => 'security-refund-contract-order-0001'];
        $url = '/api/v1/admin/payments/transactions/'.$visible->id.'/refund';
        $this->postJson($url, ['amount' => 100, 'reason' => 'Lock order'], $headers)->assertOk();
        self::assertSame(['contracts', 'payment_documents', 'payment_transactions'], array_slice($locks, 0, 3));
        $before = $document->fresh()->getAttributes();
        $changed = false;
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use ($document, $contracts, &$changed): void {
            if ($changed || ! str_contains(strtolower($query->sql), 'for update') || ! str_contains($query->sql, '"contracts"')) {
                return;
            }
            $changed = true;
            PaymentDocument::query()->whereKey($document->id)->update(['source_id' => $contracts[1]->id]);
        });
        $headers['Idempotency-Key'] = 'security-refund-contract-order-0002';
        $this->postJson($url, ['amount' => 25, 'reason' => 'Changed contract'], $headers)->assertNotFound();
        self::assertTrue($changed);
        self::assertSame($before, $document->fresh()->getAttributes());
        self::assertSame(1, $visible->refunds()->count());
    }

    private function revokeOnTransactionLock(Project $project, User $actor): \Closure
    {
        $revoked = false;
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use ($project, $actor, &$revoked): void {
            if ($revoked || ! str_contains(strtolower($query->sql), 'for update') || ! str_contains($query->sql, '"payment_transactions"')) {
                return;
            }
            $revoked = true;
            $project->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        });

        return static function () use (&$revoked): bool { return $revoked; };
    }

    private function permissions(): array
    {
        return ['payments.transaction.view', 'payments.transaction.approve', 'payments.transaction.reject', 'payments.transaction.edit', 'payments.transaction.refund'];
    }

    private function context(array $permissions): array
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => $permissions]);
        $allowed = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $restricted = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $allowed->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);

        return [$fixture, $actor, $allowed, $restricted, $this->transaction($allowed), $this->transaction($restricted)];
    }

    private function transaction(Project $project, array $overrides = [], array $documentOverrides = []): PaymentTransaction
    {
        $document = PaymentDocument::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'document_type' => PaymentDocumentType::INVOICE, 'document_number' => 'TX-'.Str::uuid(),
            'document_date' => now()->toDateString(), 'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000, 'paid_amount' => 300, 'remaining_amount' => 700,
            'currency' => 'RUB', 'status' => PaymentDocumentStatus::PARTIALLY_PAID,
            ...$documentOverrides,
        ]);

        return PaymentTransaction::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'payment_document_id' => $document->id, 'amount' => 300, 'currency' => 'RUB',
            'transaction_date' => now()->toDateString(), 'payment_method' => 'bank_transfer',
            'status' => PaymentTransactionStatus::COMPLETED,
            ...$overrides,
        ]);
    }

    private function headers(User $actor): array
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Transaction security regression', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $sessionUuid, (int) $actor->current_organization_id, false);

        return ['Authorization' => 'Bearer '.$tokens->accessToken, 'Accept' => 'application/json', 'Origin' => 'https://admin.1мост.рф'];
    }
}
