<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\Support\Reporting\ReportSourceAccessPolicy;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;

final readonly class SupplyReliabilityReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function __construct(private ReportSourceAccessPolicy $sourceAccess) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'supply_reliability'
            && $definition->formulaVersion === 'supply-otif.v1';
    }

    public function assertReady(ReportExecutionContext $context, ReportQuery $query): void
    {
        $this->inspect($context, $query)->assertReady('supply_reliability');
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): SourceReadinessResult
    {
        $projects = $context->scope->projectIds;
        $allowedItemIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'purchase_order_item',
        );
        $eligibleQuery = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_orders.purchase_request_id')
            ->join('site_requests', 'site_requests.id', '=', 'purchase_requests.site_request_id')
            ->where('purchase_orders.organization_id', $context->scope->organizationId)
            ->whereNotNull('purchase_orders.sent_at')
            ->where('purchase_orders.sent_at', '<=', $query->asOf)
            ->whereNull('purchase_orders.deleted_at')
            ->when(
                $allowedItemIds !== null,
                static fn ($builder) => $builder->whereIn(
                    'purchase_order_items.id',
                    $allowedItemIds,
                ),
            )
            ->when($projects !== [], static fn ($builder) => $builder->whereIn('site_requests.project_id', $projects));
        $eligibleItemIds = (clone $eligibleQuery)->pluck('purchase_order_items.id');
        $eligible = $eligibleItemIds->count();
        $promises = PurchaseOrderPromiseVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('purchase_order_item_id', $eligibleItemIds)
            ->where('promise_version', 1)
            ->where('effective_from', '<=', $query->asOf);
        $projected = (clone $promises)->distinct()->count('purchase_order_item_id');
        $sentItems = SupplyLifecycleEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('purchase_order_item_id', $eligibleItemIds)
            ->where('event_type', 'sent')
            ->where('occurred_at', '<=', $query->asOf)
            ->distinct()
            ->count('purchase_order_item_id');
        $unknown = (clone $promises)
            ->where(function ($builder): void {
                $builder->whereIn('unit_dimension', ['', 'unknown'])
                    ->orWhereIn('conversion_version', ['', 'unknown', 'unproven'])
                    ->orWhereNull('ordered_value_minor')
                    ->orWhereNull('currency')
                    ->orWhereNull('value_basis');
            })
            ->count();
        $lifecycle = SupplyLifecycleEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('purchase_order_item_id', $eligibleItemIds)
            ->where('occurred_at', '<=', $query->asOf);

        return new SourceReadinessResult(
            $eligible,
            $projected,
            max(0, $eligible - min($projected, $sentItems)),
            $unknown,
            (clone $promises)->where('source_version', '<', 1)->count()
                + (clone $lifecycle)->where('source_version', '<', 1)->count(),
            (clone $promises)->whereRaw('LENGTH(source_hash) <> 64')->count()
                + (clone $lifecycle)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }
}
