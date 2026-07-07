<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentGateService;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

final class MobilePurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PurchaseOrder $order */
        $order = $this->resource;
        $workflowSummary = app(ProcurementLifecycleService::class)->forPurchaseOrder($order);
        $paymentSummary = app(PurchaseOrderPaymentGateService::class)->summary($order);

        return [
            'id' => $order->id,
            'organization_id' => $order->organization_id,
            'purchase_request_id' => $order->purchase_request_id,
            'order_number' => $order->order_number,
            'order_date' => $order->order_date?->toDateString(),
            'status' => $this->statusValue($order),
            'status_label' => trans_message('procurement.mobile.purchase_order_statuses.'.$this->statusValue($order)),
            'total_amount' => (float) $order->total_amount,
            'pricing_breakdown' => $this->pricingBreakdown($order),
            'currency' => $order->currency,
            'delivery_date' => $order->delivery_date?->toDateString(),
            'sent_at' => $order->sent_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'notes' => $order->notes,
            'supplier' => [
                'id' => $order->supplier_id,
                'label' => $this->supplierLabel($order),
                'party_id' => $order->supplier_party_id,
            ],
            'purchase_request' => $this->purchaseRequestPayload($order),
            'statistics' => [
                'items_count' => (int) ($order->items_count ?? $order->items->count()),
                'receipts_count' => (int) ($order->receipts_count ?? $order->receipts->count()),
                'received_items_count' => $this->receivedItemsCount($order),
            ],
            'workflow_summary' => $workflowSummary->toArray(),
            'payment_summary' => $paymentSummary,
            'available_actions' => $this->availableActions($request, $order, $workflowSummary->canReceiveMaterials),
            'items' => $this->items($order),
            'receipts' => $this->receipts($order),
            'comments' => $this->comments($order),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    private function statusValue(PurchaseOrder $order): string
    {
        $status = $order->status;

        return $status instanceof PurchaseOrderStatusEnum ? $status->value : (string) $status;
    }

    private function availableActions(Request $request, PurchaseOrder $order, bool $canReceiveMaterials): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        $authorization = app(AuthorizationService::class);
        $organizationId = (int) $order->organization_id;
        $actions = [];

        if (
            $canReceiveMaterials
            && $authorization->can($user, 'procurement.purchase_orders.receive', ['organization_id' => $organizationId])
        ) {
            $actions[] = 'receive_materials';
        }

        if ($authorization->can($user, 'procurement.purchase_orders.comment', ['organization_id' => $organizationId])) {
            $actions[] = 'comment';
        }

        return $actions;
    }

    private function supplierLabel(PurchaseOrder $order): ?string
    {
        $snapshot = is_array($order->supplier_snapshot) ? $order->supplier_snapshot : [];

        foreach ([
            $snapshot['display_name'] ?? null,
            $snapshot['name'] ?? null,
            $order->supplier?->name,
            $order->supplierParty?->display_name ?? null,
            $order->externalSupplierContact?->name ?? null,
        ] as $value) {
            $label = is_string($value) ? trim($value) : '';

            if ($label !== '') {
                return $label;
            }
        }

        return null;
    }

    private function purchaseRequestPayload(PurchaseOrder $order): ?array
    {
        $purchaseRequest = $order->purchaseRequest;

        if (! $purchaseRequest) {
            return null;
        }

        $siteRequest = $purchaseRequest->siteRequest;

        return [
            'id' => $purchaseRequest->id,
            'request_number' => $purchaseRequest->request_number,
            'status' => $purchaseRequest->status instanceof \BackedEnum ? $purchaseRequest->status->value : (string) $purchaseRequest->status,
            'site_request_id' => $purchaseRequest->site_request_id,
            'site_request_title' => $siteRequest?->title,
            'project_id' => $siteRequest?->project_id,
            'project_label' => $siteRequest?->project?->name,
        ];
    }

    private function items(PurchaseOrder $order): array
    {
        if (! $order->relationLoaded('items')) {
            return [];
        }

        $receivedByItem = $this->receivedByItem($order);

        return $order->items
            ->map(static function (PurchaseOrderItem $item) use ($receivedByItem): array {
                $quantity = (float) $item->quantity;
                $received = (float) ($receivedByItem[(int) $item->id] ?? 0.0);

                return [
                    'id' => $item->id,
                    'material_id' => $item->material_id,
                    'material_name' => $item->material_name,
                    'quantity' => $quantity,
                    'unit' => $item->unit,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                    'received_quantity' => $received,
                    'remaining_quantity' => max(round($quantity - $received, 3), 0.0),
                    'notes' => $item->notes,
                ];
            })
            ->values()
            ->all();
    }

    private function pricingBreakdown(PurchaseOrder $order): array
    {
        $snapshot = $this->acceptedCommercialSnapshot($order);
        $itemsAmount = $this->itemsSubtotal($order);
        $deliveryAmount = $this->numericPayloadValue($snapshot, 'delivery_amount') ?? 0.0;
        $vatAmount = $this->numericPayloadValue($snapshot, 'vat_amount') ?? 0.0;
        $totalAmount = $this->numericPayloadValue($snapshot, 'total_amount') ?? (float) $order->total_amount;
        $subtotalAmount = $this->numericPayloadValue($snapshot, 'subtotal_amount')
            ?? $itemsAmount
            ?? max($totalAmount - $deliveryAmount - $vatAmount, 0.0);

        return [
            'subtotal_amount' => round($subtotalAmount, 2),
            'delivery_amount' => round($deliveryAmount, 2),
            'vat_amount' => round($vatAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'currency' => $this->stringPayloadValue($snapshot, 'currency') ?? $order->currency,
            'vat_mode' => $this->stringPayloadValue($snapshot, 'vat_mode'),
            'vat_rate' => $this->numericPayloadValue($snapshot, 'vat_rate'),
        ];
    }

    private function acceptedCommercialSnapshot(PurchaseOrder $order): array
    {
        $version = $order->relationLoaded('acceptedSupplierProposalVersion')
            ? $order->acceptedSupplierProposalVersion
            : null;

        if ($version instanceof SupplierProposalVersion && is_array($version->commercial_snapshot)) {
            return $version->commercial_snapshot;
        }

        $proposal = $order->relationLoaded('acceptedSupplierProposal')
            ? $order->acceptedSupplierProposal
            : null;

        if (! $proposal instanceof SupplierProposal) {
            return [];
        }

        return [
            'subtotal_amount' => (float) $proposal->subtotal_amount,
            'delivery_amount' => (float) $proposal->delivery_amount,
            'vat_amount' => (float) $proposal->vat_amount,
            'total_amount' => (float) $proposal->total_amount,
            'currency' => $proposal->currency,
            'vat_mode' => $proposal->vat_mode,
            'vat_rate' => $proposal->vat_rate === null ? null : (float) $proposal->vat_rate,
        ];
    }

    private function itemsSubtotal(PurchaseOrder $order): ?float
    {
        if (! $order->relationLoaded('items')) {
            return null;
        }

        return round((float) $order->items->sum('total_price'), 2);
    }

    private function numericPayloadValue(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function stringPayloadValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function receipts(PurchaseOrder $order): array
    {
        if (! $order->relationLoaded('receipts')) {
            return [];
        }

        return $order->receipts
            ->map(static fn (PurchaseReceipt $receipt): array => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'receipt_date' => $receipt->receipt_date?->toDateString(),
                'status' => $receipt->status instanceof \BackedEnum ? $receipt->status->value : (string) $receipt->status,
                'warehouse' => $receipt->warehouse ? [
                    'id' => $receipt->warehouse->id,
                    'name' => $receipt->warehouse->name,
                ] : null,
                'received_by_label' => $receipt->receivedByUser?->name,
                'notes' => $receipt->notes,
                'lines' => $receipt->relationLoaded('lines')
                    ? $receipt->lines->map(static fn (PurchaseReceiptLine $line): array => [
                        'id' => $line->id,
                        'purchase_order_item_id' => $line->purchase_order_item_id,
                        'quantity_received' => (float) $line->quantity_received,
                        'price' => (float) $line->price,
                        'total_amount' => (float) $line->total_amount,
                    ])->values()->all()
                    : [],
            ])
            ->values()
            ->all();
    }

    private function comments(PurchaseOrder $order): array
    {
        if (! $order->relationLoaded('auditEvents')) {
            return [];
        }

        return $order->auditEvents
            ->filter(static function (ProcurementAuditEvent $event): bool {
                $type = $event->event_type;
                $value = $type instanceof ProcurementAuditEventTypeEnum ? $type->value : (string) $type;

                return $value === ProcurementAuditEventTypeEnum::PURCHASE_ORDER_COMMENTED->value;
            })
            ->map(static fn (ProcurementAuditEvent $event): array => [
                'id' => $event->id,
                'comment' => is_array($event->payload) ? ($event->payload['comment'] ?? null) : null,
                'actor_label' => $event->actor?->name,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function receivedItemsCount(PurchaseOrder $order): int
    {
        return collect($this->receivedByItem($order))
            ->filter(static fn (float $quantity): bool => $quantity > 0)
            ->count();
    }

    private function receivedByItem(PurchaseOrder $order): array
    {
        if (! $order->relationLoaded('receipts')) {
            return [];
        }

        return $order->receipts
            ->flatMap(static fn (PurchaseReceipt $receipt): Collection => $receipt->relationLoaded('lines') ? $receipt->lines : collect())
            ->groupBy('purchase_order_item_id')
            ->map(static fn (Collection $lines): float => round((float) $lines->sum('quantity_received'), 3))
            ->all();
    }
}
