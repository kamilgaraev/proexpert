<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\NormalizesPayloadResponse;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MobileResponse
{
    use NormalizesPayloadResponse;

    /**
     * Return a success response for Mobile API.
     */
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

    /**
     * Return an error response for Mobile API.
     */
    public static function error(
        string $message,
        int $code = 400,
        mixed $errors = null,
        array $extra = []
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
            'data' => null,
            'code' => is_string($extra['code'] ?? null) ? $extra['code'] : 'http_'.$code,
        ];

        if (! is_null($errors)) {
            $response['errors'] = $errors;
        }

        foreach ($extra as $key => $value) {
            if (in_array($key, ['success', 'message', 'data', 'code', 'errors'], true)) {
                continue;
            }

            $response[$key] = $value;
        }

        return response()->json($response, $code);
    }

    /**
     * Transform data to array if needed.
     */
    protected static function transformData(mixed $data): mixed
    {
        if ($data instanceof ResourceCollection) {
            return $data->response()->getData(true);
        }

        if ($data instanceof JsonResource) {
            return $data->resolve();
        }

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        return $data;
    }
}
