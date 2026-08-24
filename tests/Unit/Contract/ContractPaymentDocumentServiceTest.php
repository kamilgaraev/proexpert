<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Contract;
use App\Services\Contract\ContractPaymentDocumentService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class ContractPaymentDocumentServiceTest extends TestCase
{
    public function test_paid_contract_payment_uses_canonical_atomic_idempotent_registration_without_float_loss(): void
    {
        $contract = new Contract;
        $contract->forceFill([
            'id' => 17,
            'organization_id' => 5,
            'project_id' => 9,
            'contractor_id' => 11,
            'number' => 'C-17',
        ]);
        $document = new PaymentDocument;
        $document->forceFill([
            'id' => 31,
            'organization_id' => 5,
            'amount' => '9999999999999.99',
        ]);
        $payments = Mockery::mock(PaymentDocumentService::class);
        $payments->shouldReceive('createFromContract')
            ->once()
            ->withArgs(static function (Contract $actualContract, $invoiceType, array $data) use ($contract): bool {
                return $actualContract === $contract
                    && $data['amount'] === '9999999999999.99'
                    && $data['origin_key'] === 'contract-payment:17:retry-17';
            })
            ->andReturn($document);
        $payments->shouldReceive('registerPayment')
            ->once()
            ->withArgs(static function (PaymentDocument $actualDocument, string $amount, array $data) use ($document): bool {
                return $actualDocument === $document
                    && $amount === '9999999999999.99'
                    && $data['idempotency_key'] === 'retry-17';
            })
            ->andReturn($document);
        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback());

        $result = (new ContractPaymentDocumentService($payments))->createPaidContractPayment($contract, [
            'amount' => '9999999999999.99',
            'payment_type' => 'advance',
            'idempotency_key' => 'retry-17',
            'payment_date' => '2026-08-23',
        ]);

        $this->assertSame($document, $result);
    }
}
