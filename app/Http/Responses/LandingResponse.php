<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\NormalizesPayloadResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class LandingResponse
{
    use NormalizesPayloadResponse;

    /**
     * Return a success response for Landing/LK API.
     *
     * @param mixed $data
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $code = 200,
        ?array $meta = null
    ): JsonResponse
    {
        [$resolvedData, $resourceMeta] = self::transformDataWithMeta($data);

        $response = [
            'success' => true,
            'message' => $message,
            'data'    => $resolvedData,
        ];

        $meta = self::mergeMeta($resourceMeta, $meta);

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

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
    public static function error(
        string $message,
        int $code = 400,
        mixed $errors = null,
        array $extra = []
    ): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'data' => null,
        ];

        if (!is_null($errors)) {
            $response['errors'] = $errors;
        }

        foreach ($extra as $key => $value) {
            if (in_array($key, ['success', 'message', 'errors'], true)) {
                continue;
            }

            $response[$key] = $value;
        }

        return response()->json($response, $code);
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

    /**
     * Transform data to array if needed.
     *
     * @param mixed $data
     * @return mixed
     */
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

    protected static function transformDataWithMeta(mixed $data): array
    {
        if (! $data instanceof ResourceCollection) {
            return [self::transformData($data), null];
        }

        $resolved = $data->response()->getData(true);
        $meta = [];

        if (isset($resolved['meta']) && is_array($resolved['meta'])) {
            $meta = $resolved['meta'];
        }

        if (isset($resolved['links']) && is_array($resolved['links'])) {
            $meta['links'] = $resolved['links'];
        }

        return [$resolved['data'] ?? [], $meta === [] ? null : $meta];
    }

    protected static function mergeMeta(?array $resourceMeta, ?array $explicitMeta): ?array
    {
        if ($resourceMeta === null) {
            return $explicitMeta;
        }

        if ($explicitMeta === null) {
            return $resourceMeta;
        }

        return array_replace_recursive($resourceMeta, $explicitMeta);
    }
}
