<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Services\BudgetWorkflowService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

final class PaymentCalendarSourceService
{
    public function collect(PaymentCalendarSourceFilters $filters, ?DateTimeInterface $today = null): array
    {
        $items = $this->collectPaymentTransactionItems($filters);

        [$scheduleItems, $scheduledDocumentIds] = $this->collectPaymentScheduleItems($filters, $today);

        $items = array_merge(
            $items,
            $scheduleItems,
            $this->collectPaymentDocumentItems($filters, $today, $scheduledDocumentIds),
            $this->collectBudgetLimitReservationItems($filters, $today, $scheduledDocumentIds),
            $this->collectBudgetPlanItems($filters),
        );

        return $this->normalizeItems($items, $filters);
    }

    public function normalizeItems(array $items, PaymentCalendarSourceFilters $filters): array
    {
        $byCashFlowKey = [];
        $withoutKey = [];

        foreach ($items as $item) {
            if (!$item instanceof PaymentCalendarItem || !$filters->matches($item)) {
                continue;
            }

            if ($item->cashFlowKey === '') {
                $withoutKey[] = $item;
                continue;
            }

            $current = $byCashFlowKey[$item->cashFlowKey] ?? null;

            if (
                !$current instanceof PaymentCalendarItem
                || $this->calendarPriority($item) > $this->calendarPriority($current)
            ) {
                $byCashFlowKey[$item->cashFlowKey] = $item;
            }
        }

        $normalized = array_merge(array_values($byCashFlowKey), $withoutKey);

        usort(
            $normalized,
            fn (PaymentCalendarItem $left, PaymentCalendarItem $right): int => [
                $left->date,
                -$this->calendarPriority($left),
                $left->sourceType,
                (string) $left->sourceId,
            ] <=> [
                $right->date,
                -$this->calendarPriority($right),
                $right->sourceType,
                (string) $right->sourceId,
            ]
        );

        return $normalized;
    }

    public function fromPaymentDocument(PaymentDocument $document, ?DateTimeInterface $today = null): ?PaymentCalendarItem
    {
        $status = $this->documentStatusValue($document);

        if (!in_array($status, $this->activeDocumentStatuses(), true)) {
            return null;
        }

        $direction = $this->paymentDocumentDirection($document);
        $date = $this->effectiveDocumentDate($document);
        $amount = $this->positive((float) $document->amount);
        $remainingAmount = $this->documentRemainingAmount($document);

        if ($direction === null || $date === null || $amount <= 0.0 || $remainingAmount <= 0.0) {
            return null;
        }

        $bucket = $this->isOverdue($date, $today, $document->overdue_since !== null)
            ? PaymentCalendarItem::BUCKET_OVERDUE
            : $this->documentBucket($status);

        $sourceId = $this->modelId($document);

        return new PaymentCalendarItem(
            organizationId: (int) $document->organization_id,
            date: $date,
            originalDate: $this->documentOriginalDate($document, $date),
            direction: $direction,
            bucket: $bucket,
            amount: $amount,
            remainingAmount: $remainingAmount,
            currency: $this->currency($document->currency),
            probability: $this->documentProbability($direction, $status),
            status: $status,
            sourceType: 'payment_document',
            sourceId: $sourceId,
            cashFlowKey: $this->paymentDocumentCashFlowKey($document),
            projectId: $this->nullableInt($document->project_id),
            counterpartyId: $this->paymentDocumentCounterpartyId($document, $direction),
            budgetArticleId: $document->budget_article_id,
            responsibilityCenterId: $document->responsibility_center_id,
            editable: in_array($status, [
                PaymentDocumentStatus::APPROVED->value,
                PaymentDocumentStatus::SCHEDULED->value,
                PaymentDocumentStatus::PARTIALLY_PAID->value,
            ], true),
            drillDown: [
                'type' => 'payment_document',
                'id' => $sourceId,
                'document_number' => $document->document_number,
                'source_type' => $document->source_type,
                'source_id' => $document->source_id,
                'label' => $document->document_number,
            ],
        );
    }

