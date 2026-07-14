<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\Exceptions\Billing\PaymentGatewayConfigurationException;
use App\Services\Billing\YooKassaPaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

class YooKassaPaymentGatewayTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.yookassa', [
            'shop_id' => 'test-shop',
            'secret_key' => 'test-secret',
            'api_url' => 'https://api.yookassa.ru/v3',
            'return_url' => 'https://example.test/dashboard/billing',
            'timeout' => 10,
            'retry_attempts' => 3,
            'retry_delay_ms' => 0,
        ]);
    }

    public function test_creates_redirect_payment_with_exact_contract_and_idempotence_key(): void
    {
        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response($this->providerResponse(), 200),
        ]);

        $result = app(YooKassaPaymentGateway::class)->createPayment(new CreatePaymentData(
            idempotenceKey: 'order-payment-key',
            amountMinor: 1290000,
            currency: 'RUB',
            description: 'Оплата пакетов МОСТ',
            metadata: ['order_id' => 'order-public-id', 'organization_id' => 42],
            savePaymentMethod: false,
        ));

        $this->assertSame('provider-payment-id', $result->id);
        $this->assertSame('pending', $result->status);
        $this->assertSame('https://yookassa.test/confirmation', $result->confirmationUrl);
        $this->assertFalse($result->paymentMethodSaved);

        Http::assertSent(function (Request $request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            return $request->url() === 'https://api.yookassa.ru/v3/payments'
                && $request->method() === 'POST'
                && $authorization === 'Basic '.base64_encode('test-shop:test-secret')
                && $request->hasHeader('Idempotence-Key', 'order-payment-key')
                && $request['amount'] === ['value' => '12900.00', 'currency' => 'RUB']
                && $request['capture'] === true
                && $request['confirmation'] === [
                    'type' => 'redirect',
                    'return_url' => 'https://example.test/dashboard/billing',
                ]
                && $request['description'] === 'Оплата пакетов МОСТ'
                && $request['metadata'] === ['order_id' => 'order-public-id', 'organization_id' => 42]
                && ! array_key_exists('save_payment_method', $request->data());
        });
    }

    public function test_sends_save_payment_method_only_with_explicit_consent(): void
    {
        Http::fake(['*' => Http::response($this->providerResponse(true), 200)]);

        app(YooKassaPaymentGateway::class)->createPayment(new CreatePaymentData(
            idempotenceKey: 'consented-key',
            amountMinor: 790000,
            currency: 'RUB',
            description: 'Оплата пакета МОСТ',
            metadata: ['order_id' => 'order-id', 'organization_id' => 7],
            savePaymentMethod: true,
        ));

        Http::assertSent(fn (Request $request): bool => $request['save_payment_method'] === true);
    }

    public function test_missing_credentials_fails_before_http_request(): void
    {
        Http::fake();
        config()->set('services.yookassa.shop_id', '');

        $this->expectException(PaymentGatewayConfigurationException::class);

        try {
            app(YooKassaPaymentGateway::class)->createPayment(new CreatePaymentData(
                idempotenceKey: 'missing-config-key',
                amountMinor: 100,
                currency: 'RUB',
                description: 'Проверка',
                metadata: [],
                savePaymentMethod: false,
            ));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_does_not_retry_client_error(): void
    {
        Http::fake(['*' => Http::response(['type' => 'invalid_request'], 400)]);

        try {
            app(YooKassaPaymentGateway::class)->createPayment($this->paymentData('client-error-key'));
            $this->fail('Client error must be propagated.');
        } catch (RequestException $exception) {
            $this->assertSame(400, $exception->response->status());
        }

        Http::assertSentCount(1);
    }

    public function test_retries_transport_and_server_errors_with_same_idempotence_key(): void
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            $attempt++;

            if ($attempt === 1) {
                throw new ConnectionException('network unavailable');
            }

            if ($attempt === 2) {
                return Http::response(['type' => 'server_error'], 500);
            }

            return Http::response($this->providerResponse(), 200);
        });

        $result = app(YooKassaPaymentGateway::class)->createPayment($this->paymentData('stable-retry-key'));

        $this->assertSame('provider-payment-id', $result->id);
        $this->assertSame(3, $attempt);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotence-Key', 'stable-retry-key'));
    }

    public function test_gets_payment_with_basic_auth(): void
    {
        Http::fake([
            'https://api.yookassa.ru/v3/payments/provider-payment-id' => Http::response($this->providerResponse(), 200),
        ]);

        $result = app(YooKassaPaymentGateway::class)->getPayment('provider-payment-id');

        $this->assertSame('provider-payment-id', $result->id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.yookassa.ru/v3/payments/provider-payment-id');
    }

    public function test_rejects_malformed_success_response(): void
    {
        Http::fake(['*' => Http::response(['status' => 'pending'], 200)]);

        $this->expectException(UnexpectedValueException::class);

        app(YooKassaPaymentGateway::class)->createPayment($this->paymentData('malformed-response-key'));
    }

    private function paymentData(string $key): CreatePaymentData
    {
        return new CreatePaymentData(
            idempotenceKey: $key,
            amountMinor: 990000,
            currency: 'RUB',
            description: 'Оплата пакета МОСТ',
            metadata: ['order_id' => 'order-id', 'organization_id' => 1],
            savePaymentMethod: false,
        );
    }

    private function providerResponse(bool $saved = false): array
    {
        return [
            'id' => 'provider-payment-id',
            'status' => 'pending',
            'amount' => ['value' => '12900.00', 'currency' => 'RUB'],
            'description' => 'Оплата пакетов МОСТ',
            'recipient' => ['account_id' => 'test-shop', 'gateway_id' => 'gateway'],
            'created_at' => '2026-07-14T10:00:00.000Z',
            'confirmation' => [
                'type' => 'redirect',
                'confirmation_url' => 'https://yookassa.test/confirmation',
            ],
            'test' => true,
            'paid' => false,
            'refundable' => false,
            'metadata' => ['order_id' => 'order-id', 'organization_id' => 1],
            'payment_method' => [
                'id' => 'method-id',
                'type' => 'bank_card',
                'saved' => $saved,
            ],
        ];
    }
}
