<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentAuditLog;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentAuditService;
use App\Enums\AuthSessionStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class PaymentDocumentActivityControllerTest extends TestCase
{
    use RefreshDatabase;
    use EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
    }

    public function test_activity_returns_explicit_approval_transaction_and_safe_audit_contracts(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => ['payments.transaction.view']]);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $project->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);
        $document = $this->document($project);
        $approval = PaymentApproval::query()->create([
            'payment_document_id' => $document->id, 'organization_id' => $document->organization_id,
            'approver_user_id' => $actor->id, 'approval_permission' => 'payments.transaction.approve',
            'approval_level' => 2, 'approval_order' => 1, 'status' => 'approved',
            'decision_comment' => 'Учебное согласование', 'decided_at' => '2026-10-19T02:00:00Z',
            'conditions' => ['internal_rule' => 'not-for-export'],
        ]);
        $transaction = PaymentTransaction::query()->create([
            'payment_document_id' => $document->id, 'organization_id' => $document->organization_id,
            'project_id' => $project->id, 'amount' => 100, 'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER, 'status' => PaymentTransactionStatus::COMPLETED,
            'transaction_date' => '2026-10-19', 'reference_number' => 'TRAINING-ACTIVITY',
            'notes' => 'Учебная запись', 'metadata' => ['internal' => 'not-for-export'],
            'gateway_response' => ['internal' => 'not-for-export'],
        ]);
        $this->actingAs($actor);
        $audit = app(PaymentAuditService::class)->log('updated', $document,
            ['bank_account' => 'synthetic-old'], ['bank_account' => 'synthetic-new'], 'Internal audit description');

        $response = $this->getJson("/api/v1/admin/payments/documents/{$document->id}/activity", $this->headers($actor));

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data.approvals');
        $response->assertJsonPath('data.approvals.0.id', $approval->id);
        $response->assertJsonPath('data.approvals.0.level', 2);
        $response->assertJsonPath('data.approvals.0.approver.name', $actor->name);
        $response->assertJsonPath('data.approvals.0.comment', 'Учебное согласование');
        $this->assertNotSame('payments.transaction.approve', $response->json('data.approvals.0.approval_permission_label'));
        $this->assertNotEmpty($response->json('data.approvals.0.decided_at'));
        $response->assertJsonMissingPath('data.approvals.0.conditions');
        $response->assertJsonCount(1, 'data.transactions');
        $response->assertJsonPath('data.transactions.0.id', $transaction->id);
        $response->assertJsonPath('data.transactions.0.transaction_date', '2026-10-19');
        $response->assertJsonPath('data.transactions.0.payment_method', 'bank_transfer');
        $response->assertJsonPath('data.transactions.0.reference_number', 'TRAINING-ACTIVITY');
        $response->assertJsonMissingPath('data.transactions.0.metadata');
        $response->assertJsonMissingPath('data.transactions.0.gateway_response');
        $response->assertJsonCount(2, 'data.audit_logs');
        $response->assertJsonPath('data.audit_logs.0.id', $audit->id);
        $response->assertJsonPath('data.audit_logs.0.action', 'Обновлен');
        $response->assertJsonPath('data.audit_logs.0.user.name', $actor->name);
        $response->assertJsonPath('data.audit_logs.1.action', 'Создан');
        $this->assertNotEmpty($response->json('data.audit_logs.0.timestamp'));
        $this->assertEqualsCanonicalizing(['id', 'action', 'timestamp', 'user'], array_keys($response->json('data.audit_logs.0')));
        foreach (['approvals.0.decided_at', 'approvals.0.created_at', 'transactions.0.created_at', 'audit_logs.0.timestamp'] as $path) {
            $this->assertMatchesRegularExpression('/T.*(?:Z|[+-]\d{2}:\d{2})$/', $response->json('data.'.$path));
        }
    }

    public function test_activity_is_empty_for_a_document_without_events_and_does_not_read_another_organization(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => ['payments.transaction.view']]);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $project->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);
        $document = PaymentDocument::withoutEvents(fn () => $this->document($project));
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $foreignDocument = $this->document($foreignProject);
        $headers = $this->headers($actor);

        $this->getJson("/api/v1/admin/payments/documents/{$document->id}/activity", $headers)
            ->assertOk()->assertJsonPath('data.approvals', [])
            ->assertJsonPath('data.transactions', [])->assertJsonPath('data.audit_logs', []);
        $this->getJson("/api/v1/admin/payments/documents/{$foreignDocument->id}/activity", $headers)->assertNotFound();
    }

    public function test_activity_preserves_assigned_project_scope(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => ['payments.transaction.view']]);
        $allowed = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $restricted = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $allowed->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);
        $visible = $this->document($allowed);
        $hidden = $this->document($restricted);
        $headers = $this->headers($actor);

        $this->getJson("/api/v1/admin/payments/documents/{$visible->id}/activity", $headers)->assertOk();
        $this->getJson("/api/v1/admin/payments/documents/{$hidden->id}/activity", $headers)->assertNotFound();
    }

    public function test_unknown_activity_action_uses_a_translated_safe_label(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => ['payments.transaction.view']]);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $project->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);
        $document = $this->document($project);
        $this->actingAs($actor);
        app(PaymentAuditService::class)->log('unknown_technical_event', $document);
        $this->assertSame('Изменение документа', trans_message('payments.audit.document_changed'));
        app('translator')->addLines(['payments.audit.document_changed' => 'Учебная подпись изменения'], 'ru');

        $response = $this->getJson("/api/v1/admin/payments/documents/{$document->id}/activity", $this->headers($actor));

        $response->assertOk()->assertJsonPath('data.audit_logs.0.action', 'Учебная подпись изменения');
    }

    public function test_activity_rejects_invalid_document_identifiers_without_database_conversion_errors(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => ['payments.transaction.view']]);
        $headers = $this->headers($actor);

        foreach (['not-a-number', '0', '-1', '99999999999999999999999999999'] as $id) {
            $this->getJson("/api/v1/admin/payments/documents/{$id}/activity", $headers)->assertNotFound();
        }
    }
    public function test_known_activity_actions_use_the_translation_catalog(): void
    {
        $this->assertSame('Создан', trans_message('payments.audit.actions.created'));
        app('translator')->addLines(['payments.audit.actions.created' => 'Учебная подпись создания'], 'ru');

        $this->assertSame('Учебная подпись создания', (new PaymentAuditLog(['action' => 'created']))->getActionLabel());
        $this->assertSame('Утверждено', trans_message('payments.approval.statuses.approved'));
        $this->assertSame('Финансовый директор', trans_message('payments.approval.roles.financial_director'));
        app('translator')->addLines([
            'payments.approval.statuses.approved' => 'Учебная подпись согласования',
            'payments.approval.roles.financial_director' => 'Учебная подпись должности',
        ], 'ru');
        $this->assertSame('Учебная подпись согласования', (new PaymentApproval(['status' => 'approved']))->getStatusLabel());
        $this->assertSame('Учебная подпись должности', (new PaymentApproval(['approval_role' => 'financial_director']))->getRoleLabel());
    }

    public function test_activity_bounds_each_section_and_exposes_the_next_page(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => ['payments.transaction.view']]);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $project->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);
        $document = $this->document($project);
        $this->actingAs($actor);
        $approvals = [];
        $transactions = [];
        $events = [];
        for ($index = 1; $index <= 3; $index++) {
            $approvals[] = PaymentApproval::query()->create([
                'payment_document_id' => $document->id, 'organization_id' => $document->organization_id,
                'approver_user_id' => $actor->id, 'approval_level' => $index, 'approval_order' => 1,
                'status' => 'pending', 'approval_permission' => 'payments.transaction.approve',
            ]);
            $transactions[] = PaymentTransaction::query()->create([
                'payment_document_id' => $document->id, 'organization_id' => $document->organization_id,
                'project_id' => $project->id, 'amount' => 10, 'currency' => 'RUB',
                'payment_method' => PaymentMethod::BANK_TRANSFER, 'status' => PaymentTransactionStatus::COMPLETED,
                'transaction_date' => '2026-10-19',
            ]);
            if ($index < 3) {
                $events[] = app(PaymentAuditService::class)->log('updated', $document);
            }
        }
        $headers = $this->headers($actor);
        $path = "/api/v1/admin/payments/documents/{$document->id}/activity";

        $this->getJson($path.'?per_page=1', $headers)->assertOk()
            ->assertJsonCount(1, 'data.approvals')->assertJsonCount(1, 'data.transactions')->assertJsonCount(1, 'data.audit_logs')
            ->assertJsonPath('data.approvals.0.id', $approvals[0]->id)
            ->assertJsonPath('data.transactions.0.id', $transactions[2]->id)
            ->assertJsonPath('data.audit_logs.0.id', $events[1]->id)
            ->assertJsonPath('data.pagination.current_page', 1)->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonPath('data.pagination.totals', ['approvals' => 3, 'transactions' => 3, 'audit_logs' => 3]);
        $this->getJson($path.'?per_page=1&page=2', $headers)->assertOk()
            ->assertJsonCount(1, 'data.approvals')->assertJsonCount(1, 'data.transactions')->assertJsonCount(1, 'data.audit_logs')
            ->assertJsonPath('data.approvals.0.id', $approvals[1]->id)
            ->assertJsonPath('data.transactions.0.id', $transactions[1]->id)
            ->assertJsonPath('data.audit_logs.0.id', $events[0]->id)
            ->assertJsonPath('data.pagination.current_page', 2);
        $this->getJson($path.'?per_page=101', $headers)->assertUnprocessable();
        $this->getJson($path.'?page=0', $headers)->assertUnprocessable();
    }

    private function document(Project $project): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'document_type' => PaymentDocumentType::INVOICE, 'document_number' => 'ACTIVITY-'.Str::uuid(),
            'document_date' => '2026-10-19', 'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::OTHER, 'amount' => 1000, 'paid_amount' => 0,
            'remaining_amount' => 1000, 'currency' => 'RUB', 'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => '2026-10-22',
        ]);
    }

    private function headers(User $actor): array
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Activity contract regression', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $sessionUuid, (int) $actor->current_organization_id, false);

        return ['Authorization' => 'Bearer '.$tokens->accessToken, 'Accept' => 'application/json', 'Origin' => 'https://admin.1мост.рф'];
    }
}
