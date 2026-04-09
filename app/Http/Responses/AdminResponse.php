<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AdminResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => self::transformData($data),
        ];

        return response()->json($response, $code);
    }

    public static function error(
        string $message,
        int $code = 400,
        mixed $errors = null,
        array $extra = []
    ): JsonResponse
    {
        $statusCode = self::normalizeStatusCode($code, 400);

        $response = [
            'success' => false,
            'message' => $message,
            'error' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        foreach ($extra as $key => $value) {
            if (in_array($key, ['success', 'message', 'error', 'errors'], true)) {
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

        return response()->json($response, $code);
    }

    protected static function transformData(mixed $data): mixed
    {
        if ($data instanceof ResourceCollection) {
            $resolved = $data->response()->getData(true);

            return $resolved['data'] ?? $resolved;
        }

        if ($data instanceof JsonResource) {
            return $data->resolve();
        }

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        return $data;
    }

    protected static function normalizeStatusCode(int $code, int $fallback = 400): int
    {
        return ($code >= 100 && $code < 600) ? $code : $fallback;
    }
}
