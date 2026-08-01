<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportingPermissionMatrix;
use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Modules\Contracts\ModuleInterface;

final class ReportingModule implements ModuleInterface
{
    public function getName(): string
    {
        return $this->manifest()['name'];
    }

    public function getSlug(): string
    {
        return $this->manifest()['slug'];
    }

    public function getVersion(): string
    {
        return $this->manifest()['version'];
    }

    public function getDescription(): string
    {
        return $this->manifest()['description'];
    }

    public function getType(): ModuleType
    {
        return ModuleType::CORE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
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
        return true;
    }

    public function getDependencies(): array
    {
        return $this->manifest()['dependencies'];
    }

    public function getConflicts(): array
    {
        return $this->manifest()['conflicts'];
    }

    public function getPermissions(): array
    {
        return [...ReportingPermissionMatrix::corePermissions(), 'reports.project_readiness.view', 'reports.project_readiness.export'];
    }

    public function getFeatures(): array
    {
        return $this->manifest()['features'];
    }

    public function getLimits(): array
    {
        return $this->manifest()['limits'];
    }

    private function manifest(): array
    {
        return json_decode((string) file_get_contents(config_path('ModuleList/core/reports.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
