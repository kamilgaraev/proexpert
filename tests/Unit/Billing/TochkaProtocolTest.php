<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Billing\Bank\BankMoney;
use App\Services\Billing\Bank\TochkaClient;
use App\Services\Billing\Bank\TochkaWebhookVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class TochkaProtocolTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_money_uses_exact_kopecks_and_rejects_ambiguous_values(): void
    {
        $this->assertSame(120, BankMoney::minor('1.20'));
        $this->assertSame(10, BankMoney::minor(0.1));
        $this->assertSame('45800.01', BankMoney::decimal(4580001));
        foreach (['1.001', '-1', '1e3', '1,20', '', null, [], '10000000000000'] as $value) {
            try {
                BankMoney::minor($value);
                $this->fail('Invalid amount accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_webhook_verifies_bank_jwk_and_rejects_tampering_algorithm_and_remote_keys(): void
    {
        Cache::forget('billing:tochka:public-key:v1');
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $configuration = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (PHP_OS_FAMILY === 'Windows' && is_file($configuration)) {
            $options['config'] = $configuration;
        }
        $private = openssl_pkey_new($options);
        $this->assertNotFalse($private);
        $rsa = openssl_pkey_get_details($private)['rsa'];
        Http::fake([TochkaWebhookVerifier::PUBLIC_KEY_URL => Http::response([
            'kty' => 'RSA', 'n' => $this->base64($rsa['n']), 'e' => $this->base64($rsa['e']),
        ])]);
        $payload = ['webhookType' => 'incomingPayment', 'paymentId' => 'payment-1', 'date' => '2026-10-15'];
        $sign = function (array $header, array $body) use ($private): string {
            $input = $this->base64(json_encode($header, JSON_THROW_ON_ERROR)).'.'.$this->base64(json_encode($body, JSON_THROW_ON_ERROR));
            openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256);

            return $input.'.'.$this->base64($signature);
        };
        $verifier = new TochkaWebhookVerifier;
        $valid = $sign(['alg' => 'RS256', 'typ' => 'JWT'], $payload);
        $this->assertSame($payload, $verifier->verify($valid));
        $parts = explode('.', $valid);
        $parts[1] = $this->base64(json_encode($payload + ['amount' => '999999'], JSON_THROW_ON_ERROR));
        foreach ([implode('.', $parts), $sign(['alg' => 'none'], $payload),
            $sign(['alg' => 'RS256', 'jku' => 'https://example.test/key'], $payload),
            $sign(['alg' => 'RS256'], $payload + ['exp' => time() - 1]),
            $sign(['alg' => 'RS256'], $payload + ['nbf' => time() + 1000]),
            str_repeat('x', 65_537)] as $invalid) {
            try {
                $verifier->verify($invalid);
                $this->fail('Invalid signature accepted');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('tochka_webhook_invalid', $error->getMessage());
            }
        }
        Http::assertSentCount(1);
    }

    public function test_statement_client_uses_official_paths_and_never_discloses_http_response(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-tochka-protocol-');
        file_put_contents($path, 'test-only-credential');
        try {
            config(['bank_billing.enabled' => true, 'bank_billing.jwt_path' => $path,
                'bank_billing.customer_code' => 'test-customer', 'bank_billing.account_id' => '40802810000000000001/044525104']);
            $client = new TochkaClient;
            Http::fake([
                TochkaClient::BASE_URL.'/open-banking/v1.0/statements' => Http::response(['Data' => ['Statement' => ['accountId' => config('bank_billing.account_id'), 'statementId' => 'statement-1']]]),
                TochkaClient::BASE_URL.'/open-banking/v1.0/accounts/*' => Http::response(['secret' => 'response-sensitive-content'], 401),
            ]);
            $this->assertTrue($client->ready());
            $this->assertSame('statement-1', $client->initializeStatement('2026-10-15'));
            Http::assertSent(fn ($request): bool => $request->method() === 'POST'
                && $request['Data']['Statement']['startDateTime'] === '2026-10-15'
                && $request->hasHeader('Authorization', 'Bearer test-only-credential'));
            try {
                $client->statement('statement-1');
                $this->fail('HTTP failure accepted');
            } catch (RuntimeException $error) {
                $this->assertSame('tochka_http_401', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
            Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/accounts/40802810000000000001/044525104/statements/statement-1'));
        } finally {
            unlink($path);
        }
    }

    public function test_bank_webhook_body_and_headers_are_removed_from_telemetry(): void
    {
        $event = \Sentry\Event::createEvent();
        $event->setRequest(['url' => 'https://api.example.test/api/v1/webhooks/tochka?data=private', 'method' => 'POST',
            'data' => 'signed-webhook-payload', 'headers' => ['Authorization' => 'private'], 'cookies' => ['session' => 'private']]);
        $filtered = \App\Services\Billing\Bank\BankTelemetrySanitizer::sanitize($event);
        $this->assertSame(['url' => 'https://api.example.test/api/v1/webhooks/tochka', 'method' => 'POST'], $filtered->getRequest());
        $other = \Sentry\Event::createEvent();
        $other->setRequest(['url' => 'https://api.example.test/api/v1/other', 'method' => 'GET']);
        $this->assertSame($other, \App\Services\Billing\Bank\BankTelemetrySanitizer::sanitize($other));
    }

    public function test_bank_billing_resolves_without_validating_the_disabled_yookassa_mode(): void
    {
        config(['bank_billing.default_provider' => 'tochka', 'services.yookassa.payments_enabled' => false,
            'services.yookassa.mode' => 'mock']);
        $environment = app()->environment();
        app()->instance('env', 'production');
        app()->forgetInstance(\App\Interfaces\Billing\PaymentGatewayInterface::class);
        try {
            $this->assertInstanceOf(\App\Services\Billing\YooKassaPaymentGateway::class,
                app(\App\Interfaces\Billing\PaymentGatewayInterface::class));
            $this->assertInstanceOf(\App\Services\Billing\CommercialCheckoutService::class,
                app(\App\Services\Billing\CommercialCheckoutService::class));
            $this->expectException(\App\Exceptions\Billing\PaymentGatewayConfigurationException::class);
            app(\App\Services\Billing\CommercialPaymentProviderPolicy::class)->assertCanCreatePayment();
        } finally {
            app()->instance('env', $environment);
            app()->forgetInstance(\App\Interfaces\Billing\PaymentGatewayInterface::class);
        }
    }

    private function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
