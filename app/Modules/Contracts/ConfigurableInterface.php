<?php

namespace App\Modules\Contracts;

interface ConfigurableInterface
{
    public function getDefaultSettings(): array;
    
    public function validateSettings(array $settings): bool;
    
    public function applySettings(int $organizationId, array $settings): void;
    
    public function getSettings(int $organizationId): array;
}
