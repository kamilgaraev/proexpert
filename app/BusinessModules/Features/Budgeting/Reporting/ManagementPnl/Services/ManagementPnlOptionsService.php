<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlReadinessSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Readiness\ManagementPnlReadinessProbe;
use App\Models\Project;
use InvalidArgumentException;

final readonly class ManagementPnlOptionsService
{
    public function __construct(private ManagementPnlReadinessProbe $readiness) {}

    /** @return array<string,mixed> */
    public function options(ReportExecutionContext $context, ReportQuery $query): array
    {
        if ($context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw new InvalidArgumentException('management_pnl_options_scope_mismatch');
        }
        $snapshot = $this->readiness->inspect($context->scope, $query);

        return [
            'availability' => self::availability($snapshot),
            'currencies' => self::currencyOptions($snapshot),
            'scenarios' => self::scenarioOptions($snapshot),
            'projects' => $this->dimensionOptions(Project::class, $context->scope->organizationId, $snapshot->projectIds),
            'responsibility_centers' => $this->dimensionOptions(ResponsibilityCenter::class, $context->scope->organizationId, $snapshot->responsibilityCenterIds),
            'budget_articles' => $this->dimensionOptions(BudgetArticle::class, $context->scope->organizationId, $snapshot->budgetArticleIds),
            'period' => [
                'from' => (string) ($query->filters->values['period_from'] ?? ''),
                'to' => (string) ($query->filters->values['period_to'] ?? ''),
            ],
        ];
    }

    /** @return array{status:string,can_run:bool} */
    public static function availability(ManagementPnlReadinessSnapshot $snapshot): array
    {
        $status = match (true) {
            $snapshot->factCount === 0 => 'no_data',
            $snapshot->hasActivePolicy && $snapshot->hasExactSealedTuple => 'available',
            default => 'source_incomplete',
        };

        return ['status' => $status, 'can_run' => $status === 'available'];
    }

    /** @return list<array{id:string,name:string}> */
    public static function currencyOptions(ManagementPnlReadinessSnapshot $snapshot): array
    {
        $labels = [
            'RUB' => trans_message('reports.management_pnl.currencies.RUB'),
            'USD' => trans_message('reports.management_pnl.currencies.USD'),
            'EUR' => trans_message('reports.management_pnl.currencies.EUR'),
        ];
        $codes = array_values(array_unique(array_map('mb_strtoupper', $snapshot->currencies)));
        sort($codes, SORT_STRING);

        return array_values(array_map(
            static fn (string $code): array => ['id' => $code, 'name' => $labels[$code] ?? $code],
            $codes,
        ));
    }

    /** @return list<array{id:string,name:string}> */
    public static function scenarioOptions(ManagementPnlReadinessSnapshot $snapshot): array
    {
        $labels = [
            'actual' => trans_message('reports.management_pnl.scenarios.actual'),
            'forecast' => trans_message('reports.management_pnl.scenarios.forecast'),
            'plan' => trans_message('reports.management_pnl.scenarios.plan'),
        ];
        $scenarios = array_values(array_unique($snapshot->scenarios));
        sort($scenarios, SORT_STRING);

        return array_values(array_map(
            static fn (string $scenario): array => ['id' => $scenario, 'name' => $labels[$scenario] ?? $scenario],
            $scenarios,
        ));
    }

    /** @param  class-string<\Illuminate\Database\Eloquent\Model>  $model */
    private function dimensionOptions(string $model, int $organizationId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $model::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn ($item): array => ['id' => (int) $item->id, 'name' => (string) $item->name])
            ->values()
            ->all();
    }
}
