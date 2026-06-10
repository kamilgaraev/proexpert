<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastFilters;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectPortfolioDashboardFilters;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitCheck;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Enums\OneCExchangeStatus;
use App\Models\OneCExchangeConflict;
use App\Models\OneCExchangeOperation;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

use function trans_message;

final class ProjectPortfolioDashboardService
{
    private const DEFAULT_LIMIT = 25;

    public function __construct(
        private readonly ProjectMarginReportService $projectMarginReportService,
        private readonly PlanFactReportService $planFactReportService,
        private readonly WipForecastReportService $wipForecastReportService,
        private readonly PaymentCalendarSourceService $calendarSourceService,
        private readonly CashGapForecastService $cashGapForecastService,
        private readonly ProjectPortfolioDashboardPayloadBuilder $payloadBuilder,
        private readonly ?EpmDataMartFreshnessService $dataMartFreshness = null,
    ) {
    }

    public function dashboard(array $input, ?User $user = null): array
    {
        $filters = $this->resolveFilters($input);
        $projects = $this->projectRegistry($filters);
        $generatedAt = now()->toIso8601String();
        $skipDataMartMeta = ($input['_skip_data_mart_meta'] ?? false) === true;
        $components = [
            'project_margin' => $this->projectMarginComponent($filters, $user, $skipDataMartMeta),
            'plan_fact' => $this->planFactComponent($filters, $skipDataMartMeta),
            'wip_forecast' => $this->wipForecastComponent($filters, $user, $skipDataMartMeta),
            'cash_gap' => $this->cashGapComponent($filters, $projects),
            'limit_risk' => $this->limitRiskComponent($filters),
            'approvals' => $this->approvalsComponent($filters),
            'one_c_exchange' => $this->oneCExchangeComponent($filters),
        ];

        $payload = $this->payloadBuilder->build($filters, $projects, $components, $generatedAt);

        if ($skipDataMartMeta) {
            return $payload;
        }

        return $this->dataMartFreshness()->decoratePayload(
            $payload,
            EpmDataMartScope::fromInput(EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD, $filters->toArray()),
        );
    }

    private function dataMartFreshness(): EpmDataMartFreshnessService
    {
        return $this->dataMartFreshness ?? app(EpmDataMartFreshnessService::class);
    }

