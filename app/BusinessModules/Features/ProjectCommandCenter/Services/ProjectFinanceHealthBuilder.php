<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectFinanceHealthData;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Project;
use App\Services\Analytics\EVMService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProjectFinanceHealthBuilder
{
    public function __construct(
        private readonly EVMService $evmService,
    ) {
    }

    public function build(Project $project, ProjectContext $projectContext, CarbonImmutable $asOf): ProjectFinanceHealthData
    {
        if (! $this->canViewFinances($projectContext)) {
            return ProjectFinanceHealthData::unavailable('project_command_center.finance.access_restricted');
        }

        $visibleOrganizationId = $this->visibleOrganizationId($projectContext);
        $metrics = $this->evmService->calculateMetrics($project, $visibleOrganizationId);
        $payments = $this->paymentFacts($project->getKey(), $visibleOrganizationId);
        $contractedRevenue = $this->contractedRevenue($project, $projectContext, $visibleOrganizationId);

        return $this->fromFacts([
            'metrics' => $metrics,
            'payments' => $payments,
            'contracted_revenue' => $contractedRevenue,
        ], $asOf);
    }

    /**
     * Kept public so calculation rules can be verified without a database connection.
     */
    public function fromFacts(array $facts, CarbonImmutable $asOf): ProjectFinanceHealthData
    {
        $metrics = $facts['metrics'] ?? [];
        $payments = $facts['payments'] ?? [];
        $bac = $this->number($metrics['bac'] ?? null);
        $actualCost = $this->number($metrics['ac'] ?? null);
        $forecastCost = $this->number($metrics['eac'] ?? null);

        $margin = [
            'available' => false,
            'reason_key' => 'project_command_center.finance.contracted_revenue_unavailable',
        ];

        if (($facts['contracted_revenue'] ?? null) !== null && $forecastCost !== null) {
            $revenue = $this->number($facts['contracted_revenue']);
            $margin = [
                'available' => $revenue !== null,
                'reason_key' => $revenue === null ? 'project_command_center.finance.contracted_revenue_unavailable' : null,
                'contracted_revenue' => $revenue,
                'forecast_total_cost' => $forecastCost,
                'forecast_profit' => $revenue === null ? null : round($revenue - $forecastCost, 2),
                'forecast_margin_percent' => $revenue !== null && $revenue > 0
                    ? round((($revenue - $forecastCost) / $revenue) * 100, 2)
                    : null,
            ];
        }

        $cashFlow = $this->cashFlow($payments, $asOf);

        return ProjectFinanceHealthData::available(
            margin: $margin,
            cashFlow: $cashFlow,
            evm: [
                'available' => $bac !== null && $actualCost !== null,
                'reason_key' => $bac === null
                    ? 'project_command_center.finance.planned_cost_unavailable'
                    : ($actualCost === null ? 'project_command_center.finance.actual_cost_unavailable' : null),
                'plan_total_cost' => $bac,
                'actual_cost' => $actualCost,
                'forecast_total_cost' => $forecastCost,
                'deviation' => $forecastCost !== null && $bac !== null ? round($forecastCost - $bac, 2) : null,
                'metrics' => $metrics === [] ? null : $metrics,
            ],
            dataCompleteness: [
                'contracted_revenue' => [
                    'available' => ($facts['contracted_revenue'] ?? null) !== null,
                    'reason_key' => ($facts['contracted_revenue'] ?? null) === null
                        ? 'project_command_center.finance.contracted_revenue_unavailable'
                        : null,
                ],
                'actual_costs' => [
                    'available' => $actualCost !== null,
                    'reason_key' => $actualCost === null ? 'project_command_center.finance.actual_cost_unavailable' : null,
                ],
                'payment_schedule' => [
                    'available' => $cashFlow['available'],
                    'reason_key' => $cashFlow['reason_key'],
                ],
            ],
        );
    }

    private function paymentFacts(int $projectId, ?int $visibleOrganizationId): array
    {
        $query = DB::table('payment_documents')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('remaining_amount', '>', 0)
            ->select(['direction', 'remaining_amount', 'due_date']);

        if ($visibleOrganizationId !== null) {
            $query->where('organization_id', $visibleOrganizationId);
        }

        return $query->get()
            ->map(static fn (object $row): array => [
                'direction' => (string) $row->direction,
                'amount' => (float) $row->remaining_amount,
                'due_at' => (string) $row->due_date,
            ])
            ->all();
    }

    private function cashFlow(array $payments, CarbonImmutable $asOf): array
    {
        $buckets = [30 => ['incoming' => 0.0, 'outgoing' => 0.0], 60 => ['incoming' => 0.0, 'outgoing' => 0.0], 90 => ['incoming' => 0.0, 'outgoing' => 0.0]];
        $datedPayments = 0;
        $receivable = 0.0;
        $payable = 0.0;

        foreach ($payments as $payment) {
            if (! isset($payment['amount']) || ! in_array($payment['direction'] ?? null, ['incoming', 'outgoing'], true)) {
                continue;
            }

            if ($payment['direction'] === 'incoming') {
                $receivable += (float) $payment['amount'];
            } else {
                $payable += (float) $payment['amount'];
            }

            if (empty($payment['due_at'])) {
                continue;
            }

            $dueAt = CarbonImmutable::parse((string) $payment['due_at'])->startOfDay();
            $days = max(0, $asOf->startOfDay()->diffInDays($dueAt, false));
            $bucket = $days <= 30 ? 30 : ($days <= 60 ? 60 : ($days <= 90 ? 90 : null));
            if ($bucket === null) {
                continue;
            }

            $datedPayments++;
            $buckets[$bucket][$payment['direction']] += (float) $payment['amount'];
        }

        if ($datedPayments === 0) {
            return [
                'available' => false,
                'reason_key' => 'project_command_center.finance.payment_schedule_unavailable',
                'accounts_receivable' => round($receivable, 2),
                'accounts_payable' => round($payable, 2),
                'projections' => [],
            ];
        }

        $projections = [];
        foreach ($buckets as $days => $amounts) {
            $projections[] = [
                'days' => $days,
                'incoming' => round($amounts['incoming'], 2),
                'outgoing' => round($amounts['outgoing'], 2),
                'net' => round($amounts['incoming'] - $amounts['outgoing'], 2),
            ];
        }

        return [
            'available' => true,
            'reason_key' => null,
            'accounts_receivable' => round($receivable, 2),
            'accounts_payable' => round($payable, 2),
            'projections' => $projections,
        ];
    }

    private function canViewFinances(ProjectContext $projectContext): bool
    {
        return $projectContext->roleConfig->canViewFinances
            || $projectContext->hasPermission('view_own_finances');
    }

    private function visibleOrganizationId(ProjectContext $projectContext): ?int
    {
        return in_array($projectContext->role, [
            ProjectOrganizationRole::CONTRACTOR,
            ProjectOrganizationRole::SUBCONTRACTOR,
        ], true) ? $projectContext->organizationId : null;
    }

    private function contractedRevenue(
        Project $project,
        ProjectContext $projectContext,
        ?int $visibleOrganizationId,
    ): ?float {
        $revenueSide = match ($projectContext->role) {
            ProjectOrganizationRole::CONTRACTOR => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR,
            ProjectOrganizationRole::SUBCONTRACTOR => ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR,
            default => ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR,
        };

        $query = Contract::query()
            ->whereNull('contracts.deleted_at')
            ->where('contracts.contract_side_type', $revenueSide->value)
            ->where(function ($query) use ($project): void {
                $query->where('contracts.project_id', $project->getKey())
                    ->orWhereExists(function ($subQuery) use ($project): void {
                        $subQuery->selectRaw('1')
                            ->from('contract_project')
                            ->whereColumn('contract_project.contract_id', 'contracts.id')
                            ->where('contract_project.project_id', $project->getKey());
                    });
            })
            ->when($visibleOrganizationId !== null, function ($query) use ($visibleOrganizationId): void {
                $query->whereHas('contractor', function ($contractorQuery) use ($visibleOrganizationId): void {
                    $contractorQuery->where('source_organization_id', $visibleOrganizationId);
                });
            })
            ->leftJoin('contract_project_allocations as cpa', function ($join) use ($project): void {
                $join->on('cpa.contract_id', '=', 'contracts.id')
                    ->where('cpa.project_id', '=', $project->getKey())
                    ->where('cpa.is_active', true)
                    ->whereNull('cpa.deleted_at');
            })
            ->leftJoinSub(
                DB::table('contract_project')
                    ->select('contract_id')
                    ->selectRaw('COUNT(*) as projects_count')
                    ->groupBy('contract_id'),
                'contract_project_counts',
                'contract_project_counts.contract_id',
                '=',
                'contracts.id',
            );

        $revenue = $query->selectRaw("SUM(
            CASE
                WHEN cpa.allocation_type = 'fixed' AND cpa.allocated_amount IS NOT NULL THEN cpa.allocated_amount
                WHEN cpa.allocation_type = 'percentage' AND cpa.allocated_percentage IS NOT NULL
                    THEN COALESCE(contracts.total_amount, 0) * cpa.allocated_percentage / 100
                WHEN contracts.is_multi_project = true AND COALESCE(contract_project_counts.projects_count, 0) > 0
                    THEN COALESCE(contracts.total_amount, 0) / contract_project_counts.projects_count
                ELSE COALESCE(contracts.total_amount, 0)
            END
        ) as total")->value('total');

        return $revenue !== null && (float) $revenue > 0 ? round((float) $revenue, 2) : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
