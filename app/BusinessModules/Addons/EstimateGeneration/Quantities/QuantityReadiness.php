<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;

final readonly class QuantityReadiness
{
    /**
     * @param  list<array{code: string, operand: string, fact_id: string|null}>  $unresolvedInputs
     * @param  list<array{code: string, message_key: string, operand: string}>  $questions
     */
    public function __construct(
        public ?DerivedQuantity $quantity,
        public array $unresolvedInputs,
        public array $questions,
    ) {}

    public function isReady(): bool
    {
        return $this->quantity instanceof DerivedQuantity && $this->unresolvedInputs === [];
    }
}