    private function resolveFilters(array $input): ProjectPortfolioDashboardFilters
    {
        $organizationId = (int) ($input['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        $periodStart = CarbonImmutable::parse((string) $input['period_start'])->toDateString();
        $periodEnd = CarbonImmutable::parse((string) $input['period_end'])->toDateString();

        if (CarbonImmutable::parse($periodEnd)->lt(CarbonImmutable::parse($periodStart))) {
            throw new InvalidArgumentException(trans_message('budgeting.project_portfolio_dashboard.errors.period_invalid'));
        }

        $asOfDate = CarbonImmutable::parse((string) ($input['as_of_date'] ?? $periodEnd))->toDateString();
        $projectManagerId = $this->nullableInt($input['project_manager_id'] ?? null);

        [$responsibilityCenterId, $responsibilityCenterUuid] = $this->resolveResponsibilityCenter($organizationId, $input['responsibility_center_id'] ?? null);
        $limit = max(1, min(100, (int) ($input['limit'] ?? $input['top_n'] ?? self::DEFAULT_LIMIT)));
        $topN = max(1, min(100, (int) ($input['top_n'] ?? $limit)));

        return new ProjectPortfolioDashboardFilters(
            organizationId: $organizationId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            asOfDate: $asOfDate,
            projectManagerId: $projectManagerId,
            projectStatus: $this->nullableString($input['project_status'] ?? null),
            projectType: $this->nullableString($input['project_type'] ?? null),
            responsibilityCenterId: $responsibilityCenterId,
            responsibilityCenterUuid: $responsibilityCenterUuid,
            currency: $this->nullableCurrency($input['currency'] ?? null),
            limit: $limit,
            topN: $topN,
        );
    }

    private function projectRegistry(ProjectPortfolioDashboardFilters $filters): array
    {
        $query = Project::query()
            ->select(['id', 'organization_id', 'name', 'status', 'additional_info'])
            ->accessibleByOrganization($filters->organizationId)
            ->where('is_archived', false)
            ->with(['users' => function ($query): void {
                $query
                    ->select(['users.id', 'users.name', 'users.email'])
                    ->wherePivot('role', 'project_manager')
                    ->wherePivot('is_active', true);
            }])
            ->when($filters->projectStatus !== null, static fn (Builder $query): Builder => $query->where('status', $filters->projectStatus))
            ->when($filters->projectType !== null, static fn (Builder $query): Builder => $query->where('additional_info->project_type', $filters->projectType))
            ->when($filters->projectManagerId !== null, static function (Builder $query) use ($filters): Builder {
                return $query->whereHas('users', static function (Builder $userQuery) use ($filters): void {
                    $userQuery
                        ->where('users.id', $filters->projectManagerId)
                        ->where('project_user.role', 'project_manager')
                        ->where('project_user.is_active', true);
                });
            })
            ->orderBy('name');

        return $query
            ->get()
            ->mapWithKeys(fn (Project $project): array => [
                (int) $project->id => $this->projectPayload($project),
            ])
            ->all();
    }

    private function projectPayload(Project $project): array
    {
        $manager = $project->users->first();
        $additionalInfo = is_array($project->additional_info) ? $project->additional_info : [];

        return [
            'id' => (int) $project->id,
            'name' => (string) $project->name,
            'status' => (string) $project->status,
            'type' => is_string($additionalInfo['project_type'] ?? null) ? $additionalInfo['project_type'] : null,
            'manager' => $manager instanceof User ? [
                'id' => (int) $manager->id,
                'name' => (string) $manager->name,
                'email' => (string) $manager->email,
            ] : null,
        ];
    }

    private function projectMarginComponent(ProjectPortfolioDashboardFilters $filters, ?User $user, bool $skipDataMartMeta): array
    {
        return $this->reportComponent(
            'project_margin',
            fn (): array => $this->projectMarginReportService->report(
                $this->withDataMartSkip($filters->marginInput(), $skipDataMartMeta),
                $user,
            ),
            static fn (array $report): array => [
                'status' => (string) ($report['meta']['freshness']['status'] ?? 'actual'),
                'generated_at' => $report['meta']['generated_at'] ?? null,
                'source_rows_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['source_rows_count'] ?? 0), $report['rows'] ?? [])),
            ],
        );
    }

    private function planFactComponent(ProjectPortfolioDashboardFilters $filters, bool $skipDataMartMeta): array
    {
        return $this->reportComponent(
            'plan_fact',
            fn (): array => $this->planFactReportService->report(
                $this->withDataMartSkip($filters->planFactInput(), $skipDataMartMeta),
            ),
            static fn (array $report): array => [
                'status' => 'actual',
                'generated_at' => $report['meta']['generated_at'] ?? null,
                'source_rows_count' => count($report['rows'] ?? []),
            ],
        );
    }

    private function wipForecastComponent(ProjectPortfolioDashboardFilters $filters, ?User $user, bool $skipDataMartMeta): array
    {
        if ($filters->responsibilityCenterId !== null) {
            return [
                'available' => true,
                'partial_reason' => 'responsibility_center_filter_not_supported',
                'report' => [
                    'rows' => [],
                    'warnings' => [trans_message('budgeting.project_portfolio_dashboard.warnings.wip_responsibility_center_partial')],
                ],
                'freshness' => [
                    'status' => 'partial',
                    'generated_at' => null,
                ],
            ];
        }

        return $this->reportComponent(
            'wip_forecast',
            fn (): array => $this->wipForecastReportService->report(
                $this->withDataMartSkip($filters->wipForecastInput(), $skipDataMartMeta),
                $user,
            ),
            static fn (array $report): array => is_array($report['freshness'] ?? null)
                ? $report['freshness']
                : ['status' => 'actual', 'generated_at' => $report['meta']['generated_at'] ?? null],
        );
    }

    private function withDataMartSkip(array $input, bool $skipDataMartMeta): array
    {
        if (!$skipDataMartMeta) {
            return $input;
        }

        $input['_skip_data_mart_meta'] = true;

        return $input;
    }

    private function reportComponent(string $component, callable $callback, callable $freshness): array
    {
        try {
            $report = $callback();

            return [
                'available' => true,
                'report' => $report,
                'freshness' => $freshness($report),
            ];
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->unavailableComponent($component, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('budgeting.project_portfolio_dashboard.component_failed', [
                'component' => $component,
                'exception_class' => $exception::class,
            ]);

            return $this->unavailableComponent($component, trans_message('budgeting.project_portfolio_dashboard.warnings.component_unavailable'));
        }
    }

    private function cashGapComponent(ProjectPortfolioDashboardFilters $filters, array $projects): array
    {
        try {
            $calendarItems = $this->calendarSourceService->collect($filters->calendarFilters(), CarbonImmutable::today());
            $forecastItems = array_values(array_filter(array_map(
                static fn (mixed $item): mixed => $item instanceof PaymentCalendarItem ? $item->toCashGapForecastItem() : null,
                $calendarItems,
            )));
            $groups = $this->cashGapGroups($calendarItems, $projects, $filters);
            $rows = [];

            foreach ($groups as $group) {
                $forecast = $this->cashGapForecastService->forecast(
                    new CashGapForecastContext(
                        periodStart: $filters->periodStart,
                        periodEnd: $filters->periodEnd,
                        openingBalance: 0.0,
                        filters: new CashGapForecastFilters(
                            organizationId: $filters->organizationId,
                            projectId: (int) $group['project_id'],
                            responsibilityCenterId: $filters->responsibilityCenterId !== null ? (string) $filters->responsibilityCenterId : null,
                            currency: (string) $group['currency'],
                        ),
                    ),
                    $forecastItems,
                )->toArray();
                $cashGap = is_array($forecast['cash_gap'] ?? null) ? $forecast['cash_gap'] : [];

                $rows[] = [
                    'project_id' => (int) $group['project_id'],
                    'currency' => (string) $group['currency'],
                    'risk_level' => (string) ($forecast['risk_level'] ?? 'low'),
                    'has_gap' => (bool) ($cashGap['has_gap'] ?? false),
                    'first_gap_date' => is_string($cashGap['first_gap_date'] ?? null) ? $cashGap['first_gap_date'] : null,
                    'max_gap_amount' => round((float) ($cashGap['max_gap_amount'] ?? 0.0), 2),
                    'opening_balance' => round((float) ($forecast['opening_balance'] ?? 0.0), 2),
                    'closing_balance' => round((float) ($forecast['closing_balance'] ?? 0.0), 2),
                    'inflows' => round((float) ($forecast['inflows'] ?? 0.0), 2),
                    'outflows' => round((float) ($forecast['outflows'] ?? 0.0), 2),
                    'overdue_receivables' => round((float) ($forecast['overdue_inflows'] ?? 0.0), 2),
                    'overdue_payables' => round((float) ($forecast['overdue_outflows'] ?? 0.0), 2),
                    'freshness_status' => 'actual',
                ];
            }

            return [
                'available' => true,
                'rows' => $rows,
                'freshness' => [
                    'status' => 'actual',
                    'items_count' => count($calendarItems),
                ],
            ];
        } catch (Throwable $exception) {
            Log::error('budgeting.project_portfolio_dashboard.cash_gap_failed', [
                'organization_id' => $filters->organizationId,
                'exception_class' => $exception::class,
            ]);

            return $this->unavailableComponent('cash_gap', trans_message('budgeting.project_portfolio_dashboard.warnings.cash_gap_unavailable'));
        }
    }

    private function cashGapGroups(array $calendarItems, array $projects, ProjectPortfolioDashboardFilters $filters): array
    {
        $groups = [];

        foreach ($calendarItems as $item) {
            if (!$item instanceof PaymentCalendarItem || $item->projectId === null || !isset($projects[$item->projectId])) {
                continue;
            }

            $currency = mb_strtoupper($item->currency);
            if ($filters->currency !== null && $currency !== $filters->currency) {
                continue;
            }

            $groups[$item->projectId . '|' . $currency] = [
                'project_id' => $item->projectId,
                'currency' => $currency,
            ];
        }

        return array_values($groups);
    }

    private function limitRiskComponent(ProjectPortfolioDashboardFilters $filters): array
    {
        try {
            $rows = [];
            $currencyExpression = "UPPER(COALESCE(NULLIF(currency, ''), 'RUB'))";
            $reservationRows = BudgetLimitReservation::query()
                ->where('organization_id', $filters->organizationId)
                ->where('status', BudgetLimitReservation::STATUS_RESERVED)
                ->whereNotNull('project_id')
                ->whereBetween('period_month', [$this->monthStart($filters->periodStart), $this->monthStart($filters->periodEnd)])
                ->when($filters->responsibilityCenterId !== null, static fn (Builder $query): Builder => $query->where('responsibility_center_id', $filters->responsibilityCenterId))
                ->when($filters->currency !== null, static fn (Builder $query): Builder => $query->whereRaw($currencyExpression . ' = ?', [$filters->currency]))
                ->selectRaw('project_id')
                ->selectRaw($currencyExpression . ' AS currency')
                ->selectRaw('SUM(amount) AS reserved_amount')
                ->selectRaw('COUNT(*) AS reserved_count')
                ->groupBy('project_id')
                ->groupByRaw($currencyExpression)
                ->get();

            foreach ($reservationRows as $row) {
                $key = ((int) $row->project_id) . '|' . (string) $row->currency;
                $rows[$key] = [
                    'project_id' => (int) $row->project_id,
                    'currency' => (string) $row->currency,
                    'reserved_amount' => round((float) $row->reserved_amount, 2),
                    'reserved_count' => (int) $row->reserved_count,
                    'warning_count' => 0,
                    'exceeded_count' => 0,
                    'requires_exception_count' => 0,
                    'blocked_count' => 0,
                    'latest_checked_at' => null,
                ];
            }

            $checkRows = BudgetLimitCheck::query()
                ->where('organization_id', $filters->organizationId)
                ->whereNotNull('project_id')
                ->whereBetween('period_month', [$this->monthStart($filters->periodStart), $this->monthStart($filters->periodEnd)])
                ->when($filters->responsibilityCenterId !== null, static fn (Builder $query): Builder => $query->where('responsibility_center_id', $filters->responsibilityCenterId))
                ->when($filters->currency !== null, static fn (Builder $query): Builder => $query->whereRaw($currencyExpression . ' = ?', [$filters->currency]))
                ->selectRaw('project_id')
                ->selectRaw($currencyExpression . ' AS currency')
                ->selectRaw("SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) AS warning_count")
                ->selectRaw("SUM(CASE WHEN status = 'exceeded' THEN 1 ELSE 0 END) AS exceeded_count")
                ->selectRaw("SUM(CASE WHEN status = 'requires_exception' THEN 1 ELSE 0 END) AS requires_exception_count")
                ->selectRaw("SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS blocked_count")
                ->selectRaw('MAX(created_at) AS latest_checked_at')
                ->groupBy('project_id')
                ->groupByRaw($currencyExpression)
                ->get();

            foreach ($checkRows as $row) {
                $key = ((int) $row->project_id) . '|' . (string) $row->currency;
                $rows[$key] ??= [
                    'project_id' => (int) $row->project_id,
                    'currency' => (string) $row->currency,
                    'reserved_amount' => 0.0,
                    'reserved_count' => 0,
                ];

                $rows[$key]['warning_count'] = (int) $row->warning_count;
                $rows[$key]['exceeded_count'] = (int) $row->exceeded_count;
                $rows[$key]['requires_exception_count'] = (int) $row->requires_exception_count;
                $rows[$key]['blocked_count'] = (int) $row->blocked_count;
                $rows[$key]['latest_checked_at'] = $this->date($row->latest_checked_at ?? null);
            }

            return [
                'available' => true,
                'rows' => array_values($rows),
                'freshness' => [
                    'status' => 'actual',
                    'latest_checked_at' => $this->latestValue(array_column($rows, 'latest_checked_at')),
                ],
            ];
        } catch (Throwable $exception) {
            Log::error('budgeting.project_portfolio_dashboard.limit_risk_failed', [
                'organization_id' => $filters->organizationId,
                'exception_class' => $exception::class,
            ]);

            return $this->unavailableComponent('limit_risk', trans_message('budgeting.project_portfolio_dashboard.warnings.component_unavailable'));
        }
    }

    private function approvalsComponent(ProjectPortfolioDashboardFilters $filters): array
    {
        try {
            $currencyExpression = "UPPER(COALESCE(NULLIF(payment_documents.currency, ''), 'RUB'))";
            $rows = PaymentApproval::query()
                ->join('payment_documents', 'payment_documents.id', '=', 'payment_approvals.payment_document_id')
                ->where('payment_approvals.organization_id', $filters->organizationId)
                ->where('payment_approvals.status', 'pending')
                ->whereIn('payment_documents.status', [
                    PaymentDocumentStatus::SUBMITTED->value,
                    PaymentDocumentStatus::PENDING_APPROVAL->value,
                ])
                ->whereNull('payment_documents.deleted_at')
                ->whereNotNull('payment_documents.project_id')
                ->when($filters->responsibilityCenterId !== null, static fn (Builder $query): Builder => $query->where('payment_documents.responsibility_center_id', $filters->responsibilityCenterId))
                ->when($filters->currency !== null, static fn (Builder $query): Builder => $query->whereRaw($currencyExpression . ' = ?', [$filters->currency]))
                ->selectRaw('payment_documents.project_id AS project_id')
                ->selectRaw($currencyExpression . ' AS currency')
                ->selectRaw('COUNT(payment_approvals.id) AS pending_count')
                ->selectRaw('COUNT(DISTINCT payment_documents.id) AS pending_documents_count')
                ->selectRaw('MAX(payment_approvals.created_at) AS latest_pending_created_at')
                ->groupBy('payment_documents.project_id')
                ->groupByRaw($currencyExpression)
                ->get()
                ->map(static fn (object $row): array => [
                    'project_id' => (int) $row->project_id,
                    'currency' => (string) $row->currency,
                    'pending_count' => (int) $row->pending_count,
                    'pending_documents_count' => (int) $row->pending_documents_count,
                    'latest_pending_created_at' => $row->latest_pending_created_at,
                ])
                ->all();

            return [
                'available' => true,
                'rows' => $rows,
                'freshness' => [
                    'status' => 'actual',
                    'latest_pending_created_at' => $this->latestValue(array_column($rows, 'latest_pending_created_at')),
                ],
            ];
        } catch (Throwable $exception) {
            Log::error('budgeting.project_portfolio_dashboard.approvals_failed', [
                'organization_id' => $filters->organizationId,
                'exception_class' => $exception::class,
            ]);

            return $this->unavailableComponent('approvals', trans_message('budgeting.project_portfolio_dashboard.warnings.component_unavailable'));
        }
    }

    private function oneCExchangeComponent(ProjectPortfolioDashboardFilters $filters): array
    {
        try {
            if (!Schema::hasTable('one_c_exchange_operations')) {
                return $this->unavailableComponent('one_c_exchange', trans_message('budgeting.project_portfolio_dashboard.warnings.one_c_unavailable'));
            }

            $base = OneCExchangeOperation::query()->where('organization_id', $filters->organizationId);
            $problemStatuses = [
                OneCExchangeStatus::Failed->value,
                OneCExchangeStatus::Rejected->value,
                OneCExchangeStatus::RequiresMapping->value,
                OneCExchangeStatus::DeadLetter->value,
            ];
            $successStatuses = [
                OneCExchangeStatus::Accepted->value,
                OneCExchangeStatus::Posted->value,
                OneCExchangeStatus::Completed->value,
            ];
            $problemCount = (int) (clone $base)->whereIn('status', $problemStatuses)->count();
            $lastSuccessAt = $this->date((clone $base)->whereIn('status', $successStatuses)->max('updated_at'));
            $lastFailureAt = $this->date((clone $base)->whereIn('status', $problemStatuses)->max('updated_at'));
            $openConflictsCount = $this->openOneCConflictsCount($filters->organizationId);
            $criticalConflictsCount = $this->criticalOneCConflictsCount($filters->organizationId);
            $status = $problemCount > 0 || $openConflictsCount > 0 ? 'warning' : 'actual';
            if ($criticalConflictsCount > 0 || (clone $base)->where('status', OneCExchangeStatus::DeadLetter->value)->exists()) {
                $status = 'critical';
            }

            return [
                'available' => true,
                'summary' => [
                    'problem_count' => $problemCount,
                    'open_conflicts_count' => $openConflictsCount,
                    'critical_conflicts_count' => $criticalConflictsCount,
                    'last_success_at' => $lastSuccessAt,
                    'last_failure_at' => $lastFailureAt,
                    'health' => $status === 'actual' ? 'ok' : $status,
                ],
                'freshness' => [
                    'status' => $status,
                    'last_success_at' => $lastSuccessAt,
                    'last_failure_at' => $lastFailureAt,
                    'problem_count' => $problemCount,
                    'open_conflicts_count' => $openConflictsCount,
                ],
            ];
        } catch (Throwable $exception) {
            Log::error('budgeting.project_portfolio_dashboard.one_c_failed', [
                'organization_id' => $filters->organizationId,
                'exception_class' => $exception::class,
            ]);

            return $this->unavailableComponent('one_c_exchange', trans_message('budgeting.project_portfolio_dashboard.warnings.one_c_unavailable'));
        }
    }

    private function unavailableComponent(string $component, string $warning): array
    {
        return [
            'available' => false,
            'report' => [
                'rows' => [],
                'warnings' => [$warning],
            ],
            'rows' => [],
            'freshness' => [
                'status' => 'unavailable',
                'warning' => $warning,
            ],
        ];
    }

    private function resolveResponsibilityCenter(int $organizationId, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        $center = ResponsibilityCenter::query()
            ->where('organization_id', $organizationId)
            ->where(static function (Builder $query) use ($value): void {
                if (is_numeric($value)) {
                    $query->whereKey((int) $value);
                }

                $query->orWhere('uuid', (string) $value);
            })
            ->first();

        if (!$center instanceof ResponsibilityCenter) {
            throw new DomainException(trans_message('budgeting.cfo.not_found'));
        }

        return [(int) $center->id, (string) $center->uuid];
    }

    private function openOneCConflictsCount(int $organizationId): int
    {
        if (!Schema::hasTable('one_c_exchange_conflicts')) {
            return 0;
        }

        return (int) OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['open', 'in_review', 'postponed', 'assigned'])
            ->count();
    }

    private function criticalOneCConflictsCount(int $organizationId): int
    {
        if (!Schema::hasTable('one_c_exchange_conflicts')) {
            return 0;
        }

        return (int) OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['open', 'in_review', 'postponed', 'assigned'])
            ->whereIn('severity', ['critical', 'high'])
            ->count();
    }

    private function monthStart(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfMonth()->toDateString();
    }

    private function latestValue(array $values): ?string
    {
        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''));
        rsort($values);

        return $values[0] ?? null;
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullableCurrency(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_strtoupper(trim($value)) : null;
    }
}
