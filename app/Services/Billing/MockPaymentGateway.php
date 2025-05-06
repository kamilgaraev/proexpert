<?php

namespace App\Services\Billing;

use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\User;
use App\Models\SubscriptionPlan;
use App\DataTransferObjects\Billing\PaymentGatewayChargeResponse;
use App\DataTransferObjects\Billing\PaymentGatewaySubscriptionResponse;
use Illuminate\Support\Str;

class MockPaymentGateway implements PaymentGatewayInterface
{
    public function createCharge(
        User $user,
        int $amount, // Ожидаем сумму в минорных единицах (копейках)
        string $currency,
        string $description,
        ?string $paymentMethodId = null,
        array $metadata = [],
        ?string $returnUrl = null
    ): PaymentGatewayChargeResponse {
        // Имитация успешного или неуспешного платежа
        // Можно добавить логику для тестирования разных сценариев, например, на основе $amount или $metadata
        $success = $metadata['force_fail'] ?? false ? false : true; 
        $chargeId = 'mock_charge_' . Str::uuid()->toString();

        if ($success) {
            return new PaymentGatewayChargeResponse(
                success: true,
                chargeId: $chargeId,
                status: 'succeeded',
                message: 'Mock charge successful.',
                redirectUrl: $returnUrl ?? Str::replace('{charge_id}', $chargeId, config('app.url') . '/payment/success?charge_id={charge_id}'),
                gatewaySpecificResponse: ['mock_data' => 'charge_created', 'amount' => $amount, 'currency' => $currency]
            );
        } else {
            return new PaymentGatewayChargeResponse(
                success: false,
                chargeId: $chargeId, // ID может быть и при ошибке
                status: 'failed',
                message: 'Mock charge failed as requested.',
                gatewaySpecificResponse: ['mock_data' => 'charge_failed', 'error_code' => 'MOCK_FAIL_01']
            );
        }
    }

    public function getChargeDetails(string $chargeId): PaymentGatewayChargeResponse
    {
        // Имитация получения деталей
        if (Str::startsWith($chargeId, 'mock_charge_')) {
            // Предположим, что все "mock" платежи были успешны, если не указано иное
            return new PaymentGatewayChargeResponse(
                success: true,
                chargeId: $chargeId,
                status: 'succeeded',
                message: 'Details for mock charge ' . $chargeId,
                gatewaySpecificResponse: ['retrieved_at' => now()->toIso8601String()]
            );
        } else {
             return new PaymentGatewayChargeResponse(
                success: false,
                chargeId: $chargeId,
                status: 'not_found',
                message: 'Mock charge not found.'
            );
        }
    }

    public function createSubscription(
        User $user,
        SubscriptionPlan $plan,
        ?string $paymentMethodId = null,
        array $metadata = []
    ): PaymentGatewaySubscriptionResponse {
        $success = $metadata['force_fail_subscription'] ?? false ? false : true;
        $gatewaySubscriptionId = 'mock_sub_' . Str::uuid()->toString();

        if ($success) {
            return new PaymentGatewaySubscriptionResponse(
                success: true,
                gatewaySubscriptionId: $gatewaySubscriptionId,
                status: 'active', // Или 'trialing' если план предполагает триал и нет paymentMethodId
                message: 'Mock subscription created successfully for plan ' . $plan->name,
                gatewaySpecificResponse: ['plan_id' => $plan->slug, 'user_id' => $user->id]
            );
        } else {
            return new PaymentGatewaySubscriptionResponse(
                success: false,
                gatewaySubscriptionId: null,
                status: 'failed',
                message: 'Mock subscription creation failed for plan ' . $plan->name,
                gatewaySpecificResponse: ['error_code' => 'MOCK_SUB_FAIL_01']
            );
        }
    }

    public function cancelGatewaySubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): bool
    {
        // Имитация отмены
        if (Str::startsWith($gatewaySubscriptionId, 'mock_sub_')) {
            // Log::info("Mock subscription {$gatewaySubscriptionId} cancellation requested (at period end: {$atPeriodEnd})");
            return true; // Всегда успешно для заглушки
        }
        return false;
    }
} 