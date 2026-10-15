<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\CommercialPayment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CommercialPaymentFundsGuardTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('commercial_payments');
        Schema::create('commercial_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('provider_status');
            $table->boolean('reconciliation_required');
            $table->json('safe_response')->nullable();
            $table->timestamps();
        });
    }

    #[DataProvider('paymentStates')]
    public function test_only_proven_unpaid_sandbox_operations_bypass_funds_review(
        string $provider,
        string $status,
        bool $reconciliation,
        ?array $response,
        bool $blocked,
    ): void {
        $payment = CommercialPayment::query()->create([
            'provider' => $provider, 'provider_status' => $status,
            'reconciliation_required' => $reconciliation, 'safe_response' => $response,
        ]);

        $this->assertSame($blocked, CommercialPayment::query()->whereKey($payment->id)->withUnresolvedFunds()->exists());
    }

    public static function paymentStates(): array
    {
        return [
            'sandbox_pending' => ['yookassa', 'pending', true, ['test' => true, 'paid' => false], false],
            'sandbox_canceled' => ['yookassa', 'canceled', true, ['test' => true, 'paid' => false], false],
            'sandbox_succeeded' => ['yookassa', 'succeeded', true, ['test' => true, 'paid' => true], true],
            'sandbox_reserved' => ['yookassa', 'waiting_for_capture', true, ['test' => true, 'paid' => false], true],
            'live_pending_review' => ['yookassa', 'pending', true, ['test' => false, 'paid' => false], true],
            'live_unpaid' => ['yookassa', 'pending', false, ['test' => false, 'paid' => false], false],
            'missing_sandbox_flag' => ['yookassa', 'pending', true, ['paid' => false], true],
            'missing_paid_flag' => ['yookassa', 'pending', true, ['test' => true], true],
            'missing_response' => ['yookassa', 'pending', true, null, true],
            'string_sandbox_flag' => ['yookassa', 'pending', true, ['test' => 'true', 'paid' => false], true],
            'string_paid_flag' => ['yookassa', 'pending', true, ['test' => true, 'paid' => 'false'], true],
            'bank_review' => ['tochka', 'pending', true, ['test' => true, 'paid' => false], true],
        ];
    }
}
