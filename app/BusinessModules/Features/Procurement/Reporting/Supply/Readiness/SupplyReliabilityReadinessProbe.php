<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
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
        $promises = PurchaseOrderPromiseVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('promise_version', 1)
            ->where('effective_from', '<=', $query->asOf)
            ->when(
                $allowedItemIds !== null,
                static fn ($builder) => $builder->whereIn(
                    'purchase_order_item_id',
                    $allowedItemIds,
                ),
            )
            ->when($projects !== [], static fn ($builder) => $builder->whereIn('project_id', $projects));
        $eligiblePromiseIds = (clone $promises)->select('id');
        $eligibleItemIds = (clone $promises)->select('purchase_order_item_id');
        $eligible = (clone $promises)->distinct()->count('purchase_order_item_id');
        $projected = $eligible;
        $missingSent = (clone $promises)
            ->whereNotExists(function ($builder) use ($context, $query): void {
                $builder->selectRaw('1')
                    ->from('supply_lifecycle_events as readiness_event')
                    ->whereColumn(
                        'readiness_event.promise_version_id',
                        'purchase_order_promise_versions.id',
                    )
                    ->whereColumn(
                        'readiness_event.purchase_order_item_id',
                        'purchase_order_promise_versions.purchase_order_item_id',
                    )
                    ->where('readiness_event.organization_id', $context->scope->organizationId)
                    ->where('readiness_event.event_type', 'sent')
                    ->where('readiness_event.occurred_at', '<=', $query->asOf);
            })
            ->count();
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
            ->whereIn('promise_version_id', $eligiblePromiseIds)
            ->where('occurred_at', '<=', $query->asOf);

        return new SourceReadinessResult(
            $eligible,
            $projected,
            $missingSent,
            $unknown,
            (clone $promises)->where('source_version', '<', 1)->count()
                + (clone $lifecycle)->where('source_version', '<', 1)->count(),
            (clone $promises)->whereRaw('LENGTH(source_hash) <> 64')->count()
                + (clone $lifecycle)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }
}
