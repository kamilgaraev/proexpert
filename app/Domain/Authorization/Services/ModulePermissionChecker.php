<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Models\Module;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Cache;

final class ModulePermissionChecker
{
    public function __construct(private readonly AccessController $accessController) {}

    public function isModuleActive(string $moduleSlug, int $organizationId): bool
    {
        return $this->accessController->hasModuleAccess($organizationId, $moduleSlug);
    }

    public function getActiveModules(int $organizationId): array
    {
        return $this->accessController->getActiveModules($organizationId)->pluck('slug')->all();
    }

    public function hasModulePermission(int $organizationId, string $permission): bool
    {
        return $this->accessController->hasModulePermission($organizationId, $permission);
    }

    public function moduleHasPermission(string $moduleSlug, string $permission): bool
    {
        $module = Module::query()->where('slug', $moduleSlug)->where('is_active', true)->first();

        return $module !== null && in_array($permission, (array) $module->permissions, true);
    }

    public function getModulePermissions(string $moduleSlug): array
    {
        return (array) (Module::query()->where('slug', $moduleSlug)->value('permissions') ?? []);
    }

    public function getModuleStatus(int $organizationId, string $moduleSlug): string
    {
        return $this->isModuleActive($moduleSlug, $organizationId) ? 'active' : 'unavailable';
    }

    public function getAvailableModules(): array
    {
        return Module::query()->where('is_active', true)->orderBy('display_order')->pluck('slug')->all();
    }

    public function validateModulePermissions(string $moduleSlug, array $requiredPermissions): bool
    {
        $available = $this->getModulePermissions($moduleSlug);

        return collect($requiredPermissions)->every(static fn (string $permission): bool => in_array($permission, $available, true));
    }

    public function clearModuleCache(?int $organizationId = null, ?string $moduleSlug = null): void
    {
        if ($organizationId !== null) {
            $this->accessController->clearAccessCache($organizationId);

            return;
        }

        Cache::flush();
    }
}
