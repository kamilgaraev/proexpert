<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers\Mobile;

use App\BusinessModules\Features\Procurement\Http\Resources\MobileProcurementApprovalResource;
use App\BusinessModules\Features\Procurement\Http\Resources\MobilePurchaseOrderResource;
use App\BusinessModules\Features\Procurement\Http\Resources\MobilePurchaseRequestResource;
use App\BusinessModules\Features\Procurement\Services\MobileProcurementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProcurementController extends Controller
{
    public function __construct(
        private readonly MobileProcurementService $service,
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, [
            'procurement.view',
            'procurement.purchase_requests.view',
            'procurement.purchase_orders.view',
            'procurement.approvals.view',
        ])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
            ]);
            $user = $this->mobileUser($request);
            $summary = $this->service->summary(
                $this->organizationId($request),
                $validated,
                $user
            );

            $summary['purchase_requests'] = MobilePurchaseRequestResource::collection($summary['purchase_requests'])->resolve();
            $summary['purchase_orders'] = MobilePurchaseOrderResource::collection($summary['purchase_orders'])->resolve();
            $summary['assigned_approvals'] = MobileProcurementApprovalResource::collection($summary['assigned_approvals'])->resolve();

            return MobileResponse::success($summary);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'summary');
        }
    }

    public function purchaseRequests(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.purchase_requests.view', 'procurement.view'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', Rule::in(MobileProcurementService::REQUEST_STATUSES)],
                'q' => ['nullable', 'string', 'max:120'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $paginator = $this->service->paginatePurchaseRequests(
                $this->organizationId($request),
                $validated,
                min((int) $request->input('per_page', 20), 50)
            );

            return MobileResponse::success([
                'items' => MobilePurchaseRequestResource::collection($paginator->items())->resolve(),
                'meta' => $this->meta($paginator),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'purchase_requests');
        }
    }

    public function purchaseRequest(Request $request, int $purchaseRequest): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.purchase_requests.view', 'procurement.view'])) {
            return $denied;
        }

        try {
            return MobileResponse::success(
                new MobilePurchaseRequestResource(
                    $this->service->findPurchaseRequest($this->organizationId($request), $purchaseRequest)
                )
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'purchase_request');
        }
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.purchase_orders.view', 'procurement.view'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', Rule::in(MobileProcurementService::ORDER_STATUSES)],
                'q' => ['nullable', 'string', 'max:120'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $paginator = $this->service->paginatePurchaseOrders(
                $this->organizationId($request),
                $validated,
                min((int) $request->input('per_page', 20), 50)
            );

            return MobileResponse::success([
                'items' => MobilePurchaseOrderResource::collection($paginator->items())->resolve(),
                'meta' => $this->meta($paginator),
                'warehouses' => $this->service->activeWarehouses($this->organizationId($request)),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'purchase_orders');
        }
    }

    public function purchaseOrder(Request $request, int $purchaseOrder): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.purchase_orders.view', 'procurement.view'])) {
            return $denied;
        }

        try {
            return MobileResponse::success([
                'order' => (new MobilePurchaseOrderResource(
                    $this->service->findPurchaseOrder($this->organizationId($request), $purchaseOrder)
                ))->resolve(),
                'warehouses' => $this->service->activeWarehouses($this->organizationId($request)),
            ]);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'purchase_order');
        }
    }

    public function approvals(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.approvals.view'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'status' => ['nullable', 'string', Rule::in(MobileProcurementService::APPROVAL_STATUSES)],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $paginator = $this->service->paginateApprovals(
                $this->organizationId($request),
                $validated,
                $this->mobileUser($request),
                min((int) $request->input('per_page', 20), 50)
            );

            return MobileResponse::success([
                'items' => MobileProcurementApprovalResource::collection($paginator->items())->resolve(),
                'meta' => $this->meta($paginator),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'approvals');
        }
    }

    public function approve(Request $request, int $approval): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.approvals.resolve'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);
            $updated = $this->service->approveApproval(
                $this->organizationId($request),
                $approval,
                (int) $this->mobileUser($request)->id,
                $validated['comment'] ?? null
            );

            return MobileResponse::success(
                new MobileProcurementApprovalResource($updated),
                trans_message('procurement.mobile.messages.approved')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'approve');
        }
    }

    public function reject(Request $request, int $approval): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.approvals.resolve'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'comment' => ['required', 'string', 'max:2000'],
            ]);
            $updated = $this->service->rejectApproval(
                $this->organizationId($request),
                $approval,
                (int) $this->mobileUser($request)->id,
                $validated['comment']
            );

            return MobileResponse::success(
                new MobileProcurementApprovalResource($updated),
                trans_message('procurement.mobile.messages.rejected')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'reject');
        }
    }

    public function receiveMaterials(Request $request, int $purchaseOrder): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.purchase_orders.receive'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'warehouse_id' => ['required', 'integer'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.item_id' => ['required', 'integer'],
                'items.*.quantity_received' => ['required', 'numeric', 'min:0.001'],
                'items.*.price' => ['required', 'numeric', 'min:0'],
                'receipt_date' => ['required', 'date'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);
            $updated = $this->service->receiveMaterials(
                $this->organizationId($request),
                $purchaseOrder,
                (int) $validated['warehouse_id'],
                $validated['items'],
                (int) $this->mobileUser($request)->id,
                [
                    'receipt_date' => $validated['receipt_date'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]
            );

            return MobileResponse::success(
                new MobilePurchaseOrderResource($updated),
                trans_message('procurement.mobile.messages.materials_received')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'receive_materials');
        }
    }

    public function commentOrder(Request $request, int $purchaseOrder): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['procurement.purchase_orders.comment'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'comment' => ['required', 'string', 'max:2000'],
            ]);
            $updated = $this->service->addOrderComment(
                $this->organizationId($request),
                $purchaseOrder,
                (int) $this->mobileUser($request)->id,
                $validated['comment']
            );

            return MobileResponse::success(
                new MobilePurchaseOrderResource($updated),
                trans_message('procurement.mobile.messages.comment_added')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'comment_order');
        }
    }

    private function ensureAnyPermission(Request $request, array $permissions): ?JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);

        if (!$user || $organizationId <= 0) {
            return $this->permissionDenied();
        }

        foreach ($permissions as $permission) {
            if ($this->authorizationService->can($user, $permission, ['organization_id' => $organizationId])) {
                return null;
            }
        }

        return $this->permissionDenied();
    }

    private function mobileUser(Request $request): User
    {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new DomainException(trans_message('procurement.mobile.errors.permission_denied'));
        }

        return $user;
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules, $this->validationMessages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function validationFailed(ValidationException $exception): JsonResponse
    {
        return MobileResponse::error(
            trans_message('procurement.mobile.errors.validation_failed'),
            422,
            $exception->errors()
        );
    }

    private function permissionDenied(): JsonResponse
    {
        return MobileResponse::error(
            trans_message('procurement.mobile.errors.permission_denied'),
            403,
            null,
            ['error_code' => 'PERMISSION_DENIED']
        );
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('procurement.mobile_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('procurement.mobile.errors.action_failed'), 500);
    }

    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function validationMessages(): array
    {
        return [
            'project_id.integer' => trans_message('procurement.mobile.validation.project_invalid'),
            'status.in' => trans_message('procurement.mobile.validation.status_invalid'),
            'q.max' => trans_message('procurement.mobile.validation.search_too_long'),
            'per_page.max' => trans_message('procurement.mobile.validation.per_page_max'),
            'warehouse_id.required' => trans_message('procurement.mobile.validation.warehouse_required'),
            'warehouse_id.integer' => trans_message('procurement.mobile.validation.warehouse_invalid'),
            'items.required' => trans_message('procurement.mobile.validation.items_required'),
            'items.array' => trans_message('procurement.mobile.validation.items_required'),
            'items.min' => trans_message('procurement.mobile.validation.items_required'),
            'items.*.item_id.required' => trans_message('procurement.mobile.validation.item_required'),
            'items.*.item_id.integer' => trans_message('procurement.mobile.validation.item_invalid'),
            'items.*.quantity_received.required' => trans_message('procurement.mobile.validation.quantity_required'),
            'items.*.quantity_received.numeric' => trans_message('procurement.mobile.validation.quantity_invalid'),
            'items.*.quantity_received.min' => trans_message('procurement.mobile.validation.quantity_invalid'),
            'items.*.price.required' => trans_message('procurement.mobile.validation.price_required'),
            'items.*.price.numeric' => trans_message('procurement.mobile.validation.price_invalid'),
            'items.*.price.min' => trans_message('procurement.mobile.validation.price_invalid'),
            'receipt_date.required' => trans_message('procurement.mobile.validation.receipt_date_required'),
            'receipt_date.date' => trans_message('procurement.mobile.validation.receipt_date_invalid'),
            'comment.required' => trans_message('procurement.mobile.validation.comment_required'),
            'comment.max' => trans_message('procurement.mobile.validation.comment_too_long'),
            'notes.max' => trans_message('procurement.mobile.validation.notes_too_long'),
        ];
    }
}
