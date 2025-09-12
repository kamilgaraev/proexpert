<?php

namespace App\Modules\Contracts;

interface VersionableInterface
{
    public function getVersion(): string;
    
    public function isCompatibleWith(string $version): bool;
    
    public function getMigrationPath(): ?string;
    
    public function requiresDataMigration(string $fromVersion): bool;
}
