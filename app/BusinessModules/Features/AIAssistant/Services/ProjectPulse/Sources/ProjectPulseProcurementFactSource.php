<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProjectPulseProcurementFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'procurement';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('purchase_requests')) {
            return $this->empty();
        }

        return collect()
            ->merge($this->approvedRequestsWithoutOrders($context))
            ->merge($this->approvedSiteRequestsWithoutPurchaseOrders($context))
            ->merge($this->supplierRequestsWithoutResponse($context))
            ->merge($this->purchaseOrdersWithDeliveryRisks($context))
            ->take((int) config('ai-assistant.project_pulse.limits.facts_total', 250))
            ->values();
    }

    private function approvedRequestsWithoutOrders(ProjectPulseContext $context): Collection
    {
        $hasDirectProject = $this->hasColumn('purchase_requests', 'project_id');
        $hasSiteRequestProject = $this->hasTable('site_requests')
            && $this->hasColumn('purchase_requests', 'site_request_id')
            && $this->hasColumn('site_requests', 'project_id');

        if ($context->projectId !== null && !$hasDirectProject && !$hasSiteRequestProject) {
            return $this->empty();
        }

        $query = $this->table($context, 'purchase_requests')
            ->whereIn('purchase_requests.status', ['approved', 'agreed', 'confirmed'])
            ->limit($this->limit());

        if ($hasSiteRequestProject) {
            $query->leftJoin('site_requests', 'site_requests.id', '=', 'purchase_requests.site_request_id')
                ->leftJoin('projects', 'projects.id', '=', 'site_requests.project_id')
                ->when($context->projectId !== null, fn (Builder $query) => $query->where('site_requests.project_id', $context->projectId));
        }

        if ($this->hasTable('purchase_orders') && $this->hasColumn('purchase_orders', 'purchase_request_id')) {
            $query->whereNotExists(function (Builder $query): void {
                $query->select(DB::raw(1))
                    ->from('purchase_orders')
                    ->whereColumn('purchase_orders.purchase_request_id', 'purchase_requests.id')
                    ->when($this->hasColumn('purchase_orders', 'deleted_at'), fn (Builder $query) => $query->whereNull('purchase_orders.deleted_at'));
            });
        }

        $columns = [
            'purchase_requests.id',
            'purchase_requests.request_number',
            'purchase_requests.status',
            'purchase_requests.created_at',
        ];

        if ($this->hasColumn('purchase_requests', 'budget_amount')) {
            $columns[] = 'purchase_requests.budget_amount';
        }

        if ($hasSiteRequestProject) {
            $columns[] = 'site_requests.project_id';
            $columns[] = 'projects.name as project_name';
        }

        return $query->get($columns)->map(fn ($row) => new ProjectPulseFact(
            id: 'purchase_request:' . $row->id . ':no_order',
            type: 'purchase_request',
            priority: 'warning',
            title: 'Согласована, но заказ поставщику не создан',
            text: 'По согласованной закупочной заявке ' . $row->request_number . ' еще не оформлен заказ поставщику.',
            projectId: isset($row->project_id) && $row->project_id !== null ? (int) $row->project_id : null,
            projectName: $row->project_name ?? null,
            relatedEntity: [
                'type' => 'purchase_request',
                'id' => (int) $row->id,
                'label' => 'Заявка на закупку ' . $row->request_number,
                'route' => '/procurement/purchase-requests/' . $row->id,
            ],
            amount: isset($row->budget_amount) && $row->budget_amount !== null ? (float) $row->budget_amount : null,
            occurredAt: $this->dateString($row->created_at),
            source: $this->key(),
            category: 'procurement',
            status: $row->status,
            nextAction: 'Создать заказ поставщику и зафиксировать поставщика, сроки и сумму.',
            primaryAction: [
                'label' => 'Открыть заявку',
                'route' => '/procurement/purchase-requests/' . $row->id,
                'permission' => 'procurement.purchase_requests.view',
            ],
            ageDays: $this->ageDays($context, $row->created_at),
        ))->values();
    }

    private function approvedSiteRequestsWithoutPurchaseOrders(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('site_requests') || !$this->hasColumn('purchase_requests', 'site_request_id')) {
            return $this->empty();
        }

        $query = $this->table($context, 'site_requests')
            ->leftJoin('projects', 'projects.id', '=', 'site_requests.project_id')
            ->leftJoin('purchase_requests', 'purchase_requests.site_request_id', '=', 'site_requests.id')
            ->whereIn('site_requests.status', ['approved', 'agreed', 'confirmed'])
            ->limit($this->limit());

        if ($this->hasTable('purchase_orders') && $this->hasColumn('purchase_orders', 'purchase_request_id')) {
            $query->whereNotExists(function (Builder $query): void {
                $query->select(DB::raw(1))
                    ->from('purchase_orders')
                    ->whereColumn('purchase_orders.purchase_request_id', 'purchase_requests.id')
                    ->when($this->hasColumn('purchase_orders', 'deleted_at'), fn (Builder $query) => $query->whereNull('purchase_orders.deleted_at'));
            });
        }

        return $query->get([
            'site_requests.id as site_request_id',
            'site_requests.project_id',
            'site_requests.title',
            'site_requests.status as site_request_status',
            'site_requests.created_at',
            'projects.name as project_name',
            'purchase_requests.id as purchase_request_id',
            'purchase_requests.request_number',
            'purchase_requests.status as purchase_request_status',
        ])->map(function ($row) use ($context): ProjectPulseFact {
            $purchaseRequestId = $row->purchase_request_id !== null ? (int) $row->purchase_request_id : null;
            $entityId = $purchaseRequestId ?? (int) $row->site_request_id;
            $route = $purchaseRequestId !== null
                ? '/procurement/purchase-requests/' . $purchaseRequestId
                : '/site-requests/' . $row->site_request_id;
            $number = $row->request_number ?: ('заявке "' . ($row->title ?? ('#' . $row->site_request_id)) . '"');

            return new ProjectPulseFact(
                id: ($purchaseRequestId !== null ? 'purchase_request:' . $purchaseRequestId : 'site_request:' . $row->site_request_id) . ':no_order',
                type: $purchaseRequestId !== null ? 'purchase_request' : 'site_request',
                priority: 'warning',
                title: 'Согласована, но заказ поставщику не создан',
                text: 'По согласованной закупочной заявке ' . $number . ' еще не оформлен заказ поставщику.',
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                projectName: $row->project_name,
                relatedEntity: [
                    'type' => $purchaseRequestId !== null ? 'purchase_request' : 'site_request',
                    'id' => $entityId,
                    'label' => $purchaseRequestId !== null ? 'Заявка на закупку ' . $number : 'Заявка с объекта #' . $row->site_request_id,
                    'route' => $route,
                ],
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'procurement',
                status: $row->purchase_request_status ?? $row->site_request_status,
                nextAction: 'Создать заказ поставщику и зафиксировать поставщика, сроки и сумму.',
                primaryAction: [
                    'label' => $purchaseRequestId !== null ? 'Открыть заявку' : 'Открыть заявку с объекта',
                    'route' => $route,
                    'permission' => $purchaseRequestId !== null ? 'procurement.purchase_requests.view' : 'site_requests.view',
                ],
                ageDays: $this->ageDays($context, $row->created_at),
            );
        })->values();
    }

    private function supplierRequestsWithoutResponse(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('supplier_requests')) {
            return $this->empty();
        }

        return $this->table($context, 'supplier_requests')
            ->whereIn('supplier_requests.status', ['sent', 'pending', 'requested'])
            ->whereNull('supplier_requests.responded_at')
            ->limit($this->limit())
            ->get(['supplier_requests.id', 'supplier_requests.request_number', 'supplier_requests.status', 'supplier_requests.sent_at', 'supplier_requests.created_at'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'supplier_request:' . $row->id . ':no_response',
                type: 'supplier_request',
                priority: 'warning',
                title: 'Поставщик не ответил на запрос',
                text: 'По запросу поставщику ' . $row->request_number . ' нет ответа в системе.',
                relatedEntity: [
                    'type' => 'supplier_request',
                    'id' => (int) $row->id,
                    'label' => 'Запрос поставщику ' . $row->request_number,
                    'route' => '/procurement/supplier-requests',
                ],
                occurredAt: $this->dateString($row->sent_at ?? $row->created_at),
                source: $this->key(),
                category: 'procurement',
                status: $row->status,
                nextAction: 'Запросить ответ поставщика или выбрать альтернативного поставщика.',
                primaryAction: [
                    'label' => 'Открыть запрос',
                    'route' => '/procurement/supplier-requests',
                    'permission' => 'procurement.supplier_requests.view',
                ],
                ageDays: $this->ageDays($context, $row->sent_at ?? $row->created_at),
            ))->values();
    }

    private function purchaseOrdersWithDeliveryRisks(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('purchase_orders') || !$this->hasColumn('purchase_orders', 'delivery_date')) {
            return $this->empty();
        }

        return $this->table($context, 'purchase_orders')
            ->whereNotIn('purchase_orders.status', ['completed', 'cancelled', 'closed'])
            ->whereNotNull('purchase_orders.delivery_date')
            ->whereDate('purchase_orders.delivery_date', '<', $context->date->toDateString())
            ->limit($this->limit())
            ->get(['purchase_orders.id', 'purchase_orders.order_number', 'purchase_orders.status', 'purchase_orders.total_amount', 'purchase_orders.delivery_date'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'purchase_order:' . $row->id . ':delivery_overdue',
                type: 'purchase_order',
                priority: 'critical',
                title: 'Поставка по заказу просрочена',
                text: 'Заказ поставщику ' . $row->order_number . ' не закрыт после плановой даты поставки.',
                relatedEntity: [
                    'type' => 'purchase_order',
                    'id' => (int) $row->id,
                    'label' => 'Заказ поставщику ' . $row->order_number,
                    'route' => '/procurement/purchase-orders/' . $row->id,
                ],
                amount: $row->total_amount !== null ? (float) $row->total_amount : null,
                source: $this->key(),
                category: 'procurement',
                status: $row->status,
                nextAction: 'Проверить поставку, обновить срок и зафиксировать ответственного.',
                primaryAction: [
                    'label' => 'Открыть заказ',
                    'route' => '/procurement/purchase-orders/' . $row->id,
                    'permission' => 'procurement.purchase_orders.view',
                ],
                deadline: (string) $row->delivery_date,
                ageDays: $this->ageDays($context, $row->delivery_date),
            ))->values();
    }
}
