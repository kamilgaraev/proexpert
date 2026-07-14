<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\Exceptions\Billing\PaymentGatewayConfigurationException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class YooKassaPaymentGateway implements PaymentGatewayInterface
{
    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        $payload = [
            'amount' => [
                'value' => $this->money($payment->amountMinor),
                'currency' => $payment->currency,
            ],
            'capture' => true,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $this->returnUrl(),
            ],
            'description' => $payment->description,
            'metadata' => $payment->metadata,
        ];

        if ($payment->savePaymentMethod) {
            $payload['save_payment_method'] = true;
        }

        return $this->result($this->request(
            'POST',
            '/payments',
            $payload,
            $payment->idempotenceKey,
        ));
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        return $this->result($this->request(
            'GET',
            '/payments/'.rawurlencode($paymentId),
        ));
    }

    private function request(
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotenceKey = null,
    ): Response {
        [$shopId, $secretKey] = $this->credentials();
        $attempts = max(1, (int) config('services.yookassa.retry_attempts', 3));
        $delayMilliseconds = max(0, (int) config('services.yookassa.retry_delay_ms', 150));
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $request = Http::acceptJson()
                    ->asJson()
                    ->withBasicAuth($shopId, $secretKey)
                    ->timeout(max(1, (int) config('services.yookassa.timeout', 10)));

                if ($idempotenceKey !== null) {
                    $request = $request->withHeader('Idempotence-Key', $idempotenceKey);
                }

                $response = $method === 'POST'
                    ? $request->post($this->url($path), $payload)
                    : $request->get($this->url($path));
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    throw $exception;
                }

                $this->pause($delayMilliseconds);

                continue;
            }

            if (! $this->isRetryable($response) || $attempt === $attempts) {
                return $response->throw();
            }

            $this->pause($delayMilliseconds);
        }

        throw new PaymentGatewayConfigurationException('YooKassa request did not produce a response.');
    }

    private function credentials(): array
    {
        $shopId = trim((string) config('services.yookassa.shop_id'));
        $secretKey = trim((string) config('services.yookassa.secret_key'));

        if ($shopId === '' || $secretKey === '') {
            throw new PaymentGatewayConfigurationException('YooKassa credentials are not configured.');
        }

        return [$shopId, $secretKey];
    }

    private function returnUrl(): string
    {
        $returnUrl = trim((string) config('services.yookassa.return_url'));

        if ($returnUrl === '') {
            throw new PaymentGatewayConfigurationException('YooKassa return URL is not configured.');
        }

        return $returnUrl;
    }

    private function url(string $path): string
    {
        $baseUrl = rtrim((string) config('services.yookassa.api_url', 'https://api.yookassa.ru/v3'), '/');

        return $baseUrl.$path;
    }

    private function isRetryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function result(Response $response): PaymentGatewayResult
    {
        $data = $response->json();
        $id = trim((string) ($data['id'] ?? ''));
        $status = trim((string) ($data['status'] ?? ''));

        if ($id === '' || $status === '') {
            throw new UnexpectedValueException('YooKassa returned an invalid payment response.');
        }

        $paymentMethod = is_array($data['payment_method'] ?? null) ? $data['payment_method'] : [];
        $confirmation = is_array($data['confirmation'] ?? null) ? $data['confirmation'] : [];

        return new PaymentGatewayResult(
            id: $id,
            status: $status,
            confirmationUrl: isset($confirmation['confirmation_url'])
                ? (string) $confirmation['confirmation_url']
                : null,
            paymentMethodId: isset($paymentMethod['id']) ? (string) $paymentMethod['id'] : null,
            paymentMethodSaved: ($paymentMethod['saved'] ?? false) === true,
            safeResponse: $this->safeResponse($data),
        );
    }

    private function safeResponse(array $data): array
    {
        unset($data['recipient']);

        return $data;
    }

    private function money(int $amountMinor): string
    {
        return sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
    }
}
