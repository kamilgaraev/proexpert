<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class PaymentRecipientControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_can_view_and_confirm_own_incoming_document(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $sender = Organization::factory()->verified()->create(['name' => 'Sender Org']);
        $document = $this->createIncomingDocument($context, $sender);

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/incoming/documents');

        $listResponse->assertOk();
        $listResponse->assertJsonPath('data.0.id', $document->id);
        $listResponse->assertJsonPath('data.0.is_viewed', false);
        $listResponse->assertJsonPath('data.0.is_confirmed', false);

        $viewResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/incoming/documents/{$document->id}/view");

        $viewResponse->assertOk();
        $this->assertDatabaseMissing('payment_documents', [
            'id' => $document->id,
            'recipient_viewed_at' => null,
        ]);

        $confirmResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/payments/incoming/documents/{$document->id}/confirm", [
                'comment' => 'Платеж получен',
            ]);

        $confirmResponse->assertOk();
        $confirmResponse->assertJsonPath('data.comment', 'Платеж получен');

        $this->assertDatabaseHas('payment_documents', [
            'id' => $document->id,
            'recipient_confirmation_comment' => 'Платеж получен',
            'recipient_confirmed_by_user_id' => $context->user->id,
        ]);
    }

    public function test_incoming_document_access_is_scoped_to_recipient_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $this->activatePaymentsModule($foreignContext->organization->id);
        $sender = Organization::factory()->verified()->create(['name' => 'Sender Org']);
        $document = $this->createIncomingDocument($context, $sender);

        $foreignListResponse = $this->withHeaders($foreignContext->authHeaders())
            ->getJson('/api/v1/admin/payments/incoming/documents');

        $foreignListResponse->assertOk();
        $foreignListResponse->assertJsonPath('data', []);

        $foreignShowResponse = $this->withHeaders($foreignContext->authHeaders())
            ->getJson("/api/v1/admin/payments/incoming/documents/{$document->id}");

        $foreignShowResponse->assertNotFound();
    }

    public function test_incoming_statistics_count_pending_and_confirmed_documents_for_recipient(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule($context->organization->id);
        $sender = Organization::factory()->verified()->create(['name' => 'Sender Org']);
        $pending = $this->createIncomingDocument($context, $sender, ['amount' => 100, 'remaining_amount' => 100]);
        $this->createIncomingDocument($context, $sender, [
            'amount' => 250,
            'remaining_amount' => 250,
            'recipient_confirmed_at' => now(),
            'recipient_confirmation_comment' => 'Подтверждено',
            'recipient_confirmed_by_user_id' => $context->user->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/payments/incoming/statistics');

        $response->assertOk();
        $response->assertJsonPath('data.total', 2);
        $response->assertJsonPath('data.pending_confirmation', 1);
        $response->assertJsonPath('data.pending_confirmation_amount', 100);
        $response->assertJsonPath('data.confirmed', 1);
        $response->assertJsonPath('data.confirmed_amount', 250);

        $this->assertNull($pending->recipient_confirmed_at);
    }

    private function createIncomingDocument(
        AdminApiTestContext $context,
        Organization $sender,
        array $overrides = []
    ): PaymentDocument {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $sender->id,
            'payer_organization_id' => $sender->id,
            'payee_organization_id' => $context->organization->id,
            'recipient_organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'INC-' . uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'approved_at' => now(),
            'due_date' => now()->addDays(7)->toDateString(),
        ], $overrides));
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
                'permissions' => [
                    'payments.invoice.view',
                    'payments.transaction.approve',
                ],
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
