<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Controllers\Customer;

use App\BusinessModules\Features\ChangeManagement\Http\Resources\ChangeRequestResource;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\CustomerResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ChangeManagementController extends Controller
{
    public function __construct(private readonly ChangeManagementService $service)
    {
    }

    public function changes(Request $request): JsonResponse
    {
        try {
            $paginator = $this->service->paginateChanges(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                array_merge($request->only(['project_id']), ['status' => 'customer_review'])
            );

            return CustomerResponse::success([
                'items' => ChangeRequestResource::collection($paginator->items())->resolve(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'changes.index');
        }
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
            $change = $this->service->findChange((int) $request->attributes->get('current_organization_id'), $id);

            return CustomerResponse::success(
                new ChangeRequestResource($this->service->customerApprove(
                    $change,
                    (int) $request->user()?->id,
                    $validated['comment'] ?? null
                )),
                trans_message('change_management.messages.customer_approved')
            );
        } catch (ValidationException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'changes.approve');
        }
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('change_management.customer_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return CustomerResponse::error(trans_message('change_management.errors.unexpected'), 500);
    }
}
