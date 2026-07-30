<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;

final readonly class CashGapForecastItem
{
    public const DIRECTION_INFLOW = 'inflow';

    public const DIRECTION_OUTFLOW = 'outflow';

    public const BUCKET_ACTUAL_INFLOW = 'actual_inflow';

    public const BUCKET_PLANNED_INFLOW = 'planned_inflow';

    public const BUCKET_OVERDUE_INFLOW = 'overdue_inflow';

    public const BUCKET_ACTUAL_OUTFLOW = 'actual_outflow';

    public const BUCKET_APPROVED_OUTFLOW = 'approved_outflow';

    public const BUCKET_SCHEDULED_OUTFLOW = 'scheduled_outflow';

    public const BUCKET_RESERVED_OUTFLOW = 'reserved_outflow';

    public const BUCKET_OVERDUE_OUTFLOW = 'overdue_outflow';

    public const BUCKET_MANUAL_ADJUSTMENT = 'manual_adjustment';

    public function __construct(
        public string $date,
        public string $direction,
        public string $bucket,
        string|int|float $amount,
        string|int $probability = '1',
        public ?int $organizationId = null,
        public ?int $projectId = null,
        public ?int $counterpartyId = null,
        public ?string $budgetArticleId = null,
        public ?string $responsibilityCenterId = null,
        public ?string $currency = 'RUB',
        public ?string $sourceType = null,
        public int|string|null $sourceId = null,
        public ?string $description = null,
        public ?string $originalDate = null,
        public ?string $cashFlowKey = null,
        public array $drillDown = [],
    ) {
        if (is_float($amount)) {
            $amount = rtrim(rtrim(sprintf('%.14F', $amount), '0'), '.');
        }
        $this->amount = PortfolioDecimal::money($amount);
        $this->probability = self::probability($probability);
    }

    public string $amount;

    public string $probability;

    public function isInflow(): bool
    {
        return $this->direction === self::DIRECTION_INFLOW;
    }

    public function isOutflow(): bool
    {
        return $this->direction === self::DIRECTION_OUTFLOW;
    }

    public function isActual(): bool
    {
        return in_array($this->bucket, [
            self::BUCKET_ACTUAL_INFLOW,
            self::BUCKET_ACTUAL_OUTFLOW,
        ], true);
    }

    public function isOverdueInflow(): bool
    {
        return $this->bucket === self::BUCKET_OVERDUE_INFLOW;
    }

    public function isOverdueOutflow(): bool
    {
        return $this->bucket === self::BUCKET_OVERDUE_OUTFLOW;
    }

    public function isReservedOutflow(): bool
    {
        return $this->bucket === self::BUCKET_RESERVED_OUTFLOW;
    }

    public function normalizedCashFlowKey(): ?string
    {
        if ($this->cashFlowKey === null || trim($this->cashFlowKey) === '') {
            return null;
        }

        return $this->cashFlowKey;
    }

    private static function probability(string|int $probability): string
    {
        return PortfolioDecimal::ratio($probability);
    }
}
