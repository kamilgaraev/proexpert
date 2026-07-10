<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Services\PurchaseRequestService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

use function trans_message;

class SiteRequestFulfillmentService
{
    private const EPSILON = 0.0001;

    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly ProjectMaterialDeliveryService $deliveryService,
        private readonly PurchaseRequestService $purchaseRequestService,
        private readonly AuthorizationService $authorizationService,
        private readonly AccessController $accessController
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function options(SiteRequest $siteRequest, User $actor): array
    {
        $this->assertMaterialRequest($siteRequest);

        $organizationId = (int) $siteRequest->organization_id;
        $requestedQuantity = $this->requestedQuantity($siteRequest);
        $isManualMaterial = $this->isManualMaterial($siteRequest);
        $warehouses = $isManualMaterial ? [] : $this->warehouseOptions($siteRequest, $requestedQuantity);
        $totalAvailable = array_sum(array_column($warehouses, 'available_quantity'));
        $canUseWarehouse = ! $isManualMaterial
            && $this->can($actor, 'warehouse.manage_stock', $organizationId)
            && $this->accessController->hasModuleAccess($organizationId, 'basic-warehouse');
        $canCreatePurchase = $this->can($actor, 'procurement.purchase_requests.create', $organizationId)
            && $this->accessController->hasModuleAccess($organizationId, 'procurement');
        $recommendedSource = $isManualMaterial
            ? 'purchase'
            : $this->recommendedSource($requestedQuantity, (float) $totalAvailable);

        return [
            'material_source' => $isManualMaterial ? 'manual' : 'catalog',
            'warehouse_lookup_supported' => ! $isManualMaterial,
            'warehouse_unavailable_reason' => $isManualMaterial
                ? trans_message('site_requests.fulfillment.manual_purchase_only')
                : null,
            'can_use_warehouse' => $canUseWarehouse,
            'can_use_purchase' => $canCreatePurchase,
            'can_use_mixed' => $canUseWarehouse && $canCreatePurchase,
            'recommended_source' => $recommendedSource,
            'request' => [
                'id' => $siteRequest->id,
                'material_id' => $siteRequest->material_id,
                'material_name' => $siteRequest->material_name,
                'requested_quantity' => $requestedQuantity,
                'unit' => $siteRequest->material_unit,
                'status' => $siteRequest->status->value,
            ],
            'decision' => $this->currentDecision($siteRequest),
            'warehouses' => $warehouses,
            'summary' => [
                'total_available_quantity' => round((float) $totalAvailable, 3),
                'missing_quantity' => round(max(0.0, $requestedQuantity - (float) $totalAvailable), 3),
                'recommended_source' => $recommendedSource,
            ],
            'permissions' => [
                'can_use_warehouse' => $canUseWarehouse,
                'can_create_purchase_request' => $canCreatePurchase,
                'can_use_mixed' => $canUseWarehouse && $canCreatePurchase,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function decide(SiteRequest $siteRequest, User $actor, array $data): array
    {
        $this->assertMaterialRequest($siteRequest);

        return DB::transaction(function () use ($siteRequest, $actor, $data): array {
            $locked = SiteRequest::query()
                ->where('organization_id', $siteRequest->organization_id)
                ->lockForUpdate()
                ->findOrFail($siteRequest->id);

            $this->assertMaterialRequest($locked);

            $source = (string) $data['source'];
            if (in_array($source, ['purchase', 'mixed'], true)) {
                $this->assertProcurementModuleActive($locked);
            }
            $existingDecision = $this->currentDecision($locked);
            if ($existingDecision !== null) {
                $this->assertExistingDecisionPurchaseRequestMatches($locked, $existingDecision);

                return $this->result($locked->fresh($this->siteRequestRelations()), $existingDecision);
            }

            $requestedQuantity = $this->requestedQuantity($locked);
            $warehouseQuantity = isset($data['warehouse_quantity']) ? (float) $data['warehouse_quantity'] : 0.0;
            $purchaseQuantity = isset($data['purchase_quantity']) ? (float) $data['purchase_quantity'] : 0.0;
            $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
            $notes = isset($data['notes']) ? (string) $data['notes'] : null;

            $this->assertSourceSupportsMaterial($source, $locked);
            $this->assertDecisionQuantities($source, $requestedQuantity, $warehouseQuantity, $purchaseQuantity);

            $warehouseDelivery = null;
            $purchaseRequest = null;
            $reservation = null;

            if (in_array($source, ['warehouse', 'mixed'], true)) {
                $this->authorize($actor, 'warehouse.manage_stock', (int) $locked->organization_id);
                $reservation = $this->reserveWarehouseQuantity($locked, $actor, $warehouseId, $warehouseQuantity);
                $warehouseDelivery = $this->deliveryService->createOrLinkWarehouseFromSiteRequest(
                    $locked,
                    $actor,
                    $warehouseId,
                    $warehouseQuantity,
                    isset($reservation['reservation_id']) ? (int) $reservation['reservation_id'] : null,
                    $notes
                );
            }

            if (in_array($source, ['purchase', 'mixed'], true)) {
                $this->authorize($actor, 'procurement.purchase_requests.create', (int) $locked->organization_id);
                $quantity = $source === 'purchase' ? $requestedQuantity : $purchaseQuantity;
                $this->assertExistingPurchaseRequestMatches($locked, $quantity);
                $purchaseRequest = $this->purchaseRequestService->createFromSiteRequest(
                    $locked,
                    (int) $actor->id,
                    $actor->id,
                    $quantity,
                    [
                        'fulfillment_source' => $source,
                        'site_request_id' => $locked->id,
                    ]
                );
            }

            $decision = [
                'source' => $source,
                'warehouse_id' => $warehouseId,
                'warehouse_quantity' => $warehouseQuantity > 0 ? round($warehouseQuantity, 3) : null,
                'purchase_quantity' => in_array($source, ['purchase', 'mixed'], true)
                    ? round($source === 'purchase' ? $requestedQuantity : $purchaseQuantity, 3)
                    : null,
                'warehouse_delivery_id' => $warehouseDelivery?->id,
                'purchase_request_id' => $purchaseRequest?->id,
                'reservation_id' => $reservation['reservation_id'] ?? null,
                'decided_by' => $actor->id,
                'decided_at' => now()->toDateTimeString(),
                'notes' => $notes,
            ];

            $locked->forceFill([
                'metadata' => array_merge($locked->metadata ?? [], [
                    'fulfillment_decision' => $decision,
                ]),
            ])->save();

            return $this->result($locked->fresh($this->siteRequestRelations()), $decision);
        });
    }

    /**
     * @return array<int, string>
     */
    public function siteRequestRelations(): array
    {
        return [
            'project',
            'user',
            'assignedUser',
            'files',
            'materialDeliveries.latestEvent',
            'purchaseRequests.assignedUser',
            'purchaseRequests.purchaseOrders.supplier',
            'purchaseOrders.supplier',
        ];
    }

    private function assertMaterialRequest(SiteRequest $siteRequest): void
    {
        if ($siteRequest->request_type !== SiteRequestTypeEnum::MATERIAL_REQUEST) {
            throw new DomainException(trans_message('site_requests.fulfillment.errors.material_only'));
        }

        if ($siteRequest->status !== SiteRequestStatusEnum::APPROVED) {
            throw new DomainException(trans_message('site_requests.fulfillment.errors.approved_required'));
        }

        if (
            (! $siteRequest->material_id && ! $this->hasManualMaterialName($siteRequest))
            || $this->requestedQuantity($siteRequest) <= 0
        ) {
            throw new DomainException(trans_message('site_requests.fulfillment.errors.material_required'));
        }
    }

    private function assertSourceSupportsMaterial(string $source, SiteRequest $siteRequest): void
    {
        if ($this->isManualMaterial($siteRequest) && in_array($source, ['warehouse', 'mixed'], true)) {
            throw new DomainException(trans_message('site_requests.fulfillment.manual_purchase_only'));
        }
    }

    private function isManualMaterial(SiteRequest $siteRequest): bool
    {
        return ! $siteRequest->material_id && $this->hasManualMaterialName($siteRequest);
    }

    private function hasManualMaterialName(SiteRequest $siteRequest): bool
    {
        return trim((string) $siteRequest->material_name) !== '';
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function assertExistingDecisionPurchaseRequestMatches(SiteRequest $siteRequest, array $decision): void
    {
        if (! in_array($decision['source'] ?? null, ['purchase', 'mixed'], true)) {
            return;
        }

        $purchaseQuantity = isset($decision['purchase_quantity'])
            ? (float) $decision['purchase_quantity']
            : $this->requestedQuantity($siteRequest);

        $this->assertExistingPurchaseRequestMatches($siteRequest, $purchaseQuantity, true);
    }

    private function assertExistingPurchaseRequestMatches(
        SiteRequest $siteRequest,
        float $requestedQuantity,
        bool $required = false
    ): void {
        $purchaseRequest = PurchaseRequest::query()
            ->where('organization_id', $siteRequest->organization_id)
            ->where('site_request_id', $siteRequest->id)
            ->with('lines')
            ->first();

        if (! $purchaseRequest instanceof PurchaseRequest) {
            if ($required) {
                throw new ConflictHttpException(trans_message('site_requests.fulfillment.errors.purchase_request_mismatch'));
            }

            return;
        }

        $hasMatchingLine = $purchaseRequest->lines->contains(
            fn ($line): bool => $this->lineMatchesSiteRequest($siteRequest, $line, $requestedQuantity)
        );

        if (! $hasMatchingLine) {
            throw new ConflictHttpException(trans_message('site_requests.fulfillment.errors.purchase_request_mismatch'));
        }
    }

    private function lineMatchesSiteRequest(
        SiteRequest $siteRequest,
        PurchaseRequestLine $line,
        float $requestedQuantity
    ): bool
    {
        if (abs((float) $line->quantity - $requestedQuantity) > self::EPSILON) {
            return false;
        }

        if ($this->normalizeMaterialValue((string) $line->unit) !== $this->normalizeMaterialValue((string) $siteRequest->material_unit)) {
            return false;
        }

        if ($siteRequest->material_id) {
            return (int) $line->material_id === (int) $siteRequest->material_id;
        }

        return $this->normalizeMaterialValue((string) $line->name)
            === $this->normalizeMaterialValue((string) $siteRequest->material_name);
    }

    private function normalizeMaterialValue(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return mb_strtolower($normalized ?? '', 'UTF-8');
    }

    private function assertProcurementModuleActive(SiteRequest $siteRequest): void
    {
        if (! $this->accessController->hasModuleAccess((int) $siteRequest->organization_id, 'procurement')) {
            throw new DomainException(trans_message('site_requests.fulfillment.errors.procurement_module_required'));
        }
    }

    private function requestedQuantity(SiteRequest $siteRequest): float
    {
        return round((float) ($siteRequest->material_quantity ?? 0), 3);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function warehouseOptions(SiteRequest $siteRequest, float $requestedQuantity): array
    {
        if (! $siteRequest->material_id) {
            return [];
        }

        return $this->warehouseService
            ->getWarehouses((int) $siteRequest->organization_id)
            ->map(function (OrganizationWarehouse $warehouse) use ($siteRequest, $requestedQuantity): array {
                $balance = $this->warehouseService->getAssetBalance(
                    (int) $siteRequest->organization_id,
                    (int) $warehouse->id,
                    (int) $siteRequest->material_id
                );
                $available = $balance ? (float) $balance->availableQuantity : 0.0;

                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'type' => $warehouse->warehouse_type,
                    'is_main' => (bool) $warehouse->is_main,
                    'available_quantity' => round($available, 3),
                    'reserved_quantity' => $balance ? round((float) $balance->reservedQuantity, 3) : 0.0,
                    'can_cover_full_request' => $available + self::EPSILON >= $requestedQuantity,
                ];
            })
            ->filter(static fn (array $warehouse): bool => (float) $warehouse['available_quantity'] > 0)
            ->values()
            ->all();
    }

    private function recommendedSource(float $requestedQuantity, float $totalAvailable): string
    {
        if ($totalAvailable + self::EPSILON >= $requestedQuantity) {
            return 'warehouse';
        }

        if ($totalAvailable > self::EPSILON) {
            return 'mixed';
        }

        return 'purchase';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentDecision(SiteRequest $siteRequest): ?array
    {
        $metadata = $siteRequest->metadata ?? [];
        $decision = $metadata['fulfillment_decision'] ?? null;

        return is_array($decision) ? $decision : null;
    }

    private function assertDecisionQuantities(
        string $source,
        float $requestedQuantity,
        float $warehouseQuantity,
        float $purchaseQuantity
    ): void {
        if ($source === 'warehouse' && abs($warehouseQuantity - $requestedQuantity) > self::EPSILON) {
            throw new DomainException(trans_message('site_requests.fulfillment.errors.warehouse_full_quantity_required'));
        }

        if ($source === 'purchase' && $purchaseQuantity > self::EPSILON && abs($purchaseQuantity - $requestedQuantity) > self::EPSILON) {
            throw new DomainException(trans_message('site_requests.fulfillment.errors.purchase_full_quantity_required'));
        }

        if ($source === 'mixed') {
            if ($warehouseQuantity <= self::EPSILON || $purchaseQuantity <= self::EPSILON) {
                throw new DomainException(trans_message('site_requests.fulfillment.errors.mixed_quantities_required'));
            }

            if (abs(($warehouseQuantity + $purchaseQuantity) - $requestedQuantity) > self::EPSILON) {
                throw new DomainException(trans_message('site_requests.fulfillment.errors.quantity_mismatch'));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reserveWarehouseQuantity(
        SiteRequest $siteRequest,
        User $actor,
        ?int $warehouseId,
        float $quantity
    ): array {
        if ($warehouseId === null) {
            throw new DomainException(trans_message('site_requests.fulfillment.errors.warehouse_required'));
        }

        $warehouse = OrganizationWarehouse::query()
            ->where('organization_id', $siteRequest->organization_id)
            ->where('is_active', true)
            ->find($warehouseId);

        if (! $warehouse instanceof OrganizationWarehouse) {
            throw new DomainException(trans_message('site_requests.fulfillment.errors.warehouse_not_found'));
        }

        $balance = $this->warehouseService->getAssetBalance(
            (int) $siteRequest->organization_id,
            $warehouseId,
            (int) $siteRequest->material_id
        );

        if (! $balance || (float) $balance->availableQuantity + self::EPSILON < $quantity) {
            throw new ConflictHttpException(trans_message('site_requests.fulfillment.errors.stock_changed'));
        }

        try {
            return $this->warehouseService->reserveAssets(
                (int) $siteRequest->organization_id,
                $warehouseId,
                (int) $siteRequest->material_id,
                $quantity,
                [
                    'project_id' => $siteRequest->project_id,
                    'user_id' => $actor->id,
                    'reason' => trans_message('site_requests.fulfillment.reservation_reason', ['id' => $siteRequest->id]),
                    'site_request_id' => $siteRequest->id,
                    'fulfillment_source' => 'site_request',
                ]
            );
        } catch (\Throwable) {
            throw new ConflictHttpException(trans_message('site_requests.fulfillment.errors.stock_changed'));
        }
    }

    private function authorize(User $actor, string $permission, int $organizationId): void
    {
        if (! $this->can($actor, $permission, $organizationId)) {
            throw new AuthorizationException(trans_message('errors.unauthorized'));
        }
    }

    private function can(User $actor, string $permission, int $organizationId): bool
    {
        return $this->authorizationService->can($actor, $permission, [
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function result(SiteRequest $siteRequest, array $decision): array
    {
        return [
            'site_request' => $siteRequest,
            'decision' => $decision,
            'warehouse_deliveries' => ProjectMaterialDelivery::query()
                ->where('organization_id', $siteRequest->organization_id)
                ->where('site_request_id', $siteRequest->id)
                ->get(),
            'purchase_requests' => PurchaseRequest::query()
                ->where('organization_id', $siteRequest->organization_id)
                ->where('site_request_id', $siteRequest->id)
                ->get(),
        ];
    }
}
