<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use function trans_message;

class PurchaseRequestService
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly PurchaseRequestNumberGenerator $numberGenerator
    ) {
    }

    public function find(int $id, int $organizationId): ?PurchaseRequest
    {
        return PurchaseRequest::forOrganization($organizationId)
            ->with(['siteRequest.project', 'assignedUser', 'purchaseOrders.supplier'])
            ->find($id);
    }

    public function paginate(
        int $organizationId,
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $query = PurchaseRequest::forOrganization($organizationId)
            ->with(['siteRequest.project', 'assignedUser', 'purchaseOrders']);

        if (isset($filters['status'])) {
            $query->withStatus($filters['status']);
        }

        if (isset($filters['site_request_id'])) {
            $query->where('site_request_id', $filters['site_request_id']);
        }

        if (isset($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($perPage);
    }

    public function createFromSiteRequest(SiteRequest $siteRequest, ?int $assignedTo = null): PurchaseRequest
    {
        $allowedTypes = ['material_request', 'equipment_request', 'personnel_request'];

        if (!in_array($siteRequest->request_type->value, $allowedTypes, true)) {
            throw new \DomainException(trans_message('procurement.purchase_requests.invalid_site_request_type'));
        }

        $existingRequest = $this->findExistingBySiteRequest($siteRequest->organization_id, $siteRequest->id);
        if ($existingRequest) {
            return $existingRequest->fresh(['siteRequest.project', 'assignedUser', 'purchaseOrders.supplier']);
        }

        DB::beginTransaction();

        try {
            $requestNumber = $this->numberGenerator->generate($siteRequest->organization_id);

            $requestTypeLabel = match ($siteRequest->request_type->value) {
                'material_request' => 'заявки на материалы',
                'equipment_request' => 'заявки на технику',
                'personnel_request' => 'заявки на персонал',
                default => 'заявки с объекта',
            };

            $purchaseRequest = PurchaseRequest::create([
                'organization_id' => $siteRequest->organization_id,
                'site_request_id' => $siteRequest->id,
                'assigned_to' => $assignedTo,
                'request_number' => $requestNumber,
                'status' => PurchaseRequestStatusEnum::PENDING,
                'notes' => "Создана из {$requestTypeLabel}: {$siteRequest->title}",
            ]);

            DB::commit();

            $this->invalidateCache($siteRequest->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseRequestCreated($purchaseRequest));

            Log::info('procurement.purchase_request.created', [
                'purchase_request_id' => $purchaseRequest->id,
                'site_request_id' => $siteRequest->id,
                'organization_id' => $siteRequest->organization_id,
            ]);

            return $purchaseRequest->fresh(['siteRequest.project', 'assignedUser', 'purchaseOrders.supplier']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function create(int $organizationId, array $data): PurchaseRequest
    {
        $this->checkLimits($organizationId);

        $siteRequestId = isset($data['site_request_id']) ? (int) $data['site_request_id'] : null;
        if ($siteRequestId && $this->findExistingBySiteRequest($organizationId, $siteRequestId)) {
            throw new \DomainException(trans_message('procurement.purchase_requests.duplicate_site_request'));
        }

        DB::beginTransaction();

        try {
            $requestNumber = $this->numberGenerator->generate($organizationId);

            $purchaseRequest = PurchaseRequest::create([
                'organization_id' => $organizationId,
                'site_request_id' => $siteRequestId,
                'assigned_to' => $data['assigned_to'] ?? null,
                'request_number' => $requestNumber,
                'status' => PurchaseRequestStatusEnum::DRAFT,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            DB::commit();

            $this->invalidateCache($organizationId);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseRequestCreated($purchaseRequest));

            return $purchaseRequest->fresh(['siteRequest.project', 'assignedUser', 'purchaseOrders.supplier']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function approve(PurchaseRequest $request, int $userId): PurchaseRequest
    {
        if (!$request->canBeApproved()) {
            throw new \DomainException(trans_message('procurement.purchase_requests.approve_invalid_status'));
        }

        DB::beginTransaction();

        try {
            $request->update([
                'status' => PurchaseRequestStatusEnum::APPROVED,
            ]);

            DB::commit();

            $this->invalidateCache($request->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseRequestApproved($request, $userId));

            return $request->fresh(['siteRequest.project', 'assignedUser', 'purchaseOrders.supplier']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function reject(PurchaseRequest $request, int $userId, string $reason): PurchaseRequest
    {
        if (!$request->canBeRejected()) {
            throw new \DomainException(trans_message('procurement.purchase_requests.reject_invalid_status'));
        }

        DB::beginTransaction();

        try {
            $request->update([
                'status' => PurchaseRequestStatusEnum::REJECTED,
                'notes' => ($request->notes ? $request->notes . "\n\n" : '') . "Отклонена: {$reason}",
            ]);

            DB::commit();

            $this->invalidateCache($request->organization_id);

            return $request->fresh(['siteRequest.project', 'assignedUser', 'purchaseOrders.supplier']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignToSupplier(PurchaseRequest $request, int $supplierId): PurchaseOrder
    {
        if ($request->status !== PurchaseRequestStatusEnum::APPROVED) {
            throw new \DomainException(trans_message('procurement.purchase_requests.order_requires_approved_request'));
        }

        if ($request->purchaseOrders()->exists()) {
            throw new \DomainException(trans_message('procurement.purchase_requests.order_already_exists'));
        }

        return app(PurchaseOrderService::class)->create($request, $supplierId, []);
    }

    private function checkLimits(int $organizationId): void
    {
        $module = app(\App\BusinessModules\Features\Procurement\ProcurementModule::class);
        $limits = $module->getLimits();

        if ($limits['max_purchase_requests_per_month']) {
            $count = PurchaseRequest::forOrganization($organizationId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            if ($count >= $limits['max_purchase_requests_per_month']) {
                throw new \DomainException(trans_message('procurement.purchase_requests.monthly_limit_reached'));
            }
        }

        if ($limits['max_purchase_orders_per_month']) {
            $ordersCount = PurchaseOrder::forOrganization($organizationId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            if ($ordersCount >= $limits['max_purchase_orders_per_month']) {
                throw new \DomainException(trans_message('procurement.purchase_requests.orders_monthly_limit_reached'));
            }
        }
    }

    private function findExistingBySiteRequest(int $organizationId, int $siteRequestId): ?PurchaseRequest
    {
        return PurchaseRequest::forOrganization($organizationId)
            ->where('site_request_id', $siteRequestId)
            ->first();
    }

    private function invalidateCache(int $organizationId): void
    {
        Cache::forget("procurement_purchase_requests_{$organizationId}");
    }
}
