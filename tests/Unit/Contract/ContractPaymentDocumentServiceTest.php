<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest;
use App\Models\Contract;
use App\Services\Contract\ContractPaymentDocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Tests\Support\DatabaseLessTestCase as TestCase;

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
                    && $data['origin_key'] === 'contract-payment:17:retry-17'
                    && ($data['budget_article_id'] ?? null) === 41
                    && ($data['responsibility_center_id'] ?? null) === 52
                    && ($data['created_by_user_id'] ?? null) === 7
                    && ($data['budget_override_reason'] ?? null) === 'Согласованное превышение лимита';
            })
            ->andReturn($document);
        $payments->shouldReceive('registerPayment')
            ->once()
            ->withArgs(static function (PaymentDocument $actualDocument, string $amount, array $data) use ($document): bool {
                return $actualDocument === $document
                    && $amount === '9999999999999.99'
                    && $data['idempotency_key'] === 'retry-17'
                    && ($data['created_by_user_id'] ?? null) === 7
                    && ($data['budget_override_reason'] ?? null) === 'Согласованное превышение лимита';
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
            'budget_article_id' => 41,
            'created_by_user_id' => 7,
            'responsibility_center_id' => 52,
            'budget_override_reason' => 'Согласованное превышение лимита',
        ]);

        $this->assertSame($document, $result);
    }

    public function test_initial_advances_validate_budget_classification_without_changing_contracts_without_advances(): void
    {
        $request = new StoreContractRequest;
        $request->attributes->set('current_organization_id', 5);
        $access = Mockery::mock(\App\Modules\Core\AccessController::class);
        $access->shouldReceive('hasModuleAccess')->with(5, 'budgeting')->andReturn(true, false);
        $this->app->instance(\App\Modules\Core\AccessController::class, $access);
        $rules = array_filter($request->rules(), static fn (string $field): bool => str_starts_with($field, 'advance_payments'), ARRAY_FILTER_USE_KEY);

        $this->assertTrue(Validator::make([], $rules)->passes());
        $missing = Validator::make(['advance_payments' => [['amount' => 5000]]], $rules);
        $this->assertTrue($missing->fails());
        $this->assertArrayHasKey('advance_payments.0.budget_article_id', $missing->errors()->toArray());
        $this->assertArrayHasKey('advance_payments.0.responsibility_center_id', $missing->errors()->toArray());

        foreach ([41, '41', '91b31152-76f3-4d56-a978-9c8f7e09b321'] as $identifier) {
            $this->assertTrue(Validator::make(['advance_payments' => [[
                'amount' => 5000,
                'budget_article_id' => $identifier,
                'responsibility_center_id' => $identifier,
            ]]], $rules)->passes());
        }

        foreach ([[], true, 2.5] as $identifier) {
            $this->assertTrue(Validator::make(['advance_payments' => [[
                'amount' => 5000,
                'budget_article_id' => $identifier,
                'responsibility_center_id' => $identifier,
            ]]], $rules)->fails());
        }
        $inactiveRules = array_filter($request->rules(), static fn (string $field): bool => str_starts_with($field, 'advance_payments'), ARRAY_FILTER_USE_KEY);
        $this->assertTrue(Validator::make(['advance_payments' => [['amount' => 5000]]], $inactiveRules)->passes());
    }
}
