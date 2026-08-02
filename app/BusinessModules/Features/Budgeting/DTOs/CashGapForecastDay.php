<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class CashGapForecastDay
{
    public function __construct(
        public string $date,
        public string $openingBalance,
        public string $inflows,
        public string $outflows,
        public string $reservedOutflows,
        public string $overdueInflows,
        public string $overdueOutflows,
        public string $closingBalance,
        public string $cashGap,
        public string $riskLevel,
        public array $explanation,
        public array $drivers,
    ) {}

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
