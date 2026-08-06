<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionRow;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionSnapshot;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLineageFilter;
use App\Support\Reporting\CanonicalLineageSummary;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use App\Support\Reporting\LineageCursorPosition;
use App\Support\Reporting\LineageEventPage;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentAcceptedProductionDrillDownSource implements AcceptedProductionDrillDownSource
{
    private ImmutableOwnerProjectionReader $reader;

    public function __construct()
    {
        $this->reader = new ImmutableOwnerProjectionReader(
            AcceptedProductionRow::class,
            AcceptedProductionSnapshot::class,
            ['recognized_on' => 'recognized_on'],
            ['approved_rate_minor', 'accepted_amount_minor'],
        );
    }

    public function findRow(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $rowKey,
    ): ?array {
        return $this->reader->findRow(
            $context,
            $snapshot,
            $rowKey,
        );
    }

    public function eventPage(
        ReportExecutionContext $context,
        array $row,
        CanonicalLineageSummary $lineage,
        AcceptedProductionLineageFilter $filter,
        ?LineageCursorPosition $position,
        int $limit,
    ): LineageEventPage {
        $query = ProductionAcceptanceEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('project_id', (int) $row['project_id'])
            ->where('performance_act_id', (int) $row['performance_act_id'])
            ->where('source_line_type', (string) $row['source_line_type'])
            ->where('source_line_id', (int) $row['source_line_id'])
            ->when(
                $row['work_id'] === null,
                static fn (Builder $builder): Builder => $builder->whereNull('work_id'),
                static fn (Builder $builder): Builder => $builder
                    ->where('work_id', (int) $row['work_id']),
            )
            ->where(function ($builder) use ($lineage): void {
                $builder
                    ->where('transition_version', '>', $lineage->firstVersion)
                    ->orWhere(function ($lower) use ($lineage): void {
                        $lower
                            ->where('transition_version', $lineage->firstVersion)
                            ->where('id', '>=', $lineage->firstId);
                    });
            })
            ->where(function ($builder) use ($lineage): void {
                $builder
                    ->where('transition_version', '<', $lineage->lastVersion)
                    ->orWhere(function ($upper) use ($lineage): void {
                        $upper
                            ->where('transition_version', $lineage->lastVersion)
                            ->where('id', '<=', $lineage->lastId);
                    });
            });
        $filter->applyTo($query);
        $records = $query
            ->when(
                $position !== null,
                static function ($builder) use ($position): void {
                    $builder->where(function ($after) use ($position): void {
                        $after
                            ->where('transition_version', '>', $position->version)
                            ->orWhere(function ($tie) use ($position): void {
                                $tie
                                    ->where('transition_version', $position->version)
                                    ->where('id', '>', $position->id);
                            });
                    });
                },
            )
            ->orderBy('transition_version')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;

        return new LineageEventPage(
            $records->take($limit)->values()->all(),
            $hasMore,
        );
    }
}
