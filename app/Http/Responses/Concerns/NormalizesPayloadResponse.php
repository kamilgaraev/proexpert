<?php

declare(strict_types=1);

namespace App\Http\Responses\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

trait NormalizesPayloadResponse
{
    public static function fromPayload(
        mixed $payload,
        int $code = 200,
        array $headers = [],
        int $options = 0
    ): JsonResponse {
        [$body, $statusCode] = self::normalizePayload($payload, $code);

        return response()->json($body, $statusCode, $headers, $options);
    }

    protected static function normalizePayload(mixed $payload, int $code): array
    {
        if (self::isErrorPayload($payload, $code)) {
            $body = [
                'success' => false,
                'message' => self::payloadMessage($payload) ?? self::fallbackErrorMessage(),
                'data' => static::transformData(self::payloadData($payload)),
            ];

            $errors = is_array($payload) ? ($payload['errors'] ?? null) : null;
            if ($errors !== null) {
                $body['errors'] = $errors;
            }

            $meta = self::payloadMeta($payload);
            if ($meta !== null) {
                $body['meta'] = $meta;
            }

            return [$body, self::errorStatusCode($code)];
        }

        $body = [
            'success' => true,
            'message' => self::payloadMessage($payload),
            'data' => static::transformData(self::payloadData($payload)),
        ];

        $meta = self::payloadMeta($payload);
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return [$body, self::successStatusCode($code)];
    }

    protected static function isErrorPayload(mixed $payload, int $code): bool
    {
        if ($code >= 400) {
            return true;
        }

        return is_array($payload)
            && (($payload['success'] ?? null) === false || array_key_exists('error', $payload));
    }

    protected static function payloadMessage(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $message = $payload['message'] ?? $payload['error'] ?? null;

        return is_scalar($message) ? (string) $message : null;
    }

    protected static function payloadData(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $extraData = Arr::except($payload, ['success', 'message', 'error', 'errors', 'data', 'meta', 'links', 'summary']);

        if (! array_key_exists('data', $payload)) {
            return $extraData === [] ? null : $extraData;
        }

        if ($extraData === []) {
            return $payload['data'];
        }

        if (is_array($payload['data'])) {
            return array_merge($payload['data'], $extraData);
        }

        return array_merge(['value' => $payload['data']], $extraData);
    }

    protected static function payloadMeta(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        if (isset($payload['links']) && is_array($payload['links'])) {
            $meta['links'] = $payload['links'];
        }

        if (isset($payload['summary']) && is_array($payload['summary'])) {
            $meta['summary'] = $payload['summary'];
        }

        return $meta === [] ? null : $meta;
    }

    protected static function successStatusCode(int $code): int
    {
        return $code >= 200 && $code < 300 && $code !== 204 ? $code : 200;
    }

    protected static function errorStatusCode(int $code): int
    {
        return $code >= 400 && $code < 600 ? $code : 400;
    }

    protected static function fallbackErrorMessage(): string
    {
        return function_exists('trans_message')
            ? trans_message('auth.server_error')
            : 'Произошла ошибка сервера. Попробуйте позже.';
    }
}