    public function fromPaymentSchedule(PaymentSchedule $schedule, ?DateTimeInterface $today = null): ?PaymentCalendarItem
    {
        $document = $this->loadedPaymentDocument($schedule);
        $date = $this->dateString($schedule->due_date);
        $amount = $this->positive((float) $schedule->amount);
        $remainingAmount = $this->positive($amount - (float) $schedule->paid_amount);

        if (
            !$document instanceof PaymentDocument
            || $schedule->status !== 'pending'
            || $date === null
            || $amount <= 0.0
            || $remainingAmount <= 0.0
        ) {
            return null;
        }

        $direction = $this->paymentDocumentDirection($document);

        if ($direction === null) {
            return null;
        }

        $documentDate = $this->effectiveDocumentDate($document);
        $sourceId = $this->modelId($schedule);

        return new PaymentCalendarItem(
            organizationId: (int) $document->organization_id,
            date: $date,
            originalDate: $documentDate !== null && $documentDate !== $date ? $documentDate : null,
            direction: $direction,
            bucket: $this->isOverdue($date, $today) ? PaymentCalendarItem::BUCKET_OVERDUE : PaymentCalendarItem::BUCKET_SCHEDULED,
            amount: $amount,
            remainingAmount: $remainingAmount,
            currency: $this->currency($document->currency),
            probability: 1.0,
            status: (string) $schedule->status,
            sourceType: 'payment_schedule',
            sourceId: $sourceId,
            cashFlowKey: $this->paymentScheduleCashFlowKey($schedule),
            projectId: $this->nullableInt($document->project_id),
            counterpartyId: $this->paymentDocumentCounterpartyId($document, $direction),
            budgetArticleId: $document->budget_article_id,
            responsibilityCenterId: $document->responsibility_center_id,
            editable: true,
            drillDown: [
                'type' => 'payment_schedule',
                'id' => $sourceId,
                'payment_document_id' => $this->modelId($document),
                'installment_number' => $schedule->installment_number,
                'label' => $document->document_number,
            ],
        );
    }

    public function fromPaymentTransaction(PaymentTransaction $transaction): ?PaymentCalendarItem
    {
        if ($this->transactionStatusValue($transaction) !== PaymentTransactionStatus::COMPLETED->value) {
            return null;
        }

        $valueDate = $this->dateString($transaction->value_date);
        $transactionDate = $this->dateString($transaction->transaction_date);
        $date = $valueDate ?? $transactionDate;
        $amount = $this->positive((float) $transaction->amount);
        $document = $this->loadedPaymentDocument($transaction);
        $direction = $document instanceof PaymentDocument
            ? $this->paymentDocumentDirection($document)
            : $this->transactionDirection($transaction);

        if ($date === null || $direction === null || $amount <= 0.0) {
            return null;
        }

        $sourceId = $this->modelId($transaction);

        return new PaymentCalendarItem(
            organizationId: (int) $transaction->organization_id,
            date: $date,
            originalDate: $transactionDate !== null && $transactionDate !== $date ? $transactionDate : null,
            direction: $direction,
            bucket: PaymentCalendarItem::BUCKET_FACT,
            amount: $amount,
            remainingAmount: $amount,
            currency: $this->currency($transaction->currency),
            probability: 1.0,
            status: PaymentTransactionStatus::COMPLETED->value,
            sourceType: 'payment_transaction',
            sourceId: $sourceId,
            cashFlowKey: $this->paymentTransactionCashFlowKey($transaction),
            projectId: $this->nullableInt($transaction->project_id ?? $document?->project_id),
            counterpartyId: $this->transactionCounterpartyId($transaction, $document, $direction),
            budgetArticleId: $document?->budget_article_id,
            responsibilityCenterId: $document?->responsibility_center_id,
            editable: false,
            drillDown: [
                'type' => 'payment_transaction',
                'id' => $sourceId,
                'payment_document_id' => $transaction->payment_document_id,
                'reference_number' => $transaction->reference_number,
            ],
        );
    }

