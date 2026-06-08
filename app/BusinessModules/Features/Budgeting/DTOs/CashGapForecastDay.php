<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class CashGapForecastDay
{
    public function __construct(
        public string $date,
        public float $openingBalance,
        public float $inflows,
        public float $outflows,
        public float $reservedOutflows,
        public float $overdueInflows,
        public float $overdueOutflows,
        public float $closingBalance,
        public float $cashGap,
        public string $riskLevel,
        public array $explanation,
        public array $drivers,
    ) {
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'opening_balance' => $this->openingBalance,
            'inflows' => $this->inflows,
            'outflows' => $this->outflows,
            'reserved_outflows' => $this->reservedOutflows,
            'overdue_inflows' => $this->overdueInflows,
            'overdue_outflows' => $this->overdueOutflows,
            'closing_balance' => $this->closingBalance,
            'cash_gap' => $this->cashGap,
            'risk_level' => $this->riskLevel,
            'explanation' => $this->explanation,
            'drivers' => $this->drivers,
        ];
    }
}
