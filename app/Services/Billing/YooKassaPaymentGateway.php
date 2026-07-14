<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
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
        ), true);
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        return $this->result($this->request(
            'GET',
            '/payments/'.rawurlencode($paymentId),
        ));
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        $data = $this->request('GET', '/refunds/'.rawurlencode($refundId))->json();
        $id = trim((string) ($data['id'] ?? ''));
        $paymentId = trim((string) ($data['payment_id'] ?? ''));
        $status = trim((string) ($data['status'] ?? ''));
        $amount = is_array($data['amount'] ?? null) ? $data['amount'] : [];
        $currency = strtoupper(trim((string) ($amount['currency'] ?? '')));

        if ($id === '' || $paymentId === '' || $status === '' || $currency === '') {
            throw new UnexpectedValueException('YooKassa returned an invalid refund response.');
        }

        return new RefundGatewayResult(
            id: $id,
            paymentId: $paymentId,
            status: $status,
            amountMinor: $this->minorAmount($amount['value'] ?? null),
            currency: $currency,
            safeResponse: [
                'id' => $id,
                'payment_id' => $paymentId,
                'status' => $status,
                'amount' => ['value' => (string) ($amount['value'] ?? ''), 'currency' => $currency],
                'created_at' => $data['created_at'] ?? null,
            ],
        );
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

    private function result(Response $response, bool $requiresRedirectConfirmation = false): PaymentGatewayResult
    {
        $data = $response->json();
        $id = trim((string) ($data['id'] ?? ''));
        $status = trim((string) ($data['status'] ?? ''));

        if ($id === '' || $status === '') {
            throw new UnexpectedValueException('YooKassa returned an invalid payment response.');
        }

        $paymentMethod = is_array($data['payment_method'] ?? null) ? $data['payment_method'] : [];
        $amount = is_array($data['amount'] ?? null) ? $data['amount'] : [];
        $refundedAmount = is_array($data['refunded_amount'] ?? null) ? $data['refunded_amount'] : [];
        $currency = strtoupper(trim((string) ($amount['currency'] ?? '')));
        $rawMetadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $metadata = array_intersect_key($rawMetadata, array_flip(['order_id', 'organization_id']));

        if (! is_array($data['amount'] ?? null)
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || ! is_array($data['metadata'] ?? null)
            || ! is_bool($data['paid'] ?? null)
            || ! is_bool($data['test'] ?? null)
            || (($paymentMethod['saved'] ?? false) === true && trim((string) ($paymentMethod['id'] ?? '')) === '')
            || ($refundedAmount !== [] && strtoupper((string) ($refundedAmount['currency'] ?? '')) !== $currency)) {
            throw new UnexpectedValueException('YooKassa returned an invalid payment response.');
        }
        $confirmation = is_array($data['confirmation'] ?? null) ? $data['confirmation'] : [];
        $confirmationUrl = isset($confirmation['confirmation_url'])
            ? trim((string) $confirmation['confirmation_url'])
            : '';

        if ($requiresRedirectConfirmation && ! $this->isValidConfirmationUrl($confirmationUrl)) {
            throw new UnexpectedValueException('YooKassa returned an invalid redirect confirmation URL.');
        }

        return new PaymentGatewayResult(
            id: $id,
            status: $status,
            confirmationUrl: $confirmationUrl !== '' ? $confirmationUrl : null,
            paymentMethodId: isset($paymentMethod['id']) ? (string) $paymentMethod['id'] : null,
            paymentMethodSaved: ($paymentMethod['saved'] ?? false) === true,
            safeResponse: $this->safeResponse($data),
            paid: ($data['paid'] ?? false) === true,
            test: ($data['test'] ?? false) === true,
            amountMinor: $this->minorAmount($amount['value'] ?? null),
            currency: $currency,
            metadata: $metadata,
            refundedAmountMinor: isset($refundedAmount['value'])
                ? $this->minorAmount($refundedAmount['value'])
                : 0,
        );
    }

    private function safeResponse(array $data): array
    {
        return array_filter([
            'id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'paid' => $data['paid'] ?? null,
            'test' => $data['test'] ?? null,
            'amount' => $data['amount'] ?? null,
            'refunded_amount' => $data['refunded_amount'] ?? null,
            'metadata' => isset($data['metadata']) && is_array($data['metadata'])
                ? array_intersect_key($data['metadata'], array_flip(['order_id', 'organization_id']))
                : null,
            'payment_method' => isset($data['payment_method']) ? [
                'id' => $data['payment_method']['id'] ?? null,
                'type' => $data['payment_method']['type'] ?? null,
                'saved' => $data['payment_method']['saved'] ?? false,
            ] : null,
            'created_at' => $data['created_at'] ?? null,
            'captured_at' => $data['captured_at'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function isValidConfirmationUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function money(int $amountMinor): string
    {
        return sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
    }

    private function minorAmount(mixed $value): int
    {
        $amount = trim((string) $value);

        if (preg_match('/^(0|[1-9]\d*)\.(\d{2})$/D', $amount, $matches) !== 1) {
            throw new UnexpectedValueException('YooKassa returned an invalid amount.');
        }

        return ((int) $matches[1] * 100) + (int) $matches[2];
    }
}
