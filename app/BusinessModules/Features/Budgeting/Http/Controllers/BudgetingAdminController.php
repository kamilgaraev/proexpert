<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

abstract class BudgetingAdminController extends Controller
{
    protected function user(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User) {
            throw new RuntimeException(trans_message('budgeting.errors.unauthorized'));
        }

        return $user;
    }

    protected function domainError(DomainException $exception): JsonResponse
    {
        return AdminResponse::error($exception->getMessage(), 422);
    }

    protected function unexpectedError(Throwable $exception, Request $request): JsonResponse
    {
        Log::error('Budgeting admin API failed', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'path' => $request->path(),
            'exception' => $exception,
        ]);

        return AdminResponse::error(trans_message('budgeting.errors.action_failed'), 500);
    }

    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
