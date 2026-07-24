<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

abstract class MdmBaseController extends Controller
{
    protected function organizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id);
    }

    protected function paginated(LengthAwarePaginator $paginator, ?array $meta = null): JsonResponse
    {
        return AdminResponse::paginated($paginator->items(), [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ], null, 200, $meta);
    }

    protected function handle(Request $request, string $logMessage, string $fallbackTranslationKey, callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                return $this->validationError($exception);
            }

            if ($exception instanceof HttpExceptionInterface) {
                return AdminResponse::error(
                    trans_message('mdm.errors.not_found'),
                    $exception->getStatusCode()
                );
            }

            Log::error($logMessage, [
                'error' => $exception->getMessage(),
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
            ]);

            return AdminResponse::error(trans_message($fallbackTranslationKey), 500);
        }
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $message = collect($errors)->flatten()->first() ?: trans_message('user.validation_failed');

        return AdminResponse::error((string) $message, Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }
}
