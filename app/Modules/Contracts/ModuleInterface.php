<?php

namespace App\Modules\Contracts;

use App\Enums\ModuleType;
use App\Enums\BillingModel;

interface ModuleInterface
{
    public function getName(): string;
    
    public function getSlug(): string;
    
    public function getVersion(): string;
    
    public function getDescription(): string;
    
    public function getType(): ModuleType;
    
    public function getBillingModel(): BillingModel;
    
    public function getManifest(): array;
    
    public function install(): void;
    
    public function uninstall(): void;
    
    public function upgrade(string $fromVersion): void;
    
    public function canActivate(int $organizationId): bool;
    
    public function getDependencies(): array;
    
    public function getConflicts(): array;
    
    public function getPermissions(): array;
    
    public function getFeatures(): array;
    
    public function getLimits(): array;
}
