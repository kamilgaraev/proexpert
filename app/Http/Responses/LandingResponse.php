<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class LandingResponse
{
    /**
     * Return a success response for Landing/LK API.
     *
     * @param mixed $data
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    public static function success(mixed $data = null, string $message = null, int $code = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data'    => self::transformData($data),
        ];

        return response()->json($response, $code);
    }

    /**
     * Return an error response for Landing/LK API.
     *
     * @param string $message
     * @param int $code
     * @param mixed $errors
     * @return JsonResponse
     */
    public static function error(string $message, int $code = 400, mixed $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if (!is_null($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Transform data to array if needed.
     *
     * @param mixed $data
     * @return mixed
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
