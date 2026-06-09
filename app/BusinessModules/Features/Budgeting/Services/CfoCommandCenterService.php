<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CfoCommandCenterFilters;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitCheck;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Enums\OneCExchangeStatus;
use App\Models\Contractor;
use App\Models\OneCExchangeConflict;
use App\Models\OneCExchangeOperation;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class CfoCommandCenterService
{
    private const MAX_RANGE_DAYS = 180;
    private const DEFAULT_ITEM_LIMIT = 10;
    private const UPCOMING_WINDOW_DAYS = 14;

    private const CASH_GAP_RISK_RANK = [
        CashGapForecastService::RISK_LOW => 1,
        CashGapForecastService::RISK_MEDIUM => 2,
        CashGapForecastService::RISK_HIGH => 3,
        CashGapForecastService::RISK_CRITICAL => 4,
    ];

    public function __construct(
        private readonly PaymentCalendarSourceService $calendarSourceService,
        private readonly CashGapOpeningBalanceService $openingBalanceService,
        private readonly CashGapForecastService $cashGapForecastService,
        private readonly PlanFactReportService $planFactReportService,
        private readonly CfoCommandCenterPayloadBuilder $payloadBuilder,
    ) {
    }

    public function dashboard(array $input): array
    {
        $filters = $this->resolveFilters($input);
        $generatedAt = now()->toIso8601String();
        $today = CarbonImmutable::today();
        $calendarItems = $this->calendarSourceService->collect($filters->calendarFilters(), $today);
        $calendar = $this->calendarSection($filters, $calendarItems, $today);
        $cashGap = $this->cashGapSection($filters, $calendarItems);
        $limits = $this->limitsSection($filters);
        $planFact = $this->planFactSection($filters);
        $approvals = $this->approvalsSection($filters);
        $oneCExchange = $this->oneCExchangeSection($filters);

        $items = [
            'upcoming_payments' => $this->calendarItems(
                $calendarItems,
                $filters->itemLimit,
                fn (PaymentCalendarItem $item): bool => $this->isUpcomingOutflow($item, $calendar['summary']['window']),
            ),
            'expected_inflows' => $this->calendarItems(
                $calendarItems,
                $filters->itemLimit,
                fn (PaymentCalendarItem $item): bool => $this->isExpectedInflow($item, $calendar['summary']['window']),
            ),
            'overdue' => $this->calendarItems(
                $calendarItems,
                $filters->itemLimit,
                static fn (PaymentCalendarItem $item): bool => $item->bucket === PaymentCalendarItem::BUCKET_OVERDUE,
            ),
            'limit_overruns' => $limits['items'],
            'plan_fact_deviations' => $planFact['items'],
            'approval_blockers' => $approvals['items'],
            'one_c_exchange_issues' => $oneCExchange['items'],
        ];

        unset($limits['items'], $planFact['items'], $approvals['items'], $oneCExchange['items']);

        return $this->payloadBuilder->build(
            filters: $filters->toArray(),
            aggregates: [
                'cash_gap' => $cashGap,
                'payment_calendar' => $calendar,
                'limits' => $limits,
                'plan_fact' => $planFact,
                'approvals' => $approvals,
                'one_c_exchange' => $oneCExchange,
            ],
            items: $items,
            sourceOfTruth: $this->sourceOfTruth(),
            freshness: $this->freshness($generatedAt, $calendar, $cashGap, $limits, $planFact, $approvals, $oneCExchange),
            generatedAt: $generatedAt,
            itemLimit: $filters->itemLimit,
        );
    }

    private function resolveFilters(array $input): CfoCommandCenterFilters
    {
        $organizationId = $this->resolveOrganizationId($input);
        $periodStart = CarbonImmutable::parse((string) $input['period_start'])->toDateString();
        $periodEnd = CarbonImmutable::parse((string) $input['period_end'])->toDateString();
        $start = CarbonImmutable::parse($periodStart);
        $end = CarbonImmutable::parse($periodEnd);

        if ($end->lt($start)) {
            throw new InvalidArgumentException(trans_message('budgeting.cfo_command_center.errors.period_invalid'));
        }

        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException(trans_message('budgeting.cfo_command_center.errors.range_too_large'));
        }

        $projectId = $this->resolveScopedProject($organizationId, $input['project_id'] ?? null, $input['current_project_id'] ?? null);
        [$responsibilityCenterId, $responsibilityCenterUuid] = $this->resolveCatalogFilter(
            ResponsibilityCenter::class,
            $organizationId,
            $input['responsibility_center_id'] ?? null,
            trans_message('budgeting.cfo.not_found'),
        );
        [$budgetArticleId, $budgetArticleUuid] = $this->resolveCatalogFilter(
            BudgetArticle::class,
            $organizationId,
            $input['budget_article_id'] ?? null,
            trans_message('budgeting.articles.not_found'),
        );
        $counterpartyId = $this->resolveCounterpartyFilter($organizationId, $input['counterparty_id'] ?? null);
        $currency = $this->nullableCurrency($input['currency'] ?? null);
        $itemLimit = max(1, min(50, (int) ($input['item_limit'] ?? self::DEFAULT_ITEM_LIMIT)));

        return new CfoCommandCenterFilters(
            organizationId: $organizationId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            projectId: $projectId,
            responsibilityCenterId: $responsibilityCenterId,
            responsibilityCenterUuid: $responsibilityCenterUuid,
            budgetArticleId: $budgetArticleId,
            budgetArticleUuid: $budgetArticleUuid,
            counterpartyId: $counterpartyId,
            currency: $currency,
            budgetVersionUuid: $this->nullableString($input['budget_version_uuid'] ?? null),
            scenarioUuid: $this->nullableString($input['scenario_uuid'] ?? null),
            itemLimit: $itemLimit,
        );
    }

    private function resolveOrganizationId(array $input): int
    {
        $currentOrganizationId = $input['current_organization_id'] ?? null;

        if ($currentOrganizationId === null || (int) $currentOrganizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        $requestedOrganizationId = $input['organization_id'] ?? null;

        if ($requestedOrganizationId !== null && (int) $requestedOrganizationId !== (int) $currentOrganizationId) {
            throw new DomainException(trans_message('budgeting.cfo_command_center.errors.organization_mismatch'));
        }

        return (int) $currentOrganizationId;
    }

    private function resolveScopedProject(int $organizationId, mixed $requestedProjectId, mixed $currentProjectId): ?int
    {
        $projectId = $requestedProjectId === null || $requestedProjectId === '' ? null : (int) $requestedProjectId;
        $scopedProjectId = $currentProjectId === null || $currentProjectId === '' ? null : (int) $currentProjectId;

        if ($scopedProjectId !== null && $scopedProjectId > 0) {
            if ($projectId === null) {
                $projectId = $scopedProjectId;
            } elseif ($projectId !== $scopedProjectId) {
                throw new DomainException(trans_message('budgeting.cfo_command_center.errors.project_scope_mismatch'));
            }
        }

        if ($projectId === null) {
            return null;
        }

        $exists = Project::query()
            ->whereKey($projectId)
            ->accessibleByOrganization($organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('budgeting.lines.project_not_found'));
        }

        return $projectId;
    }

    private function resolveCounterpartyFilter(int $organizationId, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $counterpartyId = (int) $value;
        $exists = Contractor::query()
            ->where('organization_id', $organizationId)
            ->whereKey($counterpartyId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('budgeting.lines.counterparty_not_found'));
        }

        return $counterpartyId;
    }

    private function resolveCatalogFilter(string $modelClass, int $organizationId, mixed $value, string $message): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        $query = $modelClass === BudgetArticle::class
            ? BudgetArticle::query()
            : ResponsibilityCenter::query();
        $model = $query
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($value): void {
                if (is_numeric($value)) {
                    $query->whereKey((int) $value);
                }

                $query->orWhere('uuid', (string) $value);
            })
            ->first();

        if (!$model) {
            throw new DomainException($message);
        }

        return [(int) $model->getKey(), (string) $model->getAttribute('uuid')];
    }

    private function calendarSection(
        CfoCommandCenterFilters $filters,
        array $items,
        CarbonImmutable $today,
    ): array {
        $windowEnd = $this->minDate($filters->periodEnd, $today->addDays(self::UPCOMING_WINDOW_DAYS)->toDateString());
        $summary = [
            'period' => $filters->period(),
            'window' => [
                'from' => max($filters->periodStart, $today->toDateString()),
                'to' => $windowEnd,
                'days' => self::UPCOMING_WINDOW_DAYS,
            ],
            'items_count' => 0,
            'inflow_amount' => 0.0,
            'outflow_amount' => 0.0,
            'net_amount' => 0.0,
            'upcoming_outflow_amount' => 0.0,
            'expected_inflow_amount' => 0.0,
            'overdue_count' => 0,
            'overdue_outflow_amount' => 0.0,
            'overdue_inflow_amount' => 0.0,
            'by_currency' => [],
            'by_bucket' => [],
        ];

        foreach ($items as $item) {
            if (!$item instanceof PaymentCalendarItem) {
                continue;
            }

            $amount = $this->money($item->remainingAmount);
            $summary['items_count']++;
            $summary['by_currency'][$item->currency] ??= $this->emptyCurrencyFlow();
            $summary['by_bucket'][$item->bucket] ??= $this->emptyCurrencyFlow();

            if ($item->direction === PaymentCalendarItem::DIRECTION_INFLOW) {
                $summary['inflow_amount'] = $this->money($summary['inflow_amount'] + $amount);
                $summary['by_currency'][$item->currency]['inflow'] = $this->money($summary['by_currency'][$item->currency]['inflow'] + $amount);
                $summary['by_bucket'][$item->bucket]['inflow'] = $this->money($summary['by_bucket'][$item->bucket]['inflow'] + $amount);
            } else {
                $summary['outflow_amount'] = $this->money($summary['outflow_amount'] + $amount);
                $summary['by_currency'][$item->currency]['outflow'] = $this->money($summary['by_currency'][$item->currency]['outflow'] + $amount);
                $summary['by_bucket'][$item->bucket]['outflow'] = $this->money($summary['by_bucket'][$item->bucket]['outflow'] + $amount);
            }

            if ($this->isUpcomingOutflow($item, $summary['window'])) {
                $summary['upcoming_outflow_amount'] = $this->money($summary['upcoming_outflow_amount'] + $amount);
            }

            if ($this->isExpectedInflow($item, $summary['window'])) {
                $summary['expected_inflow_amount'] = $this->money($summary['expected_inflow_amount'] + $amount);
            }

            if ($item->bucket === PaymentCalendarItem::BUCKET_OVERDUE) {
                $summary['overdue_count']++;
                $field = $item->direction === PaymentCalendarItem::DIRECTION_INFLOW
                    ? 'overdue_inflow_amount'
                    : 'overdue_outflow_amount';
                $summary[$field] = $this->money($summary[$field] + $amount);
            }
        }

        $summary['net_amount'] = $this->money($summary['inflow_amount'] - $summary['outflow_amount']);
        $summary['by_currency'] = $this->finishFlowGroups($summary['by_currency']);
        $summary['by_bucket'] = $this->finishFlowGroups($summary['by_bucket']);

        return [
            'summary' => $summary,
        ];
    }

    private function cashGapSection(CfoCommandCenterFilters $filters, array $calendarItems): array
    {
        $currencies = $filters->currency !== null ? [$filters->currency] : $this->currencies($calendarItems);
        $balances = $this->openingBalanceService->latestApprovedByCurrency(
            $filters->organizationId,
            $filters->periodStart,
            $currencies,
        );
        $forecastItems = array_values(array_filter(
            array_map(
                static fn (mixed $item): mixed => $item instanceof PaymentCalendarItem ? $item->toCashGapForecastItem() : null,
                $calendarItems,
            ),
        ));
        $forecasts = [];
        $positions = [];
        $unavailableCurrencies = [];
        $highestRisk = CashGapForecastService::RISK_LOW;
        $hasGap = false;
        $firstGapDate = null;
        $maxGapAmount = 0.0;

        foreach ($currencies as $currency) {
            $balance = $balances[$currency] ?? null;

            if ($balance === null) {
                $unavailableCurrencies[] = $currency;
                continue;
            }

            $forecast = $this->cashGapForecastService->forecast(
                new CashGapForecastContext(
                    periodStart: $filters->periodStart,
                    periodEnd: $filters->periodEnd,
                    openingBalance: $balance->amount,
                    filters: $filters->cashGapFilters($currency),
                ),
                $forecastItems,
            )->toArray();

            $forecasts[$currency] = [
                'currency' => $currency,
                'opening_balance' => $forecast['opening_balance'],
                'closing_balance' => $forecast['closing_balance'],
                'inflows' => $forecast['inflows'],
                'outflows' => $forecast['outflows'],
                'cash_gap' => $forecast['cash_gap'],
                'risk_level' => $forecast['risk_level'],
                'signals' => $forecast['signals'],
                'daily' => $forecast['daily'],
                'opening_balance_source' => [
                    'id' => $balance->id,
                    'balance_date' => $balance->balanceDate,
                    'approved_at' => $balance->approvedAt,
                ],
            ];
            $positions[$currency] = [
                'opening_balance' => $forecast['opening_balance'],
                'closing_balance' => $forecast['closing_balance'],
                'net_forecast' => $this->money((float) $forecast['closing_balance'] - (float) $forecast['opening_balance']),
                'opening_balance_date' => $balance->balanceDate,
            ];

            $cashGap = is_array($forecast['cash_gap'] ?? null) ? $forecast['cash_gap'] : [];
            $hasGap = $hasGap || (bool) ($cashGap['has_gap'] ?? false);
            $firstGapDate = $this->minNullableDate($firstGapDate, is_string($cashGap['first_gap_date'] ?? null) ? $cashGap['first_gap_date'] : null);
            $maxGapAmount = max($maxGapAmount, (float) ($cashGap['max_gap_amount'] ?? 0.0));
            $highestRisk = $this->highestCashGapRisk($highestRisk, (string) ($forecast['risk_level'] ?? CashGapForecastService::RISK_LOW));
        }

        return [
            'available' => $forecasts !== [],
            'currencies' => array_values(array_keys($forecasts)),
            'requested_currencies' => $currencies,
            'unavailable_currencies' => $unavailableCurrencies,
            'has_gap' => $hasGap,
            'first_gap_date' => $firstGapDate,
            'max_gap_amount' => $this->money($maxGapAmount),
            'highest_risk_level' => $highestRisk,
            'cash_position_by_currency' => $positions,
            'forecasts_by_currency' => $forecasts,
        ];
    }

    private function limitsSection(CfoCommandCenterFilters $filters): array
    {
        $reservationQuery = $this->applyBudgetDimensionFilters(
            BudgetLimitReservation::query()
                ->where('organization_id', $filters->organizationId)
                ->where('status', BudgetLimitReservation::STATUS_RESERVED)
                ->whereBetween('period_month', [$filters->periodStartMonth(), $filters->periodEndMonth()]),
            $filters,
        );
        $checkQuery = $this->applyBudgetDimensionFilters(
            BudgetLimitCheck::query()
                ->where('organization_id', $filters->organizationId)
                ->whereBetween('period_month', [$filters->periodStartMonth(), $filters->periodEndMonth()]),
            $filters,
        );
        $statusCounts = $this->budgetLimitStatusCounts($checkQuery);
        $items = $this->limitItems($checkQuery, $filters->itemLimit);

        return [
            'summary' => [
                'reserved_amount' => $this->money((clone $reservationQuery)->sum('amount')),
                'reserved_count' => (int) (clone $reservationQuery)->count(),
                'warning_count' => $statusCounts['warning'] ?? 0,
                'exceeded_count' => $statusCounts['exceeded'] ?? 0,
                'requires_exception_count' => $statusCounts['requires_exception'] ?? 0,
                'blocked_count' => $statusCounts['blocked'] ?? 0,
                'latest_checked_at' => $this->date((clone $checkQuery)->max('created_at')),
            ],
            'status_counts' => $statusCounts,
            'items' => $items,
        ];
    }

    private function planFactSection(CfoCommandCenterFilters $filters): array
    {
        try {
            $report = $this->planFactReportService->report($filters->planFactInput());
            $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
            $criticalRowsCount = $this->riskRowsCount($rows, 'critical');
            $highRowsCount = $this->riskRowsCount($rows, 'high');
            $summary = array_merge(is_array($report['summary'] ?? null) ? $report['summary'] : [], [
                'critical_rows_count' => $criticalRowsCount,
                'high_rows_count' => $highRowsCount,
                'max_negative_variance' => $this->maxNegativeVariance($rows),
            ]);

            return [
                'available' => true,
                'summary' => $summary,
                'totals_by_currency' => $report['totals_by_currency'] ?? [],
                'sources_coverage' => $report['sources_coverage'] ?? [],
                'warnings' => $report['warnings'] ?? [],
                'meta' => $report['meta'] ?? [],
                'items' => $this->planFactDeviationItems($rows, $filters->itemLimit),
            ];
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->unavailablePlanFact($exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('budgeting.cfo_command_center.plan_fact_failed', [
                'organization_id' => $filters->organizationId,
                'exception_class' => $exception::class,
            ]);

            return $this->unavailablePlanFact(trans_message('budgeting.plan_fact.load_error'));
        }
    }

    private function approvalsSection(CfoCommandCenterFilters $filters): array
    {
        $base = PaymentApproval::query()
            ->select('payment_approvals.*')
            ->join('payment_documents', 'payment_documents.id', '=', 'payment_approvals.payment_document_id')
            ->where('payment_approvals.organization_id', $filters->organizationId)
            ->where('payment_approvals.status', 'pending')
            ->whereNull('payment_documents.deleted_at')
            ->whereIn('payment_documents.status', $this->approvalDocumentStatuses());

        $this->applyPaymentDocumentFilters($base, $filters);

        $roleCounts = (clone $base)
            ->select('payment_approvals.approval_role')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('payment_approvals.approval_role')
            ->pluck('count', 'approval_role')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
        $items = (clone $base)
            ->with('paymentDocument')
            ->orderByRaw('COALESCE(payment_documents.due_date, payment_documents.document_date, payment_documents.created_at)')
            ->orderBy('payment_approvals.created_at')
            ->limit($filters->itemLimit)
            ->get()
            ->map(fn (PaymentApproval $approval): array => $this->approvalItem($approval))
            ->values()
            ->all();

        return [
            'summary' => [
                'pending_count' => (int) (clone $base)->count(),
                'pending_documents_count' => (int) (clone $base)->distinct()->count('payment_documents.id'),
                'latest_pending_created_at' => $this->date((clone $base)->max('payment_approvals.created_at')),
                'by_role' => $roleCounts,
            ],
            'items' => $items,
        ];
    }

    private function oneCExchangeSection(CfoCommandCenterFilters $filters): array
    {
        if (!$this->hasTable('one_c_exchange_operations')) {
            return $this->unavailableOneCExchange(trans_message('budgeting.cfo_command_center.one_c.unavailable'));
        }

        try {
            $now = CarbonImmutable::now();
            $operationBase = OneCExchangeOperation::query()->where('organization_id', $filters->organizationId);
            $statusCounts = (clone $operationBase)
                ->selectRaw('status, COUNT(*) AS count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();
            $staleProcessingCount = (int) (clone $operationBase)
                ->where('status', OneCExchangeStatus::Processing->value)
                ->whereNotNull('started_at')
                ->where('started_at', '<=', $now->subMinutes($this->oneCProcessingTimeoutMinutes()))
                ->count();
            $overdueRetryCount = (int) (clone $operationBase)
                ->where('status', OneCExchangeStatus::RetryScheduled->value)
                ->whereNotNull('next_retry_at')
                ->where('next_retry_at', '<', $now)
                ->count();
            $openConflictsCount = $this->openOneCConflictsCount($filters->organizationId);
            $criticalConflictsCount = $this->criticalOneCConflictsCount($filters->organizationId);
            $problemCount = $this->oneCProblemCount($statusCounts) + $staleProcessingCount + $overdueRetryCount + $openConflictsCount;
            $health = $this->oneCHealth($statusCounts, $staleProcessingCount, $criticalConflictsCount, $openConflictsCount);
            $items = $this->oneCProblemItems($operationBase, $filters->organizationId, $filters->itemLimit, $now);

            return [
                'available' => true,
                'summary' => [
                    'health' => $health,
                    'problem_count' => $problemCount,
                    'open_conflicts_count' => $openConflictsCount,
                    'critical_conflicts_count' => $criticalConflictsCount,
                    'stale_processing_count' => $staleProcessingCount,
                    'overdue_retry_count' => $overdueRetryCount,
                    'backlog_count' => $this->oneCBacklogCount($statusCounts),
                    'failed_count' => (int) ($statusCounts[OneCExchangeStatus::Failed->value] ?? 0),
                    'dead_letter_count' => (int) ($statusCounts[OneCExchangeStatus::DeadLetter->value] ?? 0),
                    'requires_mapping_count' => (int) ($statusCounts[OneCExchangeStatus::RequiresMapping->value] ?? 0),
                    'last_success_at' => $this->lastOneCStatusAt($operationBase, [
                        OneCExchangeStatus::Delivered->value,
                        OneCExchangeStatus::Accepted->value,
                        OneCExchangeStatus::Posted->value,
                        OneCExchangeStatus::Completed->value,
                    ]),
                    'last_failure_at' => $this->lastOneCStatusAt($operationBase, [
                        OneCExchangeStatus::Failed->value,
                        OneCExchangeStatus::DeadLetter->value,
                        OneCExchangeStatus::Rejected->value,
                        OneCExchangeStatus::RequiresMapping->value,
                    ]),
                ],
                'status_counts' => $statusCounts,
                'items' => $items,
            ];
        } catch (Throwable $exception) {
            Log::error('budgeting.cfo_command_center.one_c_failed', [
                'organization_id' => $filters->organizationId,
                'exception_class' => $exception::class,
            ]);

            return $this->unavailableOneCExchange(trans_message('budgeting.cfo_command_center.one_c.unavailable'));
        }
    }

    private function applyBudgetDimensionFilters(Builder $query, CfoCommandCenterFilters $filters): Builder
    {
        return $query
            ->when($filters->projectId !== null, static fn (Builder $scope): Builder => $scope->where('project_id', $filters->projectId))
            ->when($filters->responsibilityCenterId !== null, static fn (Builder $scope): Builder => $scope->where('responsibility_center_id', $filters->responsibilityCenterId))
            ->when($filters->budgetArticleId !== null, static fn (Builder $scope): Builder => $scope->where('budget_article_id', $filters->budgetArticleId))
            ->when($filters->counterpartyId !== null, static fn (Builder $scope): Builder => $scope->where('counterparty_id', $filters->counterpartyId))
            ->when($filters->currency !== null, static fn (Builder $scope): Builder => $scope->where('currency', $filters->currency));
    }

    private function applyPaymentDocumentFilters(Builder $query, CfoCommandCenterFilters $filters): void
    {
        $query
            ->when($filters->projectId !== null, static fn (Builder $scope): Builder => $scope->where('payment_documents.project_id', $filters->projectId))
            ->when($filters->responsibilityCenterId !== null, static fn (Builder $scope): Builder => $scope->where('payment_documents.responsibility_center_id', $filters->responsibilityCenterId))
            ->when($filters->budgetArticleId !== null, static fn (Builder $scope): Builder => $scope->where('payment_documents.budget_article_id', $filters->budgetArticleId))
            ->when($filters->counterpartyId !== null, static function (Builder $scope) use ($filters): Builder {
                return $scope->where(function (Builder $counterparty) use ($filters): void {
                    $counterparty
                        ->where('payment_documents.contractor_id', $filters->counterpartyId)
                        ->orWhere('payment_documents.payee_contractor_id', $filters->counterpartyId)
                        ->orWhere('payment_documents.payer_contractor_id', $filters->counterpartyId);
                });
            })
            ->when($filters->currency !== null, static fn (Builder $scope): Builder => $scope->where('payment_documents.currency', $filters->currency));

        $this->applyPaymentDocumentPeriodFilter($query, $filters);
    }

    private function applyPaymentDocumentPeriodFilter(Builder $query, CfoCommandCenterFilters $filters): void
    {
        $startDateTime = CarbonImmutable::parse($filters->periodStart)->startOfDay()->toDateTimeString();
        $endExclusive = CarbonImmutable::parse($filters->periodEnd)->addDay()->startOfDay()->toDateTimeString();

        $query->where(function (Builder $dateQuery) use ($filters, $startDateTime, $endExclusive): void {
            $dateQuery
                ->where(function (Builder $scope) use ($startDateTime, $endExclusive): void {
                    $scope
                        ->where('payment_documents.scheduled_at', '>=', $startDateTime)
                        ->where('payment_documents.scheduled_at', '<', $endExclusive);
                })
                ->orWhereBetween('payment_documents.due_date', [$filters->periodStart, $filters->periodEnd])
                ->orWhere(function (Builder $scope) use ($filters): void {
                    $scope
                        ->whereNull('payment_documents.scheduled_at')
                        ->whereNull('payment_documents.due_date')
                        ->whereBetween('payment_documents.document_date', [$filters->periodStart, $filters->periodEnd]);
                })
                ->orWhere(function (Builder $scope) use ($filters): void {
                    $scope
                        ->whereNotNull('payment_documents.due_date')
                        ->where('payment_documents.due_date', '<', $filters->periodStart);
                });
        });
    }

    private function budgetLimitStatusCounts(Builder $query): array
    {
        $counts = [
            'available' => 0,
            'warning' => 0,
            'exceeded' => 0,
            'requires_exception' => 0,
            'blocked' => 0,
        ];

        (clone $query)
            ->selectRaw('status, COUNT(*) AS count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->each(static function (mixed $count, string $status) use (&$counts): void {
                $counts[$status] = (int) $count;
            });

        return $counts;
    }

    private function limitItems(Builder $query, int $limit): array
    {
        return (clone $query)
            ->with('paymentDocument')
            ->whereIn('status', ['blocked', 'requires_exception', 'exceeded', 'warning'])
            ->orderByRaw("CASE WHEN status = 'blocked' THEN 0 WHEN status = 'requires_exception' THEN 1 WHEN status = 'exceeded' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (BudgetLimitCheck $check): array => [
                'id' => (int) $check->getKey(),
                'uuid' => (string) $check->uuid,
                'payment_document_id' => $check->payment_document_id !== null ? (int) $check->payment_document_id : null,
                'document_number' => $check->paymentDocument?->document_number,
                'status' => (string) $check->status,
                'decision' => (string) $check->decision,
                'message' => (string) $check->message,
                'requested_amount' => $this->money($check->requested_amount),
                'currency' => (string) $check->currency,
                'period_month' => $this->date($check->period_month),
                'project_id' => $check->project_id !== null ? (int) $check->project_id : null,
                'budget_article_id' => $check->budget_article_id !== null ? (int) $check->budget_article_id : null,
                'responsibility_center_id' => $check->responsibility_center_id !== null ? (int) $check->responsibility_center_id : null,
                'checked_at' => $this->date($check->created_at),
                'drill_down' => [
                    'payment_document_id' => $check->payment_document_id !== null ? (int) $check->payment_document_id : null,
                    'href' => $check->payment_document_id !== null
                        ? '/payments?tab=documents&document_id=' . (int) $check->payment_document_id
                        : null,
                ],
            ])
            ->values()
            ->all();
    }

    private function approvalItem(PaymentApproval $approval): array
    {
        $document = $approval->paymentDocument;
        $approvalRole = (string) $approval->approval_role;

        return [
            'id' => (int) $approval->getKey(),
            'payment_document_id' => $approval->payment_document_id !== null ? (int) $approval->payment_document_id : null,
            'document_number' => $document?->document_number,
            'approval_role' => $approvalRole,
            'approval_role_label' => $this->approvalRoleLabel($approvalRole),
            'approval_level' => (int) $approval->approval_level,
            'amount' => $document !== null ? $this->money($document->remaining_amount ?? $document->amount) : 0.0,
            'currency' => $document?->currency,
            'due_date' => $this->date($document?->due_date),
            'created_at' => $this->date($approval->created_at),
            'drill_down' => [
                'payment_document_id' => $approval->payment_document_id !== null ? (int) $approval->payment_document_id : null,
                'href' => $approval->payment_document_id !== null
                    ? '/payments?tab=approvals&document_id=' . (int) $approval->payment_document_id
                    : null,
            ],
        ];
    }

    private function approvalRoleLabel(string $role): string
    {
        return match ($role) {
            'financial_director' => trans_message('budgeting.cfo_command_center.approval_roles.financial_director'),
            'chief_accountant' => trans_message('budgeting.cfo_command_center.approval_roles.chief_accountant'),
            'accountant' => trans_message('budgeting.cfo_command_center.approval_roles.accountant'),
            'project_manager' => trans_message('budgeting.cfo_command_center.approval_roles.project_manager'),
            'department_head' => trans_message('budgeting.cfo_command_center.approval_roles.department_head'),
            'general_director' => trans_message('budgeting.cfo_command_center.approval_roles.general_director'),
            'admin' => trans_message('budgeting.cfo_command_center.approval_roles.admin'),
            default => trans_message('budgeting.cfo_command_center.approval_roles.unknown'),
        };
    }

    private function oneCProblemItems(Builder $operationBase, int $organizationId, int $limit, CarbonImmutable $now): array
    {
        $items = (clone $operationBase)
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereIn('status', [
                        OneCExchangeStatus::Failed->value,
                        OneCExchangeStatus::DeadLetter->value,
                        OneCExchangeStatus::RequiresMapping->value,
                    ])
                    ->orWhere(function (Builder $processing) use ($now): void {
                        $processing
                            ->where('status', OneCExchangeStatus::Processing->value)
                            ->whereNotNull('started_at')
                            ->where('started_at', '<=', $now->subMinutes($this->oneCProcessingTimeoutMinutes()));
                    })
                    ->orWhere(function (Builder $retry) use ($now): void {
                        $retry
                            ->where('status', OneCExchangeStatus::RetryScheduled->value)
                            ->whereNotNull('next_retry_at')
                            ->where('next_retry_at', '<', $now);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'dead_letter' THEN 0 WHEN status = 'failed' THEN 1 WHEN status = 'requires_mapping' THEN 2 ELSE 3 END")
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (OneCExchangeOperation $operation): array => $this->oneCOperationItem($operation))
            ->values()
            ->all();

        if (count($items) >= $limit || !$this->hasTable('one_c_exchange_conflicts')) {
            return $items;
        }

        $remaining = $limit - count($items);
        $conflicts = OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['open', 'in_review', 'postponed', 'assigned'])
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 WHEN severity = 'warning' THEN 1 ELSE 2 END")
            ->orderBy('due_at')
            ->orderByDesc('detected_at')
            ->limit($remaining)
            ->get()
            ->map(fn (OneCExchangeConflict $conflict): array => $this->oneCConflictItem($conflict))
            ->values()
            ->all();

        return array_values(array_merge($items, $conflicts));
    }

    private function oneCOperationItem(OneCExchangeOperation $operation): array
    {
        return [
            'type' => 'operation',
            'id' => (int) $operation->getKey(),
            'status' => (string) $operation->status,
            'severity' => in_array($operation->status, ['dead_letter', 'failed'], true) ? 'critical' : 'warning',
            'message' => $this->oneCOperationMessage((string) $operation->status),
            'scope' => (string) $operation->scope,
            'direction' => (string) $operation->direction,
            'entity_type' => $operation->entity_type,
            'entity_id' => $operation->entity_id,
            'next_retry_at' => $this->date($operation->next_retry_at),
            'last_attempt_at' => $this->date($operation->last_attempt_at),
            'updated_at' => $this->date($operation->updated_at),
            'drill_down' => [
                'operation_id' => (int) $operation->getKey(),
                'href' => '/one-c-exchange/journal/' . (int) $operation->getKey(),
            ],
        ];
    }

    private function oneCConflictItem(OneCExchangeConflict $conflict): array
    {
        return [
            'type' => 'conflict',
            'id' => (int) $conflict->getKey(),
            'status' => (string) $conflict->status,
            'severity' => (string) $conflict->severity,
            'message' => (string) $conflict->title,
            'scope' => (string) $conflict->scope,
            'entity_type' => $conflict->entity_type,
            'entity_id' => $conflict->entity_id,
            'detected_at' => $this->date($conflict->detected_at),
            'due_at' => $this->date($conflict->due_at),
            'drill_down' => [
                'conflict_id' => (int) $conflict->getKey(),
                'href' => '/one-c-exchange/conflicts/' . (int) $conflict->getKey(),
            ],
        ];
    }

    private function planFactDeviationItems(array $rows, int $limit): array
    {
        usort($rows, fn (array $left, array $right): int => [
            -$this->planFactRiskRank((string) ($left['risk_level'] ?? 'low')),
            -abs((float) ($left['variance_amount'] ?? 0.0)),
        ] <=> [
            -$this->planFactRiskRank((string) ($right['risk_level'] ?? 'low')),
            -abs((float) ($right['variance_amount'] ?? 0.0)),
        ]);

        return array_values(array_map(
            fn (array $row): array => [
                'drill_down_key' => $row['drill_down_key'] ?? null,
                'group' => $row['group'] ?? [],
                'budget_article' => $row['budget_article'] ?? null,
                'responsibility_center' => $row['responsibility_center'] ?? null,
                'project' => $row['project'] ?? null,
                'currency' => (string) ($row['currency'] ?? ''),
                'plan_amount' => $this->money($row['plan_amount'] ?? 0.0),
                'forecast_amount' => $this->money($row['forecast_amount'] ?? 0.0),
                'actual_amount' => $this->money($row['actual_amount'] ?? 0.0),
                'committed_amount' => $this->money($row['committed_amount'] ?? 0.0),
                'variance_amount' => $this->money($row['variance_amount'] ?? 0.0),
                'variance_percent' => $row['variance_percent'] ?? null,
                'risk_level' => (string) ($row['risk_level'] ?? 'low'),
                'drill_down' => [
                    'href' => '/budgeting/plan-fact?drill_down_key=' . rawurlencode((string) ($row['drill_down_key'] ?? '')),
                ],
            ],
            array_slice($rows, 0, $limit),
        ));
    }

    private function calendarItems(array $items, int $limit, callable $predicate): array
    {
        $filtered = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => $item instanceof PaymentCalendarItem && $predicate($item),
        ));

        usort($filtered, static fn (PaymentCalendarItem $left, PaymentCalendarItem $right): int => [
            $left->date,
            -$left->remainingAmount,
            $left->sourceType,
            (string) $left->sourceId,
        ] <=> [
            $right->date,
            -$right->remainingAmount,
            $right->sourceType,
            (string) $right->sourceId,
        ]);

        return array_map(fn (PaymentCalendarItem $item): array => $this->paymentCalendarItem($item), array_slice($filtered, 0, $limit));
    }

    private function paymentCalendarItem(PaymentCalendarItem $item): array
    {
        return [
            'id' => $item->sourceType . ':' . (string) ($item->sourceId ?? $item->cashFlowKey),
            'title' => $this->calendarItemTitle($item),
            'date' => $item->date,
            'original_date' => $item->originalDate,
            'direction' => $item->direction,
            'bucket' => $item->bucket,
            'source_type' => $item->sourceType,
            'source_id' => $item->sourceId,
            'cash_flow_key' => $item->cashFlowKey,
            'amount' => $this->money($item->amount),
            'remaining_amount' => $this->money($item->remainingAmount),
            'currency' => $item->currency,
            'probability' => $item->probability,
            'status' => $item->status,
            'project_id' => $item->projectId,
            'counterparty_id' => $item->counterpartyId,
            'budget_article_id' => $item->budgetArticleId,
            'responsibility_center_id' => $item->responsibilityCenterId,
            'drill_down' => $this->scalarDrillDown($item),
        ];
    }

    private function calendarItemTitle(PaymentCalendarItem $item): string
    {
        $label = $item->drillDown['label'] ?? $item->drillDown['document_number'] ?? null;

        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        return trans_message('budgeting.cfo_command_center.items.cash_flow');
    }

    private function scalarDrillDown(PaymentCalendarItem $item): array
    {
        $drillDown = [];

        foreach ($item->drillDown as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $drillDown[$key] = $value;
            }
        }

        $documentId = $drillDown['payment_document_id'] ?? ($item->sourceType === 'payment_document' ? $item->sourceId : null);

        if (is_numeric($documentId) && (int) $documentId > 0) {
            $drillDown['payment_document_id'] = (int) $documentId;
            $drillDown['href'] = '/payments?tab=documents&document_id=' . (int) $documentId;
        }

        return $drillDown;
    }

    private function unavailablePlanFact(string $reason): array
    {
        return [
            'available' => false,
            'summary' => [
                'rows_count' => 0,
                'currencies' => [],
                'highest_risk_level' => 'low',
                'critical_rows_count' => 0,
                'high_rows_count' => 0,
                'max_negative_variance' => 0.0,
            ],
            'totals_by_currency' => [],
            'sources_coverage' => [],
            'warnings' => [$reason],
            'meta' => [],
            'unavailable_reason' => $reason,
            'items' => [],
        ];
    }

    private function unavailableOneCExchange(string $reason): array
    {
        return [
            'available' => false,
            'summary' => [
                'health' => 'unknown',
                'problem_count' => 0,
                'open_conflicts_count' => 0,
                'critical_conflicts_count' => 0,
                'stale_processing_count' => 0,
                'overdue_retry_count' => 0,
                'backlog_count' => 0,
                'failed_count' => 0,
                'dead_letter_count' => 0,
                'requires_mapping_count' => 0,
                'last_success_at' => null,
                'last_failure_at' => null,
                'unavailable_reason' => $reason,
            ],
            'status_counts' => [],
            'items' => [],
        ];
    }

    private function sourceOfTruth(): array
    {
        return [
            'payment_calendar' => [
                'primary' => 'prohelper_payment_documents_schedules_transactions_budget_plan',
                'accounting' => 'not_regulated_accounting',
            ],
            'cash_gap' => [
                'primary' => 'prohelper_management_forecast',
                'opening_balance' => 'approved_management_opening_balance',
            ],
            'limits' => [
                'primary' => 'prohelper_budget_limit_checks_and_reservations',
            ],
            'plan_fact' => [
                'primary' => 'prohelper_management_budget_and_completed_payments',
                'accounting' => 'management_only',
            ],
            'approvals' => [
                'primary' => 'prohelper_payment_approval_workflow',
            ],
            'one_c_exchange' => [
                'primary' => 'prohelper_1c_exchange_monitoring',
                'scope' => 'integration_health_only',
            ],
        ];
    }

    private function freshness(
        string $generatedAt,
        array $calendar,
        array $cashGap,
        array $limits,
        array $planFact,
        array $approvals,
        array $oneCExchange,
    ): array {
        return [
            'payment_calendar' => [
                'generated_at' => $generatedAt,
                'items_count' => (int) ($calendar['summary']['items_count'] ?? 0),
            ],
            'cash_gap' => [
                'generated_at' => $generatedAt,
                'available' => (bool) ($cashGap['available'] ?? false),
                'unavailable_currencies' => $cashGap['unavailable_currencies'] ?? [],
            ],
            'limits' => [
                'generated_at' => $generatedAt,
                'latest_checked_at' => $limits['summary']['latest_checked_at'] ?? null,
            ],
            'plan_fact' => [
                'generated_at' => $planFact['meta']['generated_at'] ?? $generatedAt,
                'available' => (bool) ($planFact['available'] ?? false),
                'warnings_count' => count($planFact['warnings'] ?? []),
            ],
            'approvals' => [
                'generated_at' => $generatedAt,
                'latest_pending_created_at' => $approvals['summary']['latest_pending_created_at'] ?? null,
            ],
            'one_c_exchange' => [
                'generated_at' => $generatedAt,
                'available' => (bool) ($oneCExchange['available'] ?? false),
                'last_success_at' => $oneCExchange['summary']['last_success_at'] ?? null,
                'last_failure_at' => $oneCExchange['summary']['last_failure_at'] ?? null,
                'filter_scope' => 'organization',
            ],
        ];
    }

    private function isUpcomingOutflow(PaymentCalendarItem $item, array $window): bool
    {
        return $item->direction === PaymentCalendarItem::DIRECTION_OUTFLOW
            && $item->bucket !== PaymentCalendarItem::BUCKET_FACT
            && $this->dateInWindow($item->date, $window);
    }

    private function isExpectedInflow(PaymentCalendarItem $item, array $window): bool
    {
        return $item->direction === PaymentCalendarItem::DIRECTION_INFLOW
            && !in_array($item->bucket, [PaymentCalendarItem::BUCKET_FACT, PaymentCalendarItem::BUCKET_OVERDUE], true)
            && $this->dateInWindow($item->date, $window);
    }

    private function dateInWindow(string $date, array $window): bool
    {
        return $date >= (string) ($window['from'] ?? '') && $date <= (string) ($window['to'] ?? '');
    }

    private function currencies(array $items): array
    {
        $currencies = [];

        foreach ($items as $item) {
            if ($item instanceof PaymentCalendarItem && $item->currency !== '') {
                $currencies[$item->currency] = true;
            }
        }

        if ($currencies === []) {
            return ['RUB'];
        }

        ksort($currencies);

        return array_values(array_keys($currencies));
    }

    private function finishFlowGroups(array $groups): array
    {
        foreach ($groups as $key => $flow) {
            $groups[$key]['net'] = $this->money((float) $flow['inflow'] - (float) $flow['outflow']);
        }

        ksort($groups);

        return $groups;
    }

    private function emptyCurrencyFlow(): array
    {
        return [
            'inflow' => 0.0,
            'outflow' => 0.0,
            'net' => 0.0,
        ];
    }

    private function riskRowsCount(array $rows, string $riskLevel): int
    {
        return count(array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row) && ($row['risk_level'] ?? null) === $riskLevel,
        ));
    }

    private function maxNegativeVariance(array $rows): float
    {
        $min = 0.0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $min = min($min, (float) ($row['variance_amount'] ?? 0.0));
        }

        return $this->money($min);
    }

    private function planFactRiskRank(string $riskLevel): int
    {
        return [
            'low' => 1,
            'medium' => 2,
            'high' => 3,
            'critical' => 4,
        ][$riskLevel] ?? 0;
    }

    private function highestCashGapRisk(string $left, string $right): string
    {
        return (self::CASH_GAP_RISK_RANK[$right] ?? 0) > (self::CASH_GAP_RISK_RANK[$left] ?? 0)
            ? $right
            : $left;
    }

    private function oneCProblemCount(array $statusCounts): int
    {
        return (int) ($statusCounts[OneCExchangeStatus::Failed->value] ?? 0)
            + (int) ($statusCounts[OneCExchangeStatus::DeadLetter->value] ?? 0)
            + (int) ($statusCounts[OneCExchangeStatus::RequiresMapping->value] ?? 0);
    }

    private function oneCBacklogCount(array $statusCounts): int
    {
        return (int) ($statusCounts[OneCExchangeStatus::Pending->value] ?? 0)
            + (int) ($statusCounts[OneCExchangeStatus::Queued->value] ?? 0)
            + (int) ($statusCounts[OneCExchangeStatus::RetryScheduled->value] ?? 0);
    }

    private function oneCHealth(
        array $statusCounts,
        int $staleProcessingCount,
        int $criticalConflictsCount,
        int $openConflictsCount,
    ): string {
        if (
            (int) ($statusCounts[OneCExchangeStatus::DeadLetter->value] ?? 0) > 0
            || $staleProcessingCount > 0
            || $criticalConflictsCount > 0
        ) {
            return 'critical';
        }

        if ($this->oneCProblemCount($statusCounts) > 0 || $this->oneCBacklogCount($statusCounts) > 0 || $openConflictsCount > 0) {
            return 'warning';
        }

        return 'ok';
    }

    private function openOneCConflictsCount(int $organizationId): int
    {
        if (!$this->hasTable('one_c_exchange_conflicts')) {
            return 0;
        }

        return (int) OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['open', 'in_review', 'postponed', 'assigned'])
            ->count();
    }

    private function criticalOneCConflictsCount(int $organizationId): int
    {
        if (!$this->hasTable('one_c_exchange_conflicts')) {
            return 0;
        }

        return (int) OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['open', 'in_review', 'postponed', 'assigned'])
            ->where('severity', 'critical')
            ->count();
    }

    private function lastOneCStatusAt(Builder $query, array $statuses): ?string
    {
        return $this->date(
            (clone $query)
                ->whereIn('status', $statuses)
                ->selectRaw('MAX(COALESCE(finished_at, updated_at)) AS value')
                ->value('value'),
        );
    }

    private function oneCOperationMessage(string $status): string
    {
        return match ($status) {
            OneCExchangeStatus::DeadLetter->value => trans_message('budgeting.cfo_command_center.one_c.dead_letter'),
            OneCExchangeStatus::Failed->value => trans_message('budgeting.cfo_command_center.one_c.failed'),
            OneCExchangeStatus::RequiresMapping->value => trans_message('budgeting.cfo_command_center.one_c.requires_mapping'),
            OneCExchangeStatus::Processing->value => trans_message('budgeting.cfo_command_center.one_c.stale_processing'),
            OneCExchangeStatus::RetryScheduled->value => trans_message('budgeting.cfo_command_center.one_c.overdue_retry'),
            default => trans_message('budgeting.cfo_command_center.one_c.attention_required'),
        };
    }

    private function oneCProcessingTimeoutMinutes(): int
    {
        return max(1, (int) config('one_c_exchange.delivery.processing_timeout_minutes', 15));
    }

    private function approvalDocumentStatuses(): array
    {
        return [
            PaymentDocumentStatus::SUBMITTED->value,
            PaymentDocumentStatus::PENDING_APPROVAL->value,
        ];
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function nullableCurrency(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strtoupper(trim($value));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value) === '' ? null : trim((string) $value);
    }

    private function minDate(string $left, string $right): string
    {
        return $left <= $right ? $left : $right;
    }

    private function minNullableDate(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return $this->minDate($left, $right);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value)->toIso8601String();
        }

        return null;
    }

    private function money(mixed $amount): float
    {
        return round((float) $amount, 2);
    }
}