    public function fromBudgetLimitReservation(
        BudgetLimitReservation $reservation,
        ?DateTimeInterface $today = null,
    ): ?PaymentCalendarItem
    {
        if ($reservation->status !== BudgetLimitReservation::STATUS_RESERVED) {
            return null;
        }

        $document = $this->loadedPaymentDocument($reservation);
        $date = $document instanceof PaymentDocument
            ? $this->effectiveDocumentDate($document)
            : $this->dateString($reservation->period_month);
        $amount = $this->positive((float) $reservation->amount);

        if ($date === null || $amount <= 0.0) {
            return null;
        }

        $sourceId = $this->modelId($reservation);

        return new PaymentCalendarItem(
            organizationId: (int) $reservation->organization_id,
            date: $date,
            originalDate: $document instanceof PaymentDocument ? $this->documentOriginalDate($document, $date) : null,
            direction: PaymentCalendarItem::DIRECTION_OUTFLOW,
            bucket: $this->isOverdue($date, $today) ? PaymentCalendarItem::BUCKET_OVERDUE : PaymentCalendarItem::BUCKET_RESERVED,
            amount: $amount,
            remainingAmount: $amount,
            currency: $this->currency($reservation->currency),
            probability: 1.0,
            status: BudgetLimitReservation::STATUS_RESERVED,
            sourceType: 'budget_limit_reservation',
            sourceId: $sourceId,
            cashFlowKey: $this->budgetLimitReservationCashFlowKey($reservation),
            projectId: $this->nullableInt($reservation->project_id),
            counterpartyId: $this->nullableInt($reservation->counterparty_id),
            budgetArticleId: $reservation->budget_article_id,
            responsibilityCenterId: $reservation->responsibility_center_id,
            editable: false,
            drillDown: [
                'type' => 'budget_limit_reservation',
                'id' => $sourceId,
                'payment_document_id' => $reservation->payment_document_id,
                'period_month' => $this->dateString($reservation->period_month),
            ],
        );
    }

    public function fromBudgetAmount(BudgetAmount $amount): ?PaymentCalendarItem
    {
        $line = $this->loadedBudgetLine($amount);
        $version = $line instanceof BudgetLine ? $this->loadedBudgetVersion($line) : null;
        $article = $line instanceof BudgetLine ? $this->loadedBudgetArticle($line) : null;

        if (!$line instanceof BudgetLine || !$version instanceof BudgetVersion) {
            return null;
        }

        $direction = $article instanceof BudgetArticle
            ? $this->budgetArticleDirection((string) $article->flow_direction)
            : null;
        $calendarAmount = $this->positive((float) $amount->forecast_amount);

        if ($calendarAmount <= 0.0) {
            $calendarAmount = $this->positive((float) $amount->plan_amount);
        }

        $date = $this->dateString($amount->month);

        if ($direction === null || $date === null || $calendarAmount <= 0.0) {
            return null;
        }

        $sourceId = $this->modelId($amount);
        $currency = $this->currency($amount->currency ?: $line->currency);

        return new PaymentCalendarItem(
            organizationId: (int) $version->organization_id,
            date: $date,
            originalDate: null,
            direction: $direction,
            bucket: PaymentCalendarItem::BUCKET_BUDGET_PLAN,
            amount: $calendarAmount,
            remainingAmount: $calendarAmount,
            currency: $currency,
            probability: 0.6,
            status: (string) $version->status,
            sourceType: 'budget_amount',
            sourceId: $sourceId,
            cashFlowKey: $this->budgetAmountCashFlowKey($amount, $line, $currency),
            projectId: $this->nullableInt($line->project_id),
            counterpartyId: $this->nullableInt($line->counterparty_id),
            budgetArticleId: $line->budget_article_id,
            responsibilityCenterId: $line->responsibility_center_id,
            editable: false,
            drillDown: [
                'type' => 'budget_amount',
                'id' => $sourceId,
                'budget_version_id' => $line->budget_version_id,
                'budget_line_id' => $this->modelId($line),
                'month' => $date,
            ],
        );
    }

