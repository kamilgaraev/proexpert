<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Queries;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion;
use App\Support\Reporting\OwnerReportFilterApplier;
use App\Support\Reporting\ReportSourceAccessPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class SupplierAwardFilteredUniverse
{
    public function __construct(
        private ReportSourceAccessPolicy $sourceAccess,
        private OwnerReportFilterApplier $filters,
    ) {}

    public function query(ReportExecutionContext $context, ReportQuery $query): Builder
    {
        $allowedDecisionIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'supplier_award_decision',
        );
        $builder = SupplierAwardDecisionVersion::query()
            ->where('supplier_award_decision_versions.organization_id', $context->scope->organizationId)
            ->where('supplier_award_decision_versions.selected_at', '<=', $query->asOf)
            ->whereNotExists(function ($later) use ($query): void {
                $later->selectRaw('1')
                    ->from('supplier_award_decision_versions as later_award_version')
                    ->whereColumn(
                        'later_award_version.organization_id',
                        'supplier_award_decision_versions.organization_id',
                    )
                    ->whereColumn(
                        'later_award_version.decision_id',
                        'supplier_award_decision_versions.decision_id',
                    )
                    ->whereColumn(
                        'later_award_version.decision_version',
                        '>',
                        'supplier_award_decision_versions.decision_version',
                    )
                    ->where('later_award_version.selected_at', '<=', $query->asOf);
            })
            ->when(
                $allowedDecisionIds !== null,
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'supplier_award_decision_versions.decision_id',
                    $allowedDecisionIds,
                ),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'supplier_award_decision_versions.project_id',
                    $context->scope->projectIds,
                ),
            );
        $this->filters->apply($builder, $this->filters->only($query->filters, [
            'project', 'buyer', 'supplier', 'decision', 'method', 'currency', 'non_lowest', 'period',
        ]), [
            'project' => 'supplier_award_decision_versions.project_id',
            'buyer' => 'supplier_award_decision_versions.selected_by',
            'supplier' => 'supplier_award_decision_versions.selected_supplier_party_id',
            'decision' => 'supplier_award_decision_versions.decision_id',
            'method' => DB::raw("supplier_award_decision_versions.dimension_snapshot->>'procurement_method'"),
            'currency' => DB::raw("supplier_award_decision_versions.dimension_snapshot->>'currency'"),
            'non_lowest' => [
                'column' => 'supplier_award_decision_versions.is_lowest_price_selected',
                'invert_boolean' => true,
            ],
            'period' => 'supplier_award_decision_versions.selected_at',
        ]);
        $this->applyPinnedLineFilter($builder, $query, 'material', 'material_id');
        $this->applyPinnedLineFilter($builder, $query, 'category', 'category');

        return $builder
            ->select('supplier_award_decision_versions.*')
            ->distinct();
    }

    private function applyPinnedLineFilter(
        Builder $builder,
        ReportQuery $query,
        string $filter,
        string $dimension,
    ): void {
        $condition = $query->filters->values[$filter] ?? null;
        if (! is_array($condition)) {
            return;
        }
        $operator = $condition['operator'] ?? null;
        $values = match ($operator) {
            'eq' => [$condition['value'] ?? null],
            'in' => (array) ($condition['value'] ?? []),
            'neq' => [$condition['value'] ?? null],
            'not_in' => (array) ($condition['value'] ?? []),
            default => throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_FILTER_UNSUPPORTED,
            ),
        };
        if ($values === []) {
            $builder->whereRaw('1 = 0');

            return;
        }
        $method = in_array($operator, ['neq', 'not_in'], true) ? 'whereNotExists' : 'whereExists';
        $builder->{$method}(function ($line) use ($dimension, $values): void {
            $line->selectRaw('1')
                ->fromRaw(
                    "jsonb_array_elements(supplier_award_decision_versions.dimension_snapshot->'lines') "
                    .'as pinned_line',
                )
                ->whereIn(DB::raw("pinned_line->>'{$dimension}'"), array_map('strval', $values));
        });
    }
}
