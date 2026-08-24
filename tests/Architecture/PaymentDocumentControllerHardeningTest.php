<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\BusinessModules\Core\Payments\Http\Requests\StorePaymentDocumentRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentDocumentControllerHardeningTest extends TestCase
{
    #[Test]
    public function payment_document_controller_stays_http_orchestration_only(): void
    {
        $source = $this->read('app/BusinessModules/Core/Payments/Http/Controllers/PaymentDocumentController.php');

        self::assertStringNotContainsString('->validate(', $source);
        self::assertStringNotContainsString('DB::', $source);
        self::assertStringNotContainsString('PaymentDocument::', $source);
        self::assertStringNotContainsString('Contract::', $source);
        self::assertStringNotContainsString('Rule::exists', $source);
        self::assertStringNotContainsString('response()->json', $source);
    }

    #[Test]
    public function payment_document_index_request_bounds_page_size_and_sorting(): void
    {
        $source = $this->read('app/BusinessModules/Core/Payments/Http/Requests/PaymentDocumentIndexRequest.php');

        self::assertStringContainsString("'per_page' => ['nullable', 'integer', 'min:1', 'max:100']", $source);
        self::assertStringContainsString("'sort_by' => ['nullable', 'string', Rule::in([", $source);
    }

    #[Test]
    public function store_request_scopes_polymorphic_document_references_to_current_organization(): void
    {
        $source = $this->read('app/BusinessModules/Core/Payments/Http/Requests/StorePaymentDocumentRequest.php');

        self::assertStringContainsString('validateScopedMorphReference', $source);
        self::assertStringContainsString("->where('organization_id', \$organizationId)", $source);
        self::assertStringContainsString("->whereHas('contract'", $source);
    }

    #[Test]
    public function payment_document_failures_do_not_log_financial_or_bank_payloads(): void
    {
        $source = $this->read('app/BusinessModules/Core/Payments/Services/PaymentDocumentService.php');

        self::assertStringNotContainsString("'data' => \$data", $source);
        self::assertStringContainsString('paymentDocumentFailureContext', $source);
    }

    #[Test]
    public function store_request_preserves_money_as_a_normalized_decimal_string(): void
    {
        $request = new class extends StorePaymentDocumentRequest
        {
            public function normalize(): void
            {
                $this->prepareForValidation();
            }
        };
        $request->initialize([], [
            'amount' => '9 999 999 999 999,99',
            'vat_rate' => '20,00',
        ]);
        $request->setMethod('POST');

        $request->normalize();

        self::assertSame('9999999999999.99', $request->input('amount'));
        self::assertSame('20.00', $request->input('vat_rate'));
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertIsString($source);

        return $source;
    }
}
