<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CustomerResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => self::transformData($data),
        ], $code);
    }

    public static function error(
        string $message,
        int $code = 400,
        mixed $errors = null,
        array $extra = []
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        foreach ($extra as $key => $value) {
            if (\in_array($key, ['success', 'message', 'errors'], true)) {
                continue;
            }

            $response[$key] = $value;
        }

        return response()->json($response, $code);
    }

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
