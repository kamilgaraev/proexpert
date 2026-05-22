<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MobilePurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequest $purchaseRequest */
        $purchaseRequest = $this->resource;
        $siteRequest = $purchaseRequest->siteRequest;

        return [
            'id' => $purchaseRequest->id,
            'organization_id' => $purchaseRequest->organization_id,
            'site_request_id' => $purchaseRequest->site_request_id,
            'request_number' => $purchaseRequest->request_number,
            'status' => $this->statusValue($purchaseRequest),
            'status_label' => $this->statusLabel($purchaseRequest),
            'needed_by' => $purchaseRequest->needed_by?->toDateString(),
            'budget_amount' => $purchaseRequest->budget_amount !== null ? (float) $purchaseRequest->budget_amount : null,
            'budget_currency' => $purchaseRequest->budget_currency,
            'notes' => $purchaseRequest->notes,
            'assigned_user_label' => $purchaseRequest->assignedUser?->name,
            'statistics' => [
                'lines_count' => (int) ($purchaseRequest->lines_count ?? $purchaseRequest->lines->count()),
                'supplier_requests_count' => (int) ($purchaseRequest->supplier_requests_count ?? 0),
                'purchase_orders_count' => (int) ($purchaseRequest->purchase_orders_count ?? $purchaseRequest->purchaseOrders->count()),
            ],
            'site_request' => $siteRequest ? [
                'id' => $siteRequest->id,
                'title' => $siteRequest->title,
                'project_id' => $siteRequest->project_id,
                'project_label' => $siteRequest->project?->name,
                'required_date' => $siteRequest->required_date?->toDateString(),
            ] : null,
            'lines' => $this->lines($purchaseRequest),
            'purchase_orders' => $this->orders($purchaseRequest),
            'created_at' => $purchaseRequest->created_at?->toIso8601String(),
            'updated_at' => $purchaseRequest->updated_at?->toIso8601String(),
        ];
    }

    private function statusValue(PurchaseRequest $purchaseRequest): string
    {
        $status = $purchaseRequest->status;

        return $status instanceof PurchaseRequestStatusEnum ? $status->value : (string) $status;
    }

    private function statusLabel(PurchaseRequest $purchaseRequest): string
    {
        return trans_message('procurement.mobile.purchase_request_statuses.' . $this->statusValue($purchaseRequest));
    }

    private function lines(PurchaseRequest $purchaseRequest): array
    {
        if (!$purchaseRequest->relationLoaded('lines')) {
            return [];
        }

        return $purchaseRequest->lines
            ->map(static fn (PurchaseRequestLine $line): array => [
                'id' => $line->id,
                'material_id' => $line->material_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'specification' => $line->specification,
                'needed_by' => $line->needed_by?->toDateString(),
            ])
            ->values()
            ->all();
    }

    private function orders(PurchaseRequest $purchaseRequest): array
    {
        if (!$purchaseRequest->relationLoaded('purchaseOrders')) {
            return [];
        }

        return $purchaseRequest->purchaseOrders
            ->map(fn ($order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status,
                'total_amount' => (float) $order->total_amount,
                'currency' => $order->currency,
            ])
            ->values()
            ->all();
    }
}
