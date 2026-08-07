<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Exceptions\ReportContractException;
use App\Enums\CurrencyCode;
use App\Models\Project;
use App\Support\Reporting\StableReportingSourceView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ProjectPortfolioHealthOptionsService
{
    public function __construct(private StableReportingSourceView $sourceView) {}

    public function options(ReportScope $scope): array
    {
        try {
            return $this->sourceView->capture(fn (): array => $this->capture($scope));
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
    }

    private function capture(ReportScope $scope): array
    {
        $projects = Project::accessibleByOrganization($scope->organizationId)
            ->whereIn('id', $scope->projectIds)
            ->orderBy('id')
            ->get(['id', 'name', 'status']);
        $projectIds = $projects->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $expectedProjectIds = $scope->projectIds;
        sort($projectIds);
        sort($expectedProjectIds);
        if ($projectIds !== $expectedProjectIds) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        if ($projects->contains(static fn (Project $project): bool => trim((string) $project->name) === '')) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        $statuses = [];
        foreach ($projects as $project) {
            $status = trim((string) $project->status);
            $name = trans_message('reports.options.project_portfolio_health.statuses.'.$status);
            if (! in_array($status, ['active', 'completed', 'paused', 'cancelled'], true)
                || $name === 'reports.options.project_portfolio_health.statuses.'.$status) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $statuses[$status] = ['id' => $status, 'name' => $name];
        }

        $managers = DB::table('project_user')
            ->join('users', 'users.id', '=', 'project_user.user_id')
            ->whereIn('project_user.project_id', $projectIds)
            ->where('project_user.role', 'project_manager')
            ->where('project_user.is_active', true)
            ->whereNull('users.deleted_at')
            ->selectRaw("users.id, NULLIF(TRIM(CONCAT_WS(' ', users.last_name, users.first_name, users.middle_name)), '') AS name")
            ->distinct()
            ->orderBy('name')
            ->orderBy('users.id')
            ->get()
            ->map(fn (object $manager): array => $this->choice($manager->id, $manager->name))
            ->all();
        if (count($managers) !== count(array_filter($managers, static fn (array $manager): bool => $manager['name'] !== ''))) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        $responsibilityCenterIds = $this->dimensionIds('responsibility_center_id', $scope, $projectIds);
        $counterpartyIds = $this->counterpartyIds($scope, $projectIds);
        $currencies = $this->currencies($scope, $projectIds);
        $today = CarbonImmutable::now($scope->timezone)->startOfDay();

        return [
            'period' => ['default_from' => $today->startOfMonth()->format('Y-m-d'), 'default_to' => $today->format('Y-m-d'), 'default_as_of' => $today->format('Y-m-d'), 'max_horizon_days' => 366],
            'projects' => $this->sorted($projects->map(fn (Project $project): array => $this->choice($project->id, $project->name))->all()),
            'managers' => $managers,
            'project_statuses' => $this->sorted(array_values($statuses)),
            'responsibility_centers' => $this->masterChoices('responsibility_centers', $responsibilityCenterIds, $scope->organizationId),
            'counterparties' => $this->masterChoices('contractors', $counterpartyIds, $scope->organizationId),
            'currencies' => $currencies,
            'risk_levels' => $this->riskLevels(),
        ];
    }

    private function dimensionIds(string $column, ReportScope $scope, array $projectIds): array
    {
        return $this->budgetLines($scope, $projectIds)->whereNotNull('budget_lines.'.$column)->pluck('budget_lines.'.$column)
            ->merge(DB::table('payment_documents')->where('organization_id', $scope->organizationId)->whereIn('project_id', $projectIds)->whereNull('deleted_at')->whereNotNull($column)->pluck($column))
            ->map(static fn (mixed $id): string => (string) $id)->unique()->sort()->values()->all();
    }

    private function counterpartyIds(ReportScope $scope, array $projectIds): array
    {
        return $this->budgetLines($scope, $projectIds)->whereNotNull('budget_lines.counterparty_id')->pluck('budget_lines.counterparty_id')
            ->merge(DB::table('payment_documents')->where('organization_id', $scope->organizationId)->whereIn('project_id', $projectIds)->whereNull('deleted_at')
                ->selectRaw('COALESCE(payee_contractor_id, payer_contractor_id) AS counterparty_id')->whereRaw('COALESCE(payee_contractor_id, payer_contractor_id) IS NOT NULL')->pluck('counterparty_id'))
            ->map(static fn (mixed $id): string => (string) $id)->unique()->sort()->values()->all();
    }

    private function masterChoices(string $table, array $ids, int $organizationId): array
    {
        if ($ids === []) {
            return [];
        }
        $choices = DB::table($table)->where('organization_id', $organizationId)->whereIn('id', $ids)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (object $item): array => $this->choice($item->id, $item->name))->all();
        if (count($choices) !== count($ids) || count(array_filter($choices, static fn (array $choice): bool => $choice['name'] !== '')) !== count($choices)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        return $choices;
    }

    private function currencies(ReportScope $scope, array $projectIds): array
    {
        $raw = $this->budgetLines($scope, $projectIds)->whereNotNull('budget_lines.currency')->pluck('budget_lines.currency')
            ->merge(DB::table('payment_documents')->where('organization_id', $scope->organizationId)->whereIn('project_id', $projectIds)->whereNull('deleted_at')->whereNotNull('currency')->pluck('currency'));
        $allowed = CurrencyCode::options();
        $currencies = [];
        foreach ($raw as $currency) {
            $code = strtoupper(trim((string) $currency));
            if (! isset($allowed[$code])) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $currencies[$code] = ['id' => $code, 'name' => $allowed[$code]];
        }
        return $this->sorted(array_values($currencies));
    }

    private function riskLevels(): array
    {
        $choices = [];
        foreach (['low', 'medium', 'high', 'critical'] as $risk) {
            $name = trans_message('reports.options.project_portfolio_health.risk_levels.'.$risk);
            if ($name === 'reports.options.project_portfolio_health.risk_levels.'.$risk) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $choices[] = ['id' => $risk, 'name' => $name];
        }
        return $choices;
    }

    private function budgetLines(ReportScope $scope, array $projectIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('budget_lines')
            ->join('budget_versions', 'budget_versions.id', '=', 'budget_lines.budget_version_id')
            ->where('budget_versions.organization_id', $scope->organizationId)
            ->whereIn('budget_lines.project_id', $projectIds)
            ->whereNull('budget_lines.deleted_at');
    }

    private function choice(mixed $id, mixed $name): array
    {
        return ['id' => is_int($id) ? $id : (string) $id, 'name' => trim((string) $name)];
    }

    private function sorted(array $choices): array
    {
        usort($choices, static fn (array $left, array $right): int => [$left['name'], (string) $left['id']] <=> [$right['name'], (string) $right['id']]);
        return $choices;
    }
}
