<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contractor;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class BankStatementImportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_statement_import_registers_payment_for_matched_document(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $context->organization->update(['tax_number' => '7700000000']);
        $this->activatePaymentsModule($context->organization->id);

        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Supplier',
            'inn' => '7800000000',
        ]);

        $document = PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'INV-IMPORT-1',
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'payee_contractor_id' => $contractor->id,
            'contractor_id' => $contractor->id,
            'amount' => 500,
            'paid_amount' => 0,
            'remaining_amount' => 500,
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $file = UploadedFile::fake()->createWithContent('bank-statement.txt', $this->bankStatementContent());

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/payments/import/bank-statement', [
                'file' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.imported', 1);
        $response->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseHas('payment_transactions', [
            'payment_document_id' => $document->id,
            'organization_id' => $context->organization->id,
            'amount' => '500.00',
            'reference_number' => 'BNK-100',
            'payment_method' => PaymentMethod::BANK_TRANSFER->value,
            'status' => PaymentTransactionStatus::COMPLETED->value,
        ]);

        $document->refresh();
        $this->assertSame(PaymentDocumentStatus::PAID, $document->status);
        $this->assertEquals(500.0, (float) $document->paid_amount);
        $this->assertEquals(0.0, (float) $document->remaining_amount);
    }

    public function test_bank_statement_import_reports_unmatched_documents_without_creating_unlinked_transactions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $context->organization->update(['tax_number' => '7700000000']);
        $this->activatePaymentsModule($context->organization->id);

        $file = UploadedFile::fake()->createWithContent('bank-statement.txt', $this->bankStatementContent());

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/payments/import/bank-statement', [
                'file' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.imported', 0);
        $response->assertJsonPath('data.skipped', 0);
        $this->assertNotEmpty($response->json('data.errors'));
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    private function bankStatementContent(): string
    {
        return implode("\n", [
            '1CClientBankExchange',
            'СекцияДокумент=Платежное поручение',
            'Номер=BNK-100',
            'Дата=12.05.2026',
            'Сумма=500,00',
            'ПлательщикИНН=7700000000',
            'ПолучательИНН=7800000000',
            'НазначениеПлатежа=Оплата по счету INV-IMPORT-1',
            'КонецДокумента',
            'КонецФайла',
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
                'permissions' => ['payments.transaction.register'],
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
