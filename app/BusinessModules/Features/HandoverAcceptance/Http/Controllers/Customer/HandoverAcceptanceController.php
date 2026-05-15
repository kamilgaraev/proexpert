<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Controllers\Customer;

use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\AcceptanceScopeResource;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\Http\Controllers\Api\V1\Customer\CustomerController;
use App\Http\Responses\CustomerResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class HandoverAcceptanceController extends CustomerController
{
    public function __construct(private readonly HandoverAcceptanceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'handover-acceptance.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $scopes = $this->service->listScopes($organizationId, $request->only(['project_id']))
                ->filter(fn ($scope): bool => $this->canAccessProject($scope->project, $organizationId, $request->user()))
                ->values();

            return CustomerResponse::success(AcceptanceScopeResource::collection($scopes)->resolve());
        } catch (\Throwable $e) {
            Log::error('handover_acceptance.customer.index.error', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('handover_acceptance.errors.action_failed'), 500);
        }
    }

    public function handover(Request $request, int $scope): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'handover-acceptance.customer-sign', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $model = $this->service->findScope($organizationId, $scope);

            if (!$this->canAccessProject($model->project, $organizationId, $request->user())) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            return CustomerResponse::success(new AcceptanceScopeResource(
                $this->service->handoverScope($model, (int) $request->user()?->id)
            ));
        } catch (\DomainException $e) {
            return CustomerResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('handover_acceptance.customer.handover.error', [
                'user_id' => $request->user()?->id,
                'scope_id' => $scope,
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('handover_acceptance.errors.action_failed'), 500);
        }
    }

    public function reject(Request $request, int $scope): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'handover-acceptance.reject', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:1000'],
            ]);
            $model = $this->service->findScope($organizationId, $scope);

            if (!$this->canAccessProject($model->project, $organizationId, $request->user())) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            return CustomerResponse::success(new AcceptanceScopeResource(
                $this->service->reopenScope($model, (int) $request->user()?->id, $validated['reason'])
            ));
        } catch (ValidationException $e) {
            return CustomerResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException $e) {
            return CustomerResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('handover_acceptance.customer.reject.error', [
                'user_id' => $request->user()?->id,
                'scope_id' => $scope,
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('handover_acceptance.errors.action_failed'), 500);
        }
    }
}
