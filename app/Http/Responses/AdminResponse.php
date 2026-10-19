<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Responses\Concerns\NormalizesPayloadResponse;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AdminResponse
{
    use NormalizesPayloadResponse;

    public const NORMALIZATION_ATTRIBUTE = '_admin_response_normalizing';

    public const DECODED_ARRAY_ATTRIBUTE = '_admin_response_decoded_arrays';

    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $code = 200,
        ?array $meta = null
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => self::transformData($data),
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }

    public static function error(
        string $message,
        int $code = 400,
        mixed $errors = null,
        array $extra = []
    ): JsonResponse {
        $statusCode = self::normalizeStatusCode($code, 400);

        $response = [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => $message,
            'code' => is_string($extra['code'] ?? null) ? $extra['code'] : 'http_'.$statusCode,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        foreach ($extra as $key => $value) {
            if (in_array($key, ['success', 'message', 'data', 'error', 'code', 'errors'], true)) {
                continue;
            }

            $response[$key] = $value;
        }

        return response()->json($response, $statusCode);
    }

    public static function paginated(
        mixed $data,
        array $meta,
        ?string $message = null,
        int $code = 200,
        ?array $summary = null,
        ?array $links = null
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => self::transformData($data),
            'meta' => $meta,
        ];

        if ($summary !== null) {
            $response['summary'] = $summary;
        }

        if ($links !== null) {
            $response['links'] = $links;
        }

        return self::measurePIRPhase('list_encode', static fn () => response()->json($response, $code));
    }

    protected static function transformData(mixed $data): mixed
    {
        if (is_array($data)) {
            $request = app()->bound('request') ? app('request') : null;
            if ($request instanceof Request && $request->attributes->get(self::DECODED_ARRAY_ATTRIBUTE) === true) {
                return $data;
            }

            return array_map(static fn (mixed $value): mixed => self::transformData($value), $data);
        }

        if ($data instanceof ResourceCollection) {
            [$resourceResponse, $resolved] = self::measurePIRPhase('request_render', static function () use ($data): array {
                $response = self::measurePIRPhase('design_response_build', static fn () => $data->response());

                return [$response, self::measurePIRPhase('design_response_decode', static fn () => $response->getData(true))];
            });

            if (
                is_array($resolved)
                && (
                    array_key_exists('meta', $resolved)
                    || array_key_exists('links', $resolved)
                    || array_key_exists('summary', $resolved)
                )
            ) {
                return $resourceResponse instanceof JsonResponse && get_class($resourceResponse) === JsonResponse::class
                    ? $resolved
                    : self::transformData($resolved);
            }

            $resolved = $resolved['data'] ?? $resolved;

            return $resourceResponse instanceof JsonResponse && get_class($resourceResponse) === JsonResponse::class
                ? $resolved
                : self::transformData($resolved);
        }

        if ($data instanceof JsonResource) {
            return self::transformData($data->resolve());
        }

        if ($data instanceof Arrayable) {
            return self::transformData($data->toArray());
        }

        return $data;
    }

    protected static function normalizeStatusCode(int $code, int $fallback = 400): int
    {
        return ($code >= 100 && $code < 600) ? $code : $fallback;
    }

    private static function measurePIRPhase(string $phase, callable $operation): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request || ! $request->isMethod('GET') || ! $request->is('api/v1/admin/design-management/packages')
            || $request->attributes->get(self::NORMALIZATION_ATTRIBUTE) === true) {
            return $operation();
        }

        return ApiQueryMetrics::measureProcessingPhase($phase, $operation);
    }
}
