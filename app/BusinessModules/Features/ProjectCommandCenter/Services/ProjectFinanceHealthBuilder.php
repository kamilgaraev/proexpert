<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectFinanceHealthData;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Enums\ProjectOrganizationRole;
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
        $payments = $this->paymentFacts($project->getKey(), $visibleOrganizationId, $asOf);

        return $this->fromFacts([
            'metrics' => $metrics,
            'payments' => $payments,
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

    private function paymentFacts(int $projectId, ?int $visibleOrganizationId, CarbonImmutable $asOf): array
    {
        $query = DB::table('payment_documents')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->whereNotNull('due_date')
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

        foreach ($payments as $payment) {
            if (! isset($payment['due_at'], $payment['amount']) || ! in_array($payment['direction'] ?? null, ['incoming', 'outgoing'], true)) {
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
                'accounts_receivable' => null,
                'accounts_payable' => null,
                'projections' => [],
            ];
        }

        $receivable = 0.0;
        $payable = 0.0;
        $projections = [];
        foreach ($buckets as $days => $amounts) {
            $receivable += $amounts['incoming'];
            $payable += $amounts['outgoing'];
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

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
