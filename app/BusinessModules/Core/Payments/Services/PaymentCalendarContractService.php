<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarCashGapOptions;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastFilters;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

use function trans_message;

final class PaymentCalendarContractService
{
    private CashGapForecastService $cashGapForecastService;

    public function __construct(
        private readonly PaymentCalendarSourceService $sourceService,
        ?CashGapForecastService $cashGapForecastService = null,
    ) {
        $this->cashGapForecastService = $cashGapForecastService ?? new CashGapForecastService();
    }

    public function build(
        PaymentCalendarSourceFilters $filters,
        ?PaymentCalendarCashGapOptions $cashGapOptions = null,
    ): array
    {
        return $this->fromItems($this->sourceService->collect($filters), $filters, $cashGapOptions);
    }

    public function fromItems(
        array $items,
        PaymentCalendarSourceFilters $filters,
        ?PaymentCalendarCashGapOptions $cashGapOptions = null,
    ): array
    {
        $calendarItems = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => $item instanceof PaymentCalendarItem
        ));

        return [
            'items' => array_map(fn (PaymentCalendarItem $item): array => $this->presentItem($item), $calendarItems),
            'events' => array_map(fn (PaymentCalendarItem $item): array => $this->presentEvent($item), $calendarItems),
            'days' => $this->aggregateDays($calendarItems, $filters),
            'summary' => $this->aggregateSummary($calendarItems, $filters),
            'cash_gap' => $this->cashGap($calendarItems, $filters, $cashGapOptions),
        ];
    }

    private function cashGap(
        array $items,
        PaymentCalendarSourceFilters $filters,
        ?PaymentCalendarCashGapOptions $cashGapOptions,
    ): array
    {
        $options = $cashGapOptions ?? new PaymentCalendarCashGapOptions();
        $currency = $filters->currency ?? 'RUB';

        if (!$options->hasOpeningBalance()) {
            return [
                'available' => false,
                'reason' => trans_message('payments.calendar.cash_gap_unavailable_opening_balance'),
                'currency' => $currency,
                'opening_balance' => null,
                'scenario' => $options->scenario,
                'scenario_has_assumptions' => false,
                'scenario_assumptions_count' => 0,
                'forecast' => null,
                'baseline_forecast' => null,
                'comparison' => null,
            ];
        }

        $baselineItems = array_map(
            static fn (PaymentCalendarItem $item) => $item->toCashGapForecastItem(),
            $items
        );
        $baselineContext = $this->cashGapContext(
            $filters,
            $options,
            $currency,
            CashGapForecastContext::SCENARIO_BASE,
            []
        );
        $forecastContext = $this->cashGapContext(
            $filters,
            $options,
            $currency,
            $options->scenario,
            $options->scenarioAdjustments()
        );
        $baselineForecast = $this->cashGapForecastService->forecast($baselineContext, $baselineItems)->toArray();

        if ($options->hasScenarioAssumptions()) {
            $forecast = $this->cashGapForecastService->forecast($forecastContext, $baselineItems)->toArray();
        } else {
            $forecast = $baselineForecast;
            $baselineForecast = null;
        }

        return [
            'available' => true,
            'reason' => null,
            'currency' => $currency,
            'opening_balance' => $this->money((float) $options->openingBalance),
            'scenario' => $options->scenario,
            'scenario_has_assumptions' => $options->hasScenarioAssumptions(),
            'scenario_assumptions_count' => $options->assumptionsCount(),
            'forecast' => $forecast,
            'baseline_forecast' => $baselineForecast,
            'comparison' => $baselineForecast !== null
                ? $this->cashGapComparison($baselineForecast, $forecast)
                : null,
        ];
    }

    private function cashGapContext(
        PaymentCalendarSourceFilters $filters,
        PaymentCalendarCashGapOptions $options,
        string $currency,
        string $scenario,
        array $scenarioAdjustments,
    ): CashGapForecastContext
    {
        return new CashGapForecastContext(
            periodStart: $filters->periodStart,
            periodEnd: $filters->periodEnd,
            openingBalance: (float) $options->openingBalance,
            scenario: $scenario,
            filters: new CashGapForecastFilters(
                organizationId: $filters->organizationId,
                projectId: $filters->projectId,
                counterpartyId: $filters->counterpartyId,
                budgetArticleId: $this->identifierToString($filters->budgetArticleId),
                responsibilityCenterId: $this->identifierToString($filters->responsibilityCenterId),
                currency: $currency,
            ),
            stressInflowDelayDays: $options->stressInflowDelayDays,
            stressInflowProbabilityFactor: $options->stressInflowProbabilityFactor,
            optimisticInflowProbabilityLift: $options->optimisticInflowProbabilityLift,
            optimisticInflowAdvanceDays: $options->optimisticInflowAdvanceDays,
            scenarioAdjustments: $scenarioAdjustments,
        );
    }

    private function cashGapComparison(array $baselineForecast, array $forecast): array
    {
        return [
            'max_gap_amount_delta' => $this->money(
                (float) ($forecast['cash_gap']['max_gap_amount'] ?? 0.0)
                - (float) ($baselineForecast['cash_gap']['max_gap_amount'] ?? 0.0)
            ),
            'negative_days_delta' => (int) ($forecast['cash_gap']['negative_days'] ?? 0)
                - (int) ($baselineForecast['cash_gap']['negative_days'] ?? 0),
            'closing_balance_delta' => $this->money(
                (float) ($forecast['closing_balance'] ?? 0.0)
                - (float) ($baselineForecast['closing_balance'] ?? 0.0)
            ),
            'first_gap_date_changed' => ($forecast['cash_gap']['first_gap_date'] ?? null)
                !== ($baselineForecast['cash_gap']['first_gap_date'] ?? null),
        ];
    }

    private function presentItem(PaymentCalendarItem $item): array
    {
        $documentId = $this->editableDocumentId($item);
        $color = $this->color($item);

        return [
            'id' => $this->calendarItemId($item),
            'document_id' => $documentId,
            'title' => $this->title($item),
            'date' => $item->date,
            'original_date' => $item->originalDate,
            'direction' => $item->direction,
            'direction_label' => $this->directionLabel($item->direction),
            'bucket' => $item->bucket,
            'bucket_label' => $this->bucketLabel($item->bucket),
            'source_type' => $item->sourceType,
            'source_label' => $this->sourceLabel($item->sourceType),
            'source_id' => $item->sourceId,
            'cash_flow_key' => $item->cashFlowKey,
            'amount' => $this->money($item->amount),
            'remaining_amount' => $this->money($item->remainingAmount),
            'currency' => $item->currency,
            'probability' => $item->probability,
            'status' => $item->status,
            'status_label' => $this->statusLabel($item->status),
            'editable' => $item->editable && $documentId !== null,
            'editable_reason' => $this->editableReason($item, $documentId),
            'color' => $color,
            'background_color' => $color,
            'border_color' => $color,
            'project_id' => $item->projectId,
            'counterparty_id' => $item->counterpartyId,
            'budget_article_id' => $item->budgetArticleId,
            'responsibility_center_id' => $item->responsibilityCenterId,
            'drill_down' => $this->drillDown($item, $documentId),
        ];
    }

    private function presentEvent(PaymentCalendarItem $item): array
    {
        $presented = $this->presentItem($item);
        $editable = (bool) $presented['editable'];

        return [
            'id' => $presented['id'],
            'title' => $presented['title'],
            'start' => $item->date,
            'allDay' => true,
            'editable' => $editable,
            'backgroundColor' => $presented['background_color'],
            'borderColor' => $presented['border_color'],
            'textColor' => '#FFFFFF',
            'extendedProps' => [
                'calendarItemId' => $presented['id'],
                'documentId' => $presented['document_id'],
                'amount' => $presented['amount'],
                'remainingAmount' => $presented['remaining_amount'],
                'currency' => $presented['currency'],
                'direction' => $presented['direction'],
                'directionLabel' => $presented['direction_label'],
                'bucket' => $presented['bucket'],
                'bucketLabel' => $presented['bucket_label'],
                'sourceType' => $presented['source_type'],
                'sourceLabel' => $presented['source_label'],
                'cashFlowKey' => $presented['cash_flow_key'],
                'status' => $presented['status'],
                'statusLabel' => $presented['status_label'],
                'editable' => $editable,
                'editableReason' => $presented['editable_reason'],
                'drillDown' => $presented['drill_down'],
            ],
        ];
    }

    private function aggregateDays(array $items, PaymentCalendarSourceFilters $filters): array
    {
        $days = [];
        $periodEndExclusive = (new DateTimeImmutable($filters->periodEnd))->modify('+1 day');

        foreach (new DatePeriod(new DateTimeImmutable($filters->periodStart), new DateInterval('P1D'), $periodEndExclusive) as $date) {
            $days[$date->format('Y-m-d')] = $this->emptyDay($date->format('Y-m-d'));
        }

        foreach ($items as $item) {
            if (!$item instanceof PaymentCalendarItem) {
                continue;
            }

            $date = $item->date;
            $days[$date] ??= $this->emptyDay($date);
            $amount = $this->money($item->remainingAmount);

            $days[$date]['items_count'] += 1;
            $days[$date]['by_currency'][$item->currency] ??= $this->emptyCurrencyFlow();

            if ($item->direction === PaymentCalendarItem::DIRECTION_INFLOW) {
                $days[$date]['inflow'] = $this->money($days[$date]['inflow'] + $amount);
                $days[$date]['by_currency'][$item->currency]['inflow'] = $this->money(
                    $days[$date]['by_currency'][$item->currency]['inflow'] + $amount
                );
            } else {
                $days[$date]['outflow'] = $this->money($days[$date]['outflow'] + $amount);
                $days[$date]['by_currency'][$item->currency]['outflow'] = $this->money(
                    $days[$date]['by_currency'][$item->currency]['outflow'] + $amount
                );
            }

            $days[$date]['net'] = $this->money($days[$date]['inflow'] - $days[$date]['outflow']);
            $days[$date]['by_currency'][$item->currency]['net'] = $this->money(
                $days[$date]['by_currency'][$item->currency]['inflow']
                - $days[$date]['by_currency'][$item->currency]['outflow']
            );

            $this->addBucketAmount($days[$date], $item, $amount);
        }

        ksort($days);

        return array_values($days);
    }

    private function aggregateSummary(array $items, PaymentCalendarSourceFilters $filters): array
    {
        $summary = [
            'period_start' => $filters->periodStart,
            'period_end' => $filters->periodEnd,
            'items_count' => 0,
            'editable_count' => 0,
            'non_editable_count' => 0,
            'overdue_count' => 0,
            'inflow' => 0.0,
            'outflow' => 0.0,
            'net' => 0.0,
            'by_currency' => [],
            'by_direction' => [],
            'by_bucket' => [],
            'by_source_type' => [],
        ];

        foreach ($items as $item) {
            if (!$item instanceof PaymentCalendarItem) {
                continue;
            }

            $amount = $this->money($item->remainingAmount);
            $documentId = $this->editableDocumentId($item);
            $summary['items_count'] += 1;
            $summary[$item->editable && $documentId !== null ? 'editable_count' : 'non_editable_count'] += 1;

            if ($item->bucket === PaymentCalendarItem::BUCKET_OVERDUE) {
                $summary['overdue_count'] += 1;
            }

            $summary['by_currency'][$item->currency] ??= $this->emptyCurrencyFlow();
            $this->addDirectionAmount($summary, $item, $amount);
            $this->addGroupAmount($summary['by_direction'], $item->direction, $this->directionLabel($item->direction), $item, $amount);
            $this->addGroupAmount($summary['by_bucket'], $item->bucket, $this->bucketLabel($item->bucket), $item, $amount);
            $this->addGroupAmount($summary['by_source_type'], $item->sourceType, $this->sourceLabel($item->sourceType), $item, $amount);
        }

        $summary['net'] = $this->money($summary['inflow'] - $summary['outflow']);

        foreach ($summary['by_currency'] as $currency => $flow) {
            $summary['by_currency'][$currency]['net'] = $this->money($flow['inflow'] - $flow['outflow']);
        }

        return $summary;
    }

    private function addDirectionAmount(array &$summary, PaymentCalendarItem $item, float $amount): void
    {
        if ($item->direction === PaymentCalendarItem::DIRECTION_INFLOW) {
            $summary['inflow'] = $this->money($summary['inflow'] + $amount);
            $summary['by_currency'][$item->currency]['inflow'] = $this->money(
                $summary['by_currency'][$item->currency]['inflow'] + $amount
            );

            return;
        }

        $summary['outflow'] = $this->money($summary['outflow'] + $amount);
        $summary['by_currency'][$item->currency]['outflow'] = $this->money(
            $summary['by_currency'][$item->currency]['outflow'] + $amount
        );
    }

    private function addGroupAmount(array &$groups, string $key, string $label, PaymentCalendarItem $item, float $amount): void
    {
        $groups[$key] ??= [
            'key' => $key,
            'label' => $label,
            'items_count' => 0,
            'inflow' => 0.0,
            'outflow' => 0.0,
            'net' => 0.0,
            'by_currency' => [],
        ];

        $groups[$key]['items_count'] += 1;
        $groups[$key]['by_currency'][$item->currency] ??= $this->emptyCurrencyFlow();

        if ($item->direction === PaymentCalendarItem::DIRECTION_INFLOW) {
            $groups[$key]['inflow'] = $this->money($groups[$key]['inflow'] + $amount);
            $groups[$key]['by_currency'][$item->currency]['inflow'] = $this->money(
                $groups[$key]['by_currency'][$item->currency]['inflow'] + $amount
            );
        } else {
            $groups[$key]['outflow'] = $this->money($groups[$key]['outflow'] + $amount);
            $groups[$key]['by_currency'][$item->currency]['outflow'] = $this->money(
                $groups[$key]['by_currency'][$item->currency]['outflow'] + $amount
            );
        }

        $groups[$key]['net'] = $this->money($groups[$key]['inflow'] - $groups[$key]['outflow']);
        $groups[$key]['by_currency'][$item->currency]['net'] = $this->money(
            $groups[$key]['by_currency'][$item->currency]['inflow']
            - $groups[$key]['by_currency'][$item->currency]['outflow']
        );
    }

    private function addBucketAmount(array &$day, PaymentCalendarItem $item, float $amount): void
    {
        $field = match ($item->bucket) {
            PaymentCalendarItem::BUCKET_FACT => $item->direction === PaymentCalendarItem::DIRECTION_INFLOW
                ? 'actual_inflow'
                : 'actual_outflow',
            PaymentCalendarItem::BUCKET_OVERDUE => $item->direction === PaymentCalendarItem::DIRECTION_INFLOW
                ? 'overdue_inflow'
                : 'overdue_outflow',
            PaymentCalendarItem::BUCKET_SCHEDULED => $item->direction === PaymentCalendarItem::DIRECTION_INFLOW
                ? 'scheduled_inflow'
                : 'scheduled_outflow',
            PaymentCalendarItem::BUCKET_APPROVED => $item->direction === PaymentCalendarItem::DIRECTION_INFLOW
                ? 'approved_inflow'
                : 'approved_outflow',
            PaymentCalendarItem::BUCKET_RESERVED => 'reserved_outflow',
            PaymentCalendarItem::BUCKET_BUDGET_PLAN => $item->direction === PaymentCalendarItem::DIRECTION_INFLOW
                ? 'budget_plan_inflow'
                : 'budget_plan_outflow',
            default => 'manual_adjustment',
        };

        $day[$field] = $this->money($day[$field] + $amount);
    }

    private function emptyDay(string $date): array
    {
        return [
            'date' => $date,
            'items_count' => 0,
            'inflow' => 0.0,
            'outflow' => 0.0,
            'net' => 0.0,
            'actual_inflow' => 0.0,
            'actual_outflow' => 0.0,
            'scheduled_inflow' => 0.0,
            'scheduled_outflow' => 0.0,
            'approved_inflow' => 0.0,
            'approved_outflow' => 0.0,
            'reserved_outflow' => 0.0,
            'overdue_inflow' => 0.0,
            'overdue_outflow' => 0.0,
            'budget_plan_inflow' => 0.0,
            'budget_plan_outflow' => 0.0,
            'manual_adjustment' => 0.0,
            'by_currency' => [],
        ];
    }

    private function emptyCurrencyFlow(): array
    {
        return [
            'inflow' => 0.0,
            'outflow' => 0.0,
            'net' => 0.0,
        ];
    }

    private function title(PaymentCalendarItem $item): string
    {
        $amount = number_format($this->money($item->remainingAmount), 0, ',', ' ') . ' ' . $item->currency;
        $label = $item->drillDown['label'] ?? null;

        if (is_string($label) && trim($label) !== '') {
            return $this->directionLabel($item->direction) . ': ' . $amount . ' - ' . trim($label);
        }

        return $this->directionLabel($item->direction) . ': ' . $amount;
    }

    private function calendarItemId(PaymentCalendarItem $item): string
    {
        return $item->sourceType . ':' . (string) ($item->sourceId ?? $item->cashFlowKey);
    }

    private function editableDocumentId(PaymentCalendarItem $item): ?int
    {
        if ($item->sourceType === 'payment_document' && $this->isPositiveInteger($item->sourceId)) {
            return (int) $item->sourceId;
        }

        $documentId = $item->drillDown['payment_document_id'] ?? null;

        if ($this->isPositiveInteger($documentId)) {
            return (int) $documentId;
        }

        return null;
    }

    private function drillDown(PaymentCalendarItem $item, ?int $documentId): array
    {
        $drillDown = [];

        foreach ($item->drillDown as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $drillDown[$key] = $value;
            }
        }

        if ($documentId !== null) {
            $drillDown['payment_document_id'] = $documentId;
            $drillDown['document_href'] = '/payments?tab=documents&document_id=' . $documentId;
        }

        $drillDown['source_label'] = $this->sourceLabel($item->sourceType);

        return $drillDown;
    }

    private function directionLabel(string $direction): string
    {
        return match ($direction) {
            PaymentCalendarItem::DIRECTION_INFLOW => 'Поступление',
            PaymentCalendarItem::DIRECTION_OUTFLOW => 'Оплата',
            default => 'Движение денег',
        };
    }

    private function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            PaymentCalendarItem::BUCKET_FACT => 'Факт',
            PaymentCalendarItem::BUCKET_SCHEDULED => 'По графику',
            PaymentCalendarItem::BUCKET_APPROVED => 'Утверждено',
            PaymentCalendarItem::BUCKET_RESERVED => 'Резерв бюджета',
            PaymentCalendarItem::BUCKET_OVERDUE => 'Просрочено',
            PaymentCalendarItem::BUCKET_BUDGET_PLAN => 'План БДДС',
            PaymentCalendarItem::BUCKET_MANUAL => 'Ручная корректировка',
            default => 'Плановый платеж',
        };
    }

    private function sourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'payment_document' => 'Платежный документ',
            'payment_schedule' => 'График платежей',
            'payment_transaction' => 'Фактический платеж',
            'budget_limit_reservation' => 'Резерв бюджета',
            'budget_amount' => 'План БДДС',
            default => 'Источник платежа',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Черновик',
            'submitted' => 'Отправлен',
            'pending_approval' => 'На согласовании',
            'approved' => 'Утвержден',
            'scheduled' => 'Запланирован',
            'partially_paid' => 'Частично оплачен',
            'paid', 'completed' => 'Оплачен',
            'pending' => 'Ожидает оплаты',
            'reserved' => 'Зарезервировано',
            'active' => 'Активный бюджет',
            'rejected' => 'Отклонен',
            'cancelled' => 'Отменен',
            default => 'В работе',
        };
    }

    private function editableReason(PaymentCalendarItem $item, ?int $documentId): string
    {
        if ($item->editable && $documentId !== null) {
            return 'Можно перенести дату платежа.';
        }

        return match ($item->bucket) {
            PaymentCalendarItem::BUCKET_FACT => 'Фактический платеж уже проведен.',
            PaymentCalendarItem::BUCKET_BUDGET_PLAN => 'План БДДС изменяется в бюджете.',
            PaymentCalendarItem::BUCKET_RESERVED => 'Резерв бюджета не является платежным документом.',
            default => 'Для этого источника перенос даты недоступен.',
        };
    }

    private function color(PaymentCalendarItem $item): string
    {
        return match ($item->bucket) {
            PaymentCalendarItem::BUCKET_FACT => '#16A34A',
            PaymentCalendarItem::BUCKET_SCHEDULED => '#2563EB',
            PaymentCalendarItem::BUCKET_APPROVED => '#D97706',
            PaymentCalendarItem::BUCKET_RESERVED => '#7C3AED',
            PaymentCalendarItem::BUCKET_OVERDUE => '#DC2626',
            PaymentCalendarItem::BUCKET_BUDGET_PLAN => $item->direction === PaymentCalendarItem::DIRECTION_INFLOW
                ? '#0891B2'
                : '#475569',
            default => '#64748B',
        };
    }

    private function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0;
    }

    private function identifierToString(int|string|null $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return (string) $identifier;
    }

    private function money(float|int $amount): float
    {
        return round((float) $amount, 2);
    }
}
