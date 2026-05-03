<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly;

use App\Models\Organization;
use App\Models\User;

class GetProcurementSnapshotTool extends AbstractReadOnlyTool
{
    public function getName(): string
    {
        return 'get_procurement_snapshot';
    }

    public function getDescription(): string
    {
        return 'Возвращает read-only сводку по закупкам: заявки, заказы поставщикам, статусы, суммы и привязку к проекту.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'integer', 'description' => 'ID проекта'],
                'purchase_request_id' => ['type' => 'integer', 'description' => 'ID заявки на закупку'],
                'status' => ['type' => 'string', 'description' => 'Статус заявки или заказа'],
                'query' => ['type' => 'string', 'description' => 'Номер заявки, заказа или текст из заявки'],
                'date_from' => ['type' => 'string', 'description' => 'Дата начала периода YYYY-MM-DD'],
                'date_to' => ['type' => 'string', 'description' => 'Дата конца периода YYYY-MM-DD'],
                'limit' => ['type' => 'integer', 'description' => 'Максимум записей, от 1 до 30', 'default' => 10],
            ],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        unset($user);

        if (!$this->hasTable('purchase_requests')) {
            return $this->tableUnavailable('procurement', 'purchase_requests');
        }

        $requests = $this->buildRequests($arguments, $organization);
        $orders = $this->buildOrders($arguments, $organization);

        return [
            'status' => 'success',
            'domain' => 'procurement',
            'summary' => [
                'requests_count' => count($requests),
                'orders_count' => count($orders),
                'orders_total_amount' => round(array_sum(array_map(
                    static fn (array $order): float => (float) ($order['total_amount'] ?? 0),
                    $orders
                )), 2),
            ],
            'purchase_requests' => $requests,
            'purchase_orders' => $orders,
        ];
    }

    private function buildRequests(array $arguments, Organization $organization): array
    {
        $query = $this->withoutDeleted($this->orgTable('purchase_requests', $organization), 'purchase_requests');
        $projectId = $this->intArg($arguments, 'project_id');
        $requestId = $this->intArg($arguments, 'purchase_request_id');
        $status = $this->stringArg($arguments, 'status');
        $search = $this->stringArg($arguments, 'query');

        $hasSiteRequests = $this->hasTable('site_requests');

        if ($hasSiteRequests) {
            $query->leftJoin('site_requests', 'purchase_requests.site_request_id', '=', 'site_requests.id');
        }

        if ($requestId !== null) {
            $query->where('purchase_requests.id', $requestId);
        }

        if ($projectId !== null && $hasSiteRequests) {
            $query->where('site_requests.project_id', $projectId);
        }

        if ($status !== null) {
            $query->where('purchase_requests.status', $status);
        }

        if ($search !== null) {
            $query->where(function ($inner) use ($search): void {
                $inner->where('purchase_requests.request_number', 'ilike', "%{$search}%")
                    ->orWhere('purchase_requests.notes', 'ilike', "%{$search}%");
            });
        }

        $this->applyDateRange($query, 'purchase_requests.created_at', $arguments);

        $columns = [
            'purchase_requests.id',
            'purchase_requests.request_number',
            'purchase_requests.status',
            'purchase_requests.needed_by',
            'purchase_requests.budget_amount',
            'purchase_requests.budget_currency',
            'purchase_requests.notes',
            'purchase_requests.site_request_id',
        ];

        if ($hasSiteRequests) {
            $columns[] = 'site_requests.project_id';
            $columns[] = 'site_requests.title as site_request_title';
        }

        return $query
            ->select($columns)
            ->orderByDesc('purchase_requests.id')
            ->limit($this->limit($arguments))
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'number' => $row->request_number,
                'status' => $row->status,
                'needed_by' => $row->needed_by,
                'budget_amount' => $row->budget_amount !== null ? (float) $row->budget_amount : null,
                'currency' => $row->budget_currency,
                'site_request_id' => $row->site_request_id,
                'project_id' => $row->project_id ?? null,
                'title' => $row->site_request_title ?? $row->notes,
            ])
            ->all();
    }

    private function buildOrders(array $arguments, Organization $organization): array
    {
        if (!$this->hasTable('purchase_orders')) {
            return [];
        }

        $query = $this->withoutDeleted($this->orgTable('purchase_orders', $organization), 'purchase_orders');
        $requestId = $this->intArg($arguments, 'purchase_request_id');
        $status = $this->stringArg($arguments, 'status');
        $search = $this->stringArg($arguments, 'query');

        if ($requestId !== null) {
            $query->where('purchase_orders.purchase_request_id', $requestId);
        }

        if ($status !== null) {
            $query->where('purchase_orders.status', $status);
        }

        if ($search !== null) {
            $query->where('purchase_orders.order_number', 'ilike', "%{$search}%");
        }

        $this->applyDateRange($query, 'purchase_orders.created_at', $arguments);

        return $query
            ->select([
                'purchase_orders.id',
                'purchase_orders.purchase_request_id',
                'purchase_orders.order_number',
                'purchase_orders.status',
                'purchase_orders.order_date',
                'purchase_orders.delivery_date',
                'purchase_orders.total_amount',
                'purchase_orders.currency',
                'purchase_orders.contract_id',
            ])
            ->orderByDesc('purchase_orders.id')
            ->limit($this->limit($arguments))
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'purchase_request_id' => $row->purchase_request_id,
                'number' => $row->order_number,
                'status' => $row->status,
                'order_date' => $row->order_date,
                'delivery_date' => $row->delivery_date,
                'total_amount' => $row->total_amount !== null ? (float) $row->total_amount : null,
                'currency' => $row->currency,
                'contract_id' => $row->contract_id,
            ])
            ->all();
    }
}
