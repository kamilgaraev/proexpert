<?php

namespace App\Modules\Contracts;

interface BillableInterface
{
    public function getPrice(): float;
    
    public function getCurrency(): string;
    
    public function getDurationDays(): int;
    
    public function getPricingConfig(): array;
    
    public function calculateCost(int $organizationId): float;
    
    public function canAfford(int $organizationId): bool;
}
