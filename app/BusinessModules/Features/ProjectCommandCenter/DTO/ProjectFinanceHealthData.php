<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\DTO;

final readonly class ProjectFinanceHealthData
{
    public function __construct(
        private bool $available,
        private ?string $reasonKey,
        private array $margin,
        private array $cashFlow,
        private array $evm,
        private array $dataCompleteness,
    ) {
    }

    public static function unavailable(string $reasonKey): self
    {
        return new self(false, $reasonKey, [], [], [], []);
    }

    public static function available(array $margin, array $cashFlow, array $evm, array $dataCompleteness): self
    {
        return new self(true, null, $margin, $cashFlow, $evm, $dataCompleteness);
    }

    public function toArray(): array
    {
        if (! $this->available) {
            return [
                'available' => false,
                'reason_key' => $this->reasonKey,
            ];
        }

        return [
            'available' => true,
            'margin' => $this->margin,
            'cash_flow' => $this->cashFlow,
            'evm' => $this->evm,
            'data_completeness' => $this->dataCompleteness,
        ];
    }
}
