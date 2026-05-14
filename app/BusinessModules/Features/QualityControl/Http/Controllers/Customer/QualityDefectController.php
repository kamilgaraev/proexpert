<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Controllers\Customer;

use App\BusinessModules\Features\QualityControl\Http\Resources\QualityDefectResource;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Http\Controllers\Api\V1\Customer\CustomerController;
use App\Http\Responses\CustomerResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class QualityDefectController extends CustomerController
{
    public function __construct(
        private readonly QualityDefectService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'quality-control.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $filters = $request->only([
                'status',
                'project_id',
                'severity',
                'overdue',
                'sort_by',
                'sort_dir',
            ]);
            $defects = $this->service->paginate($organizationId, $perPage, $filters);

            return CustomerResponse::success(
                QualityDefectResource::collection($defects->getCollection())->resolve(),
                trans_message('quality_control.messages.loaded')
            );
        } catch (\Throwable $e) {
            Log::error('quality_control.customer.defects.index.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('quality_control.errors.index_failed'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'quality-control.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $defect = $this->service->find($id, $organizationId);

            if ($defect === null) {
                return CustomerResponse::error(trans_message('quality_control.errors.not_found'), 404);
            }

            return CustomerResponse::success(
                new QualityDefectResource($defect),
                trans_message('quality_control.messages.loaded')
            );
        } catch (\Throwable $e) {
            Log::error('quality_control.customer.defects.show.error', [
                'id' => $id,
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('quality_control.errors.show_failed'), 500);
        }
    }
}
