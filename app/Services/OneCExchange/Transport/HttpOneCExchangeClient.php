<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Transport;

use App\Services\OneCExchange\Contracts\OneCExchangeClientInterface;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryPayload;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpOneCExchangeClient implements OneCExchangeClientInterface
{
    public function deliver(OneCExchangeDeliveryPayload $payload): OneCExchangeDeliveryResult
    {
        $endpoint = trim((string) config('one_c_exchange.delivery.endpoint', ''));

        if ($endpoint === '') {
            return new OneCExchangeDeliveryResult(
                accepted: false,
                status: 'failed',
                retryable: true,
                failureType: 'unavailable',
                safeErrorCode: 'transport_unconfigured',
                safeErrorMessage: trans_message('one_c_exchange.safe_errors.transport_unconfigured'),
                externalId: null,
                transportStatus: null,
                rawResponse: [
                    'status' => 'delivery_endpoint_not_configured',
                    'scope' => $payload->scope,
                ],
            );
        }

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('one_c_exchange.delivery.timeout_seconds', 15))
                ->connectTimeout((int) config('one_c_exchange.delivery.connect_timeout_seconds', 5));

            $token = trim((string) config('one_c_exchange.delivery.token', ''));

            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->post($endpoint, $payload->toRequestArray());
        } catch (ConnectionException $exception) {
            return new OneCExchangeDeliveryResult(
                accepted: false,
                status: 'failed',
                retryable: true,
                failureType: 'transport_error',
                safeErrorCode: 'transport_error',
                safeErrorMessage: $exception->getMessage(),
                externalId: null,
                transportStatus: null,
                rawResponse: [
                    'status' => 'connection_failed',
                ],
            );
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [
            'status' => $response->status(),
        ];
        $status = (string) ($body['status'] ?? ($response->successful() ? 'delivered' : 'failed'));
        $safeErrorCode = isset($body['safe_error_code']) ? (string) $body['safe_error_code'] : null;
        $safeErrorMessage = isset($body['safe_error_message']) ? (string) $body['safe_error_message'] : null;

        if ($response->successful()) {
            $accepted = (bool) ($body['accepted'] ?? true);

            return new OneCExchangeDeliveryResult(
                accepted: $accepted,
                status: $status,
                retryable: ! $accepted && (bool) ($body['retryable'] ?? false),
                failureType: $accepted ? null : (isset($body['failure_type']) ? (string) $body['failure_type'] : ($safeErrorCode ?? 'business_validation')),
                safeErrorCode: $safeErrorCode,
                safeErrorMessage: $safeErrorMessage,
                externalId: isset($body['external_id']) ? (string) $body['external_id'] : null,
                transportStatus: $response->status(),
                rawResponse: $body,
            );
        }

        $failureType = $this->failureTypeForStatus($response->status(), $safeErrorCode);

        return new OneCExchangeDeliveryResult(
            accepted: false,
            status: 'failed',
            retryable: $this->isRetryableStatus($response->status()),
            failureType: $failureType,
            safeErrorCode: $safeErrorCode ?? $failureType,
            safeErrorMessage: $safeErrorMessage ?? trans_message('one_c_exchange.exchange_failed'),
            externalId: isset($body['external_id']) ? (string) $body['external_id'] : null,
            transportStatus: $response->status(),
            rawResponse: $body,
        );
    }

    private function failureTypeForStatus(int $status, ?string $safeErrorCode): string
    {
        if ($safeErrorCode !== null && $safeErrorCode !== '') {
            return $safeErrorCode;
        }

        return match (true) {
            $status === 408 => 'timeout',
            $status === 429 => 'rate_limit',
            $status >= 500 => 'server_error',
            $status === 401 || $status === 403 => 'authorization',
            default => 'business_validation',
        };
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }
}