    private function collectPaymentTransactionItems(PaymentCalendarSourceFilters $filters): array
    {
        $transactions = PaymentTransaction::query()
            ->with('paymentDocument')
            ->where('organization_id', $filters->organizationId)
            ->where('status', PaymentTransactionStatus::COMPLETED->value)
            ->where(function (Builder $query) use ($filters): void {
                $query
                    ->whereBetween('value_date', [$filters->periodStart, $filters->periodEnd])
                    ->orWhere(function (Builder $scope) use ($filters): void {
                        $scope
                            ->whereNull('value_date')
                            ->whereBetween('transaction_date', [$filters->periodStart, $filters->periodEnd]);
                    });
            })
            ->get();

        $items = [];

        foreach ($transactions as $transaction) {
            $item = $this->fromPaymentTransaction($transaction);

            if ($item instanceof PaymentCalendarItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function collectPaymentScheduleItems(
        PaymentCalendarSourceFilters $filters,
        ?DateTimeInterface $today,
    ): array
    {
        $schedules = PaymentSchedule::query()
            ->with('paymentDocument')
            ->where('status', 'pending')
            ->where('due_date', '<=', $filters->periodEnd)
            ->whereHas('paymentDocument', function (Builder $query) use ($filters): void {
                $query->where('organization_id', $filters->organizationId);
            })
            ->get();

        $items = [];
        $scheduledDocumentIds = [];

        foreach ($schedules as $schedule) {
            $item = $this->fromPaymentSchedule($schedule, $today);

            if (!$item instanceof PaymentCalendarItem) {
                continue;
            }

            $items[] = $item;

            if ($schedule->payment_document_id !== null) {
                $scheduledDocumentIds[] = (int) $schedule->payment_document_id;
            }
        }

        return [$items, array_values(array_unique($scheduledDocumentIds))];
    }

    private function collectPaymentDocumentItems(
        PaymentCalendarSourceFilters $filters,
        ?DateTimeInterface $today,
        array $excludedDocumentIds,
    ): array
    {
        $documents = PaymentDocument::query()
            ->where('organization_id', $filters->organizationId)
            ->whereIn('status', $this->activeDocumentStatuses())
            ->when($excludedDocumentIds !== [], function (Builder $query) use ($excludedDocumentIds): void {
                $query->whereNotIn('id', $excludedDocumentIds);
            })
            ->where(function (Builder $query) use ($filters): void {
                $query
                    ->where(function (Builder $scope) use ($filters): void {
                        $scope
                            ->where('scheduled_at', '>=', $this->periodStartDateTime($filters))
                            ->where('scheduled_at', '<', $this->periodEndExclusiveDateTime($filters));
                    })
                    ->orWhereBetween('due_date', [$filters->periodStart, $filters->periodEnd])
                    ->orWhere(function (Builder $scope) use ($filters): void {
                        $scope
                            ->whereNull('scheduled_at')
                            ->whereNull('due_date')
                            ->whereBetween('document_date', [$filters->periodStart, $filters->periodEnd]);
                    })
                    ->orWhere(function (Builder $scope) use ($filters): void {
                        $scope
                            ->whereNotNull('due_date')
                            ->where('due_date', '<', $filters->periodStart);
                    });
            })
            ->get();

        $items = [];

        foreach ($documents as $document) {
            $item = $this->fromPaymentDocument($document, $today);

            if ($item instanceof PaymentCalendarItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function collectBudgetLimitReservationItems(
        PaymentCalendarSourceFilters $filters,
        ?DateTimeInterface $today,
        array $excludedDocumentIds,
    ): array
    {
        $reservations = BudgetLimitReservation::query()
            ->with('paymentDocument')
            ->where('organization_id', $filters->organizationId)
            ->where('status', BudgetLimitReservation::STATUS_RESERVED)
            ->where('period_month', '<=', $filters->periodEndMonth())
            ->when($excludedDocumentIds !== [], function (Builder $query) use ($excludedDocumentIds): void {
                $query->where(function (Builder $scope) use ($excludedDocumentIds): void {
                    $scope
                        ->whereNull('payment_document_id')
                        ->orWhereNotIn('payment_document_id', $excludedDocumentIds);
                });
            })
            ->get();

        $items = [];

        foreach ($reservations as $reservation) {
            $item = $this->fromBudgetLimitReservation($reservation, $today);

            if ($item instanceof PaymentCalendarItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function collectBudgetPlanItems(PaymentCalendarSourceFilters $filters): array
    {
        $amounts = BudgetAmount::query()
            ->with(['line.version', 'line.article'])
            ->whereBetween('month', [$filters->periodStartMonth(), $filters->periodEndMonth()])
            ->whereHas('line', function (Builder $query) use ($filters): void {
                $query
                    ->when($filters->projectId !== null, function (Builder $scope) use ($filters): void {
                        $scope->where('project_id', $filters->projectId);
                    })
                    ->when($filters->counterpartyId !== null, function (Builder $scope) use ($filters): void {
                        $scope->where('counterparty_id', $filters->counterpartyId);
                    })
                    ->when($filters->budgetArticleId !== null, function (Builder $scope) use ($filters): void {
                        $scope->where('budget_article_id', $filters->budgetArticleId);
                    })
                    ->when($filters->responsibilityCenterId !== null, function (Builder $scope) use ($filters): void {
                        $scope->where('responsibility_center_id', $filters->responsibilityCenterId);
                    })
                    ->whereHas('version', function (Builder $version) use ($filters): void {
                        $version
                            ->where('organization_id', $filters->organizationId)
                            ->whereIn('budget_kind', ['bdds', 'consolidated'])
                            ->whereIn('status', [
                                BudgetWorkflowService::STATUS_APPROVED,
                                BudgetWorkflowService::STATUS_ACTIVE,
                            ]);
                    });
            })
            ->get();

        $items = [];

        foreach ($amounts as $amount) {
            $item = $this->fromBudgetAmount($amount);

            if ($item instanceof PaymentCalendarItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function activeDocumentStatuses(): array
    {
        return [
            PaymentDocumentStatus::SUBMITTED->value,
            PaymentDocumentStatus::PENDING_APPROVAL->value,
            PaymentDocumentStatus::APPROVED->value,
            PaymentDocumentStatus::SCHEDULED->value,
            PaymentDocumentStatus::PARTIALLY_PAID->value,
        ];
    }

    private function documentBucket(string $status): string
    {
        return $status === PaymentDocumentStatus::SCHEDULED->value
            ? PaymentCalendarItem::BUCKET_SCHEDULED
            : PaymentCalendarItem::BUCKET_APPROVED;
    }

    private function documentProbability(string $direction, string $status): float
    {
        if ($direction === PaymentCalendarItem::DIRECTION_OUTFLOW) {
            return 1.0;
        }

        return in_array($status, [
            PaymentDocumentStatus::SUBMITTED->value,
            PaymentDocumentStatus::PENDING_APPROVAL->value,
        ], true) ? 0.7 : 0.9;
    }

    private function calendarPriority(PaymentCalendarItem $item): int
    {
        return match ($item->bucket) {
            PaymentCalendarItem::BUCKET_FACT => 100,
            PaymentCalendarItem::BUCKET_OVERDUE => 90,
            PaymentCalendarItem::BUCKET_SCHEDULED => 80,
            PaymentCalendarItem::BUCKET_APPROVED => 70,
            PaymentCalendarItem::BUCKET_RESERVED => 50,
            PaymentCalendarItem::BUCKET_BUDGET_PLAN => 30,
            PaymentCalendarItem::BUCKET_MANUAL => 20,
            default => 0,
        };
    }

    private function paymentDocumentDirection(PaymentDocument $document): ?string
    {
        $direction = $document->direction;

        if ($direction instanceof InvoiceDirection) {
            return $direction === InvoiceDirection::INCOMING
                ? PaymentCalendarItem::DIRECTION_INFLOW
                : PaymentCalendarItem::DIRECTION_OUTFLOW;
        }

        return match ((string) $direction) {
            InvoiceDirection::INCOMING->value => PaymentCalendarItem::DIRECTION_INFLOW,
            InvoiceDirection::OUTGOING->value => PaymentCalendarItem::DIRECTION_OUTFLOW,
            default => null,
        };
    }

    private function transactionDirection(PaymentTransaction $transaction): ?string
    {
        if (
            $transaction->payee_organization_id !== null
            && (int) $transaction->payee_organization_id === (int) $transaction->organization_id
        ) {
            return PaymentCalendarItem::DIRECTION_INFLOW;
        }

        if (
            $transaction->payer_organization_id !== null
            && (int) $transaction->payer_organization_id === (int) $transaction->organization_id
        ) {
            return PaymentCalendarItem::DIRECTION_OUTFLOW;
        }

        return null;
    }

    private function budgetArticleDirection(string $flowDirection): ?string
    {
        return match ($flowDirection) {
            'income', 'inflow' => PaymentCalendarItem::DIRECTION_INFLOW,
            'expense', 'outflow' => PaymentCalendarItem::DIRECTION_OUTFLOW,
            default => null,
        };
    }

    private function effectiveDocumentDate(PaymentDocument $document): ?string
    {
        return $this->dateString($document->scheduled_at)
            ?? $this->dateString($document->due_date)
            ?? $this->dateString($document->document_date);
    }

    private function documentOriginalDate(PaymentDocument $document, string $effectiveDate): ?string
    {
        $dueDate = $this->dateString($document->due_date);

        if ($dueDate !== null && $dueDate !== $effectiveDate) {
            return $dueDate;
        }

        return null;
    }

    private function documentRemainingAmount(PaymentDocument $document): float
    {
        if ($document->remaining_amount !== null) {
            return $this->positive((float) $document->remaining_amount);
        }

        return $this->positive((float) $document->amount - (float) $document->paid_amount);
    }

    private function paymentDocumentCounterpartyId(PaymentDocument $document, string $direction): ?int
    {
        if ($direction === PaymentCalendarItem::DIRECTION_INFLOW) {
            return $this->nullableInt(
                $document->payer_contractor_id
                    ?? $document->contractor_id
                    ?? $document->counterparty_organization_id
            );
        }

        return $this->nullableInt(
            $document->payee_contractor_id
                ?? $document->contractor_id
                ?? $document->counterparty_organization_id
        );
    }

    private function transactionCounterpartyId(
        PaymentTransaction $transaction,
        ?PaymentDocument $document,
        string $direction,
    ): ?int
    {
        if ($direction === PaymentCalendarItem::DIRECTION_INFLOW) {
            return $this->nullableInt(
                $transaction->payer_contractor_id
                    ?? $document?->payer_contractor_id
                    ?? $document?->contractor_id
            );
        }

        return $this->nullableInt(
            $transaction->payee_contractor_id
                ?? $document?->payee_contractor_id
                ?? $document?->contractor_id
        );
    }

    private function loadedPaymentDocument(object $model): ?PaymentDocument
    {
        if (
            method_exists($model, 'relationLoaded')
            && $model->relationLoaded('paymentDocument')
            && $model->getRelation('paymentDocument') instanceof PaymentDocument
        ) {
            return $model->getRelation('paymentDocument');
        }

        return null;
    }

    private function loadedBudgetLine(BudgetAmount $amount): ?BudgetLine
    {
        if ($amount->relationLoaded('line') && $amount->getRelation('line') instanceof BudgetLine) {
            return $amount->getRelation('line');
        }

        return null;
    }

    private function loadedBudgetVersion(BudgetLine $line): ?BudgetVersion
    {
        if ($line->relationLoaded('version') && $line->getRelation('version') instanceof BudgetVersion) {
            return $line->getRelation('version');
        }

        return null;
    }

    private function loadedBudgetArticle(BudgetLine $line): ?BudgetArticle
    {
        if ($line->relationLoaded('article') && $line->getRelation('article') instanceof BudgetArticle) {
            return $line->getRelation('article');
        }

        return null;
    }

    private function documentStatusValue(PaymentDocument $document): string
    {
        $status = $document->status;

        return $status instanceof PaymentDocumentStatus ? $status->value : (string) $status;
    }

    private function transactionStatusValue(PaymentTransaction $transaction): string
    {
        $status = $transaction->status;

        return $status instanceof PaymentTransactionStatus ? $status->value : (string) $status;
    }

    private function isOverdue(string $date, ?DateTimeInterface $today = null, bool $forced = false): bool
    {
        if ($forced) {
            return true;
        }

        $currentDate = $today instanceof DateTimeInterface
            ? CarbonImmutable::instance($today)->startOfDay()
            : CarbonImmutable::today();

        return CarbonImmutable::parse($date)->startOfDay()->lt($currentDate);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value)->toDateString();
        }

        return null;
    }

    private function periodStartDateTime(PaymentCalendarSourceFilters $filters): string
    {
        return CarbonImmutable::parse($filters->periodStart)
            ->startOfDay()
            ->format('Y-m-d H:i:s');
    }

    private function periodEndExclusiveDateTime(PaymentCalendarSourceFilters $filters): string
    {
        return CarbonImmutable::parse($filters->periodEnd)
            ->addDay()
            ->startOfDay()
            ->format('Y-m-d H:i:s');
    }

    private function currency(mixed $currency): string
    {
        if (!is_string($currency) || trim($currency) === '') {
            return 'RUB';
        }

        return mb_strtoupper($currency);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function positive(float $amount): float
    {
        return round(max(0.0, $amount), 2);
    }

    private function modelId(object $model): int|string|null
    {
        if (method_exists($model, 'getKey')) {
            $key = $model->getKey();

            if (is_int($key) || is_string($key)) {
                return $key;
            }
        }

        return null;
    }

    private function paymentDocumentCashFlowKey(PaymentDocument $document): string
    {
        return 'payment-document:' . (string) ($this->modelId($document) ?? $document->source_id ?? 'unknown');
    }

    private function paymentScheduleCashFlowKey(PaymentSchedule $schedule): string
    {
        $documentPart = $schedule->payment_document_id !== null
            ? 'payment-document:' . (string) $schedule->payment_document_id
            : 'payment-document:unknown';
        $schedulePart = $this->modelId($schedule) ?? $schedule->installment_number ?? 'unknown';

        return $documentPart . ':payment-schedule:' . (string) $schedulePart;
    }

    private function paymentTransactionCashFlowKey(PaymentTransaction $transaction): string
    {
        return 'payment-transaction:' . (string) (
            $this->modelId($transaction)
            ?? $transaction->bank_transaction_id
            ?? $transaction->reference_number
            ?? 'unknown'
        );
    }

    private function budgetLimitReservationCashFlowKey(BudgetLimitReservation $reservation): string
    {
        if ($reservation->payment_document_id !== null) {
            return 'payment-document:' . (string) $reservation->payment_document_id;
        }

        return 'budget-limit-reservation:' . (string) ($this->modelId($reservation) ?? 'unknown');
    }

    private function budgetAmountCashFlowKey(BudgetAmount $amount, BudgetLine $line, string $currency): string
    {
        return implode(':', [
            'budget-plan',
            (string) ($this->modelId($line) ?? $line->uuid ?? 'unknown'),
            (string) ($this->dateString($amount->month) ?? 'unknown'),
            $currency,
        ]);
    }
}
