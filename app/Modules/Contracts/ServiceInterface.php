<?php

namespace App\Modules\Contracts;

interface ServiceInterface
{
    public function execute(int $organizationId, array $params = []): array;
    
    public function canExecute(int $organizationId): bool;
    
    public function getUsageLimit(int $organizationId): ?int;
    
    public function getCurrentUsage(int $organizationId): int;
    
    public function trackUsage(int $organizationId, array $metadata = []): void;
}
