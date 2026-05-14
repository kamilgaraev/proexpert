<?php

declare(strict_types=1);

namespace App\BusinessModules\Features;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\AccessController;

abstract class ConstructionErpFeatureModule implements ModuleInterface
{
    abstract protected function manifestPath(): string;

    public function getName(): string
    {
        return (string) $this->manifest()['name'];
    }

    public function getSlug(): string
    {
        return (string) $this->manifest()['slug'];
    }

    public function getVersion(): string
    {
        return (string) $this->manifest()['version'];
    }

    public function getDescription(): string
    {
        return (string) $this->manifest()['description'];
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return $this->manifest();
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }

    public function canActivate(int $organizationId): bool
    {
        $accessController = app(AccessController::class);

        foreach ($this->getDependencies() as $dependency) {
            if (!$accessController->hasModuleAccess($organizationId, $dependency)) {
                return false;
            }
        }

        return true;
    }

    public function getDependencies(): array
    {
        return $this->manifest()['dependencies'] ?? [];
    }

    public function getConflicts(): array
    {
        return $this->manifest()['conflicts'] ?? [];
    }

    public function getPermissions(): array
    {
        return $this->manifest()['permissions'] ?? [];
    }

    public function getFeatures(): array
    {
        return $this->manifest()['features'] ?? [];
    }

    public function getLimits(): array
    {
        return $this->manifest()['limits'] ?? [];
    }

    private function manifest(): array
    {
        return json_decode((string) file_get_contents(config_path($this->manifestPath())), true, 512, JSON_THROW_ON_ERROR);
    }
}
