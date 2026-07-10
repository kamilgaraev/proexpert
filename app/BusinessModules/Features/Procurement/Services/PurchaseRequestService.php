<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

class PurchaseRequestService
{
    private const CACHE_TTL = 3600;

    private const RESOURCE_RELATIONS = [
        'siteRequest.project',
        'assignedUser',
        'lines',
        'supplierRequests.supplier',
        'supplierRequests.externalSupplierContact',
        'supplierRequests.supplierParty',
        'purchaseOrders.supplier',
        'purchaseOrders.externalSupplierContact',
        'purchaseOrders.supplierParty',
    ];

    public function __construct(
        private readonly PurchaseRequestNumberGenerator $numberGenerator,
        private readonly ProjectMaterialDeliveryService $deliveryService
    ) {}

    public function find(int $id, int $organizationId): ?PurchaseRequest
    {
        return PurchaseRequest::forOrganization($organizationId)
            ->with(self::RESOURCE_RELATIONS)
            ->find($id);
    }

    public function paginate(
        int $organizationId,
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $query = PurchaseRequest::forOrganization($organizationId)
            ->with(self::RESOURCE_RELATIONS);

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

    public function createFromSiteRequest(
        SiteRequest $siteRequest,
        int $actorId,
        ?int $assignedTo = null,
        ?float $quantityOverride = null,
        array $metadata = []
    ): PurchaseRequest {
        $siteRequest = $this->resolveSiteRequestForProcurement(
            (int) $siteRequest->organization_id,
            (int) $siteRequest->id,
            $actorId
        );
        $allowedTypes = ['material_request', 'equipment_request', 'personnel_request'];

        if (! in_array($siteRequest->request_type->value, $allowedTypes, true)) {
            throw new \DomainException(trans_message('procurement.purchase_requests.invalid_site_request_type'));
        }

        $existingRequest = $this->findExistingBySiteRequest($siteRequest->organization_id, $siteRequest->id);
        if ($existingRequest) {
            $this->syncDeliveryFromSiteRequest($siteRequest, $existingRequest, $quantityOverride, $metadata);

            return $existingRequest->fresh(self::RESOURCE_RELATIONS);
        }

        DB::beginTransaction();

        try {
            $requestNumber = $this->numberGenerator->generate(
                $siteRequest->organization_id,
                $siteRequest->request_type
            );

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
                'needed_by' => $siteRequest->required_date,
                'metadata' => $metadata !== [] ? $metadata : null,
                'notes' => "Создана из {$requestTypeLabel}: {$siteRequest->title}",
            ]);

            if ($siteRequest->material_name || $siteRequest->material_quantity) {
                $quantity = $quantityOverride ?? (float) ($siteRequest->material_quantity ?: 1);

                $purchaseRequest->lines()->create([
                    'name' => $siteRequest->material_name ?: $siteRequest->title,
                    'quantity' => $quantity,
                    'unit' => $siteRequest->material_unit ?: 'шт',
                    'needed_by' => $siteRequest->required_date,
                    'metadata' => [
                        'source_type' => 'site_request',
                        'source_id' => $siteRequest->id,
                        'requested_quantity' => $quantity,
                    ],
                ]);
            }

            $this->syncDeliveryFromSiteRequest($siteRequest, $purchaseRequest, $quantityOverride, $metadata);

            DB::commit();

            $this->invalidateCache($siteRequest->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseRequestCreated($purchaseRequest));

            Log::info('procurement.purchase_request.created', [
                'purchase_request_id' => $purchaseRequest->id,
                'site_request_id' => $siteRequest->id,
                'organization_id' => $siteRequest->organization_id,
            ]);

            return $purchaseRequest->fresh(self::RESOURCE_RELATIONS);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function create(int $organizationId, int $actorId, array $data): PurchaseRequest
    {
        if ($actorId <= 0) {
            throw new \InvalidArgumentException('Actor ID must be a positive integer.');
        }

        $this->checkLimits($organizationId);

        $siteRequestId = isset($data['site_request_id']) ? (int) $data['site_request_id'] : null;
        $siteRequest = $siteRequestId
            ? $this->resolveSiteRequestForProcurement($organizationId, $siteRequestId, $actorId)
            : null;

        if ($siteRequestId && $this->findExistingBySiteRequest($organizationId, $siteRequestId)) {
            throw new \DomainException(trans_message('procurement.purchase_requests.duplicate_site_request'));
        }

        DB::beginTransaction();

        try {
            $requestNumber = $this->numberGenerator->generate($organizationId, $siteRequest?->request_type);

            $purchaseRequest = PurchaseRequest::create([
                'organization_id' => $organizationId,
                'site_request_id' => $siteRequestId,
                'assigned_to' => $data['assigned_to'] ?? null,
                'request_number' => $requestNumber,
                'status' => PurchaseRequestStatusEnum::PENDING,
                'needed_by' => $data['needed_by'] ?? null,
                'budget_amount' => $data['budget_amount'] ?? null,
                'budget_currency' => $data['budget_currency'] ?? 'RUB',
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($data['lines'] ?? [] as $line) {
                $purchaseRequest->lines()->create([
                    'material_id' => $line['material_id'] ?? null,
                    'name' => $line['name'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'specification' => $line['specification'] ?? null,
                    'needed_by' => $line['needed_by'] ?? $data['needed_by'] ?? null,
                    'metadata' => $line['metadata'] ?? null,
                ]);
            }

            if ($siteRequest) {
                $this->syncDeliveryFromSiteRequest($siteRequest, $purchaseRequest);
            }

            DB::commit();

            $this->invalidateCache($organizationId);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseRequestCreated($purchaseRequest));

            return $purchaseRequest->fresh(self::RESOURCE_RELATIONS);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function approve(PurchaseRequest $request, int $userId): PurchaseRequest
    {
        if (! $request->canBeApproved()) {
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

            return $request->fresh(self::RESOURCE_RELATIONS);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function reject(PurchaseRequest $request, int $userId, string $reason): PurchaseRequest
    {
        if (! $request->canBeRejected()) {
            throw new \DomainException(trans_message('procurement.purchase_requests.reject_invalid_status'));
        }

        DB::beginTransaction();

        try {
            $request->update([
                'status' => PurchaseRequestStatusEnum::REJECTED,
                'notes' => ($request->notes ? $request->notes."\n\n" : '')."Отклонена: {$reason}",
            ]);

            DB::commit();

            $this->invalidateCache($request->organization_id);

            return $request->fresh(self::RESOURCE_RELATIONS);
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

    private function resolveSiteRequestForProcurement(
        int $organizationId,
        int $siteRequestId,
        int $actorId
    ): SiteRequest {
        if ($actorId <= 0) {
            throw new \InvalidArgumentException('Actor ID must be a positive integer.');
        }

        $siteRequest = SiteRequest::forOrganization($organizationId)
            ->visibleToActor($actorId)
            ->where('status', '!=', SiteRequestStatusEnum::DRAFT->value)
            ->find($siteRequestId);

        if (! $siteRequest instanceof SiteRequest) {
            throw new \DomainException(trans_message('procurement.purchase_requests.site_request_unavailable'));
        }

        return $siteRequest;
    }

    private function syncDeliveryFromSiteRequest(
        SiteRequest $siteRequest,
        PurchaseRequest $purchaseRequest,
        ?float $requestedQuantity = null,
        array $metadata = []
    ): void {
        if (! $siteRequest->material_id) {
            return;
        }

        $actor = User::query()->find($purchaseRequest->assigned_to ?? $siteRequest->user_id);

        if (! $actor) {
            return;
        }

        $this->deliveryService->createOrLinkFromSiteRequest(
            $siteRequest,
            $actor,
            $purchaseRequest,
            $requestedQuantity,
            $metadata
        );
    }

    private function invalidateCache(int $organizationId): void
    {
        Cache::forget("procurement_purchase_requests_{$organizationId}");
    }
}
