<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\DTOs;

use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastItem;

final readonly class PaymentCalendarItem
{
    public const DIRECTION_INFLOW = 'inflow';
    public const DIRECTION_OUTFLOW = 'outflow';

    public const BUCKET_FACT = 'fact';
    public const BUCKET_SCHEDULED = 'scheduled';
    public const BUCKET_APPROVED = 'approved';
    public const BUCKET_RESERVED = 'reserved';
    public const BUCKET_OVERDUE = 'overdue';
    public const BUCKET_BUDGET_PLAN = 'budget_plan';
    public const BUCKET_MANUAL = 'manual';

    public function __construct(
        public int $organizationId,
        public string $date,
        public ?string $originalDate,
        public string $direction,
        public string $bucket,
        public float $amount,
        public float $remainingAmount,
        public string $currency,
        public float $probability,
        public string $status,
        public string $sourceType,
        public int|string|null $sourceId,
        public string $cashFlowKey,
        public ?int $projectId = null,
        public ?int $counterpartyId = null,
        public int|string|null $budgetArticleId = null,
        public int|string|null $responsibilityCenterId = null,
        public bool $editable = false,
        public array $drillDown = [],
    ) {
    }

    public function toCashGapForecastItem(): CashGapForecastItem
    {
        $description = $this->drillDown['label'] ?? null;

        return new CashGapForecastItem(
            date: $this->date,
            direction: $this->direction,
            bucket: $this->cashGapBucket(),
            amount: $this->remainingAmount,
            probability: $this->probability,
            organizationId: $this->organizationId,
            projectId: $this->projectId,
            counterpartyId: $this->counterpartyId,
            budgetArticleId: $this->identifierToString($this->budgetArticleId),
            responsibilityCenterId: $this->identifierToString($this->responsibilityCenterId),
            currency: $this->currency,
            sourceType: $this->sourceType,
            sourceId: $this->sourceId,
            description: is_string($description) ? $description : null,
            originalDate: $this->originalDate,
            cashFlowKey: $this->cashFlowKey,
        );
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'date' => $this->date,
            'original_date' => $this->originalDate,
            'direction' => $this->direction,
            'bucket' => $this->bucket,
            'amount' => $this->amount,
            'remaining_amount' => $this->remainingAmount,
            'currency' => $this->currency,
            'probability' => $this->probability,
            'status' => $this->status,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'cash_flow_key' => $this->cashFlowKey,
            'project_id' => $this->projectId,
            'counterparty_id' => $this->counterpartyId,
            'budget_article_id' => $this->budgetArticleId,
            'responsibility_center_id' => $this->responsibilityCenterId,
            'editable' => $this->editable,
            'drill_down' => $this->drillDown,
        ];
    }

    private function cashGapBucket(): string
    {
        if ($this->bucket === self::BUCKET_FACT) {
            return $this->direction === self::DIRECTION_INFLOW
                ? CashGapForecastItem::BUCKET_ACTUAL_INFLOW
                : CashGapForecastItem::BUCKET_ACTUAL_OUTFLOW;
        }

        if ($this->bucket === self::BUCKET_OVERDUE) {
            return $this->direction === self::DIRECTION_INFLOW
                ? CashGapForecastItem::BUCKET_OVERDUE_INFLOW
                : CashGapForecastItem::BUCKET_OVERDUE_OUTFLOW;
        }

        if ($this->bucket === self::BUCKET_RESERVED) {
            return CashGapForecastItem::BUCKET_RESERVED_OUTFLOW;
        }

        if ($this->bucket === self::BUCKET_SCHEDULED) {
            return $this->direction === self::DIRECTION_INFLOW
                ? CashGapForecastItem::BUCKET_PLANNED_INFLOW
                : CashGapForecastItem::BUCKET_SCHEDULED_OUTFLOW;
        }

        if ($this->bucket === self::BUCKET_MANUAL) {
            return CashGapForecastItem::BUCKET_MANUAL_ADJUSTMENT;
        }

        if ($this->direction === self::DIRECTION_INFLOW) {
            return CashGapForecastItem::BUCKET_PLANNED_INFLOW;
        }

        return CashGapForecastItem::BUCKET_APPROVED_OUTFLOW;
    }

    private function identifierToString(int|string|null $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return (string) $identifier;
    }
}
