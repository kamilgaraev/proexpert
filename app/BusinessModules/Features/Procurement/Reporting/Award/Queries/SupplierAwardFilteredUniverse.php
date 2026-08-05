<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Queries;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion;
use App\Support\Reporting\ReportSourceAccessPolicy;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Eloquent\Builder;

final readonly class SupplierAwardFilteredUniverse
{
    public function __construct(
        private ReportSourceAccessPolicy $sourceAccess,
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
        [$periodStart, $periodEnd] = $this->period($query);
        $builder->whereBetween('supplier_award_decision_versions.selected_at', [$periodStart, $periodEnd]);

        return $builder
            ->select('supplier_award_decision_versions.*')
            ->distinct();
    }

    private function period(ReportQuery $query): array
    {
        $start = $query->filters->values['period_start'] ?? null;
        $end = $query->filters->values['period_end'] ?? null;
        if (! is_string($start) || ! is_string($end)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => 'filters']);
        }
        try {
            $periodStart = new DateTimeImmutable($start.' 00:00:00');
            $periodEnd = new DateTimeImmutable($end.' 23:59:59.999999');
        } catch (Exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => 'filters']);
        }
        if ($periodStart > $periodEnd || $periodStart->format('Y-m-d') !== $start || $periodEnd->format('Y-m-d') !== $end) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => 'filters']);
        }

        return [$periodStart, $periodEnd];
    }
}
