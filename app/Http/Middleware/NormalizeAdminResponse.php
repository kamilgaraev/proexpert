<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AdminResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NormalizeAdminResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            $response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse
            || !$response instanceof JsonResponse
            || $response->getStatusCode() === Response::HTTP_NO_CONTENT
        ) {
            return $response;
        }

        $payload = $response->getData(true);
        $statusCode = $response->getStatusCode();

        if ($this->isStandardResponse($payload)) {
            return $this->withNoCacheHeaders($this->normalizeStandardPayload($payload, $statusCode));
        }

        if ($statusCode >= Response::HTTP_BAD_REQUEST) {
            return $this->withNoCacheHeaders(AdminResponse::error(
                $this->resolveErrorMessage($payload, $statusCode),
                $statusCode,
                is_array($payload) ? ($payload['errors'] ?? null) : null
            ));
        }

        if ($this->isLegacyPaginator($payload)) {
            return $this->withNoCacheHeaders(AdminResponse::paginated(
                $payload['data'] ?? [],
                $this->extractLegacyMeta($payload),
                $this->extractOptionalMessage($payload),
                $statusCode,
                is_array($payload) ? ($payload['summary'] ?? null) : null,
                $this->extractLegacyLinks($payload)
            ));
        }

        if ($this->hasCollectionMeta($payload)) {
            return $this->withNoCacheHeaders(AdminResponse::paginated(
                $payload['data'] ?? [],
                is_array($payload['meta']) ? $payload['meta'] : [],
                $this->extractOptionalMessage($payload),
                $statusCode,
                is_array($payload) ? ($payload['summary'] ?? null) : null,
                is_array($payload['links'] ?? null) ? $payload['links'] : null
            ));
        }

        return $this->withNoCacheHeaders(AdminResponse::success(
            $this->extractSuccessData($payload),
            $this->extractOptionalMessage($payload),
            $statusCode
        ));
    }

    private function isStandardResponse(mixed $payload): bool
    {
        return is_array($payload)
            && array_key_exists('success', $payload)
            && is_bool($payload['success']);
    }

    private function normalizeStandardPayload(array $payload, int $statusCode): JsonResponse
    {
        if (($payload['success'] ?? false) === false) {
            return AdminResponse::error(
                (string) ($payload['message'] ?? Response::$statusTexts[$statusCode] ?? 'Error'),
                $statusCode,
                $payload['errors'] ?? null
            );
        }

        if (array_key_exists('data', $payload)) {
            if ($this->isLegacyPaginator($payload)) {
                return AdminResponse::paginated(
                    $payload['data'] ?? [],
                    $this->extractLegacyMeta($payload),
                    $this->extractOptionalMessage($payload),
                    $statusCode,
                    is_array($payload['summary'] ?? null) ? $payload['summary'] : null,
                    $this->extractLegacyLinks($payload)
                );
            }

            if ($this->hasCollectionMeta($payload)) {
                return AdminResponse::paginated(
                    $payload['data'] ?? [],
                    is_array($payload['meta']) ? $payload['meta'] : [],
                    $this->extractOptionalMessage($payload),
                    $statusCode,
                    is_array($payload['summary'] ?? null) ? $payload['summary'] : null,
                    is_array($payload['links'] ?? null) ? $payload['links'] : null
                );
            }

            return AdminResponse::success(
                $payload['data'],
                $this->extractOptionalMessage($payload),
                $statusCode
            );
        }

        return AdminResponse::success(
            $this->extractSuccessData($payload),
            $this->extractOptionalMessage($payload),
            $statusCode
        );
    }

    private function isLegacyPaginator(mixed $payload): bool
    {
        return is_array($payload)
            && array_key_exists('data', $payload)
            && array_key_exists('current_page', $payload)
            && array_key_exists('last_page', $payload)
            && array_key_exists('per_page', $payload)
            && array_key_exists('total', $payload);
    }

    private function hasCollectionMeta(mixed $payload): bool
    {
        return is_array($payload)
            && array_key_exists('data', $payload)
            && array_key_exists('meta', $payload)
            && is_array($payload['meta']);
    }

    private function extractOptionalMessage(mixed $payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        $message = $payload['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    private function extractSuccessData(mixed $payload): mixed
    {
        if (!is_array($payload)) {
            return $payload;
        }

        $data = $payload;
        unset($data['success'], $data['message'], $data['error'], $data['errors']);

        if ($data === []) {
            return null;
        }

        return $data;
    }

    private function resolveErrorMessage(mixed $payload, int $statusCode): string
    {
        if (is_array($payload)) {
            $message = $payload['message'] ?? $payload['error'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return Response::$statusTexts[$statusCode] ?? 'Error';
    }

    private function extractLegacyMeta(array $payload): array
    {
        return [
            'current_page' => $payload['current_page'] ?? null,
            'from' => $payload['from'] ?? null,
            'last_page' => $payload['last_page'] ?? null,
            'links' => is_array($payload['links'] ?? null) ? $payload['links'] : [],
            'path' => $payload['path'] ?? null,
            'per_page' => $payload['per_page'] ?? null,
            'to' => $payload['to'] ?? null,
            'total' => $payload['total'] ?? null,
        ];
    }

    private function extractLegacyLinks(array $payload): array
    {
        return [
            'first' => $payload['first_page_url'] ?? null,
            'last' => $payload['last_page_url'] ?? null,
            'prev' => $payload['prev_page_url'] ?? null,
            'next' => $payload['next_page_url'] ?? null,
        ];
    }

    private function withNoCacheHeaders(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
