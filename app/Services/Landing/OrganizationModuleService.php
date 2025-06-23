<?php

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\OrganizationModuleActivation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class OrganizationModuleService
{
    public function getAvailableModules(): Collection
    {
        return OrganizationModule::where('is_active', true)
            ->orderBy('category')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    public function getModulesByCategory(): array
    {
        $modules = $this->getAvailableModules();
        
        return $modules->groupBy('category')->map(function ($categoryModules) {
            return $categoryModules->values();
        })->toArray();
    }

    public function getOrganizationActiveModules(int $organizationId): Collection
    {
        return OrganizationModuleActivation::with('module')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereHas('module', function ($query) {
                $query->where('is_active', true);
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();
    }

    public function getOrganizationModulesWithStatus(int $organizationId): array
    {
        $allModules = $this->getAvailableModules();
        $activeModules = $this->getOrganizationActiveModules($organizationId);
        
        $activeModuleIds = $activeModules->pluck('organization_module_id')->toArray();
        
        return $allModules->map(function ($module) use ($activeModuleIds, $organizationId) {
            $isActive = in_array($module->id, $activeModuleIds);
            $activation = $isActive ? $module->getActivationForOrganization($organizationId) : null;
            
            return [
                'module' => $module,
                'is_activated' => $isActive,
                'activation' => $activation,
                'expires_at' => $activation?->expires_at,
                'days_until_expiration' => $activation?->getDaysUntilExpiration(),
                'status' => $activation?->status,
            ];
        })->groupBy('module.category')->toArray();
    }

    public function activateModule(int $organizationId, int $moduleId, array $options = []): OrganizationModuleActivation
    {
        $module = OrganizationModule::findOrFail($moduleId);
        
        if (!$module->is_active) {
            throw new \Exception('Модуль недоступен для активации');
        }

        return DB::transaction(function () use ($organizationId, $moduleId, $module, $options) {
            $activation = OrganizationModuleActivation::updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'organization_module_id' => $moduleId,
                ],
                [
                    'activated_at' => now(),
                    'expires_at' => $options['expires_at'] ?? null,
                    'status' => 'active',
                    'settings' => $options['settings'] ?? [],
                    'paid_amount' => $options['paid_amount'] ?? $module->price,
                    'payment_method' => $options['payment_method'] ?? 'balance',
                ]
            );

            $this->clearOrganizationModulesCache($organizationId);

            return $activation;
        });
    }

    public function deactivateModule(int $organizationId, int $moduleId): bool
    {
        $result = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('organization_module_id', $moduleId)
            ->update(['status' => 'suspended']);

        if ($result) {
            $this->clearOrganizationModulesCache($organizationId);
        }

        return $result > 0;
    }

    public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
    {
        $cacheKey = "org_module_access_{$organizationId}_{$moduleSlug}";
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId, $moduleSlug) {
            return OrganizationModule::where('slug', $moduleSlug)
                ->where('is_active', true)
                ->whereHas('activations', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->where(function ($subQuery) {
                            $subQuery->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        });
                })
                ->exists();
        });
    }

    public function hasModulePermission(int $organizationId, string $permission): bool
    {
        $cacheKey = "org_module_permission_{$organizationId}_{$permission}";
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId, $permission) {
            return OrganizationModule::where('is_active', true)
                ->whereJsonContains('permissions', $permission)
                ->whereHas('activations', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->where(function ($subQuery) {
                            $subQuery->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        });
                })
                ->exists();
        });
    }

    public function getExpiringModules(int $organizationId, int $daysAhead = 7): Collection
    {
        return OrganizationModuleActivation::with('module')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($daysAhead)])
            ->get();
    }

    public function renewModule(int $organizationId, int $moduleId, int $days = 30): OrganizationModuleActivation
    {
        $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('organization_module_id', $moduleId)
            ->firstOrFail();

        $newExpirationDate = ($activation->expires_at && $activation->expires_at->isFuture())
            ? $activation->expires_at->addDays($days)
            : now()->addDays($days);

        $activation->update([
            'expires_at' => $newExpirationDate,
            'status' => 'active',
        ]);

        $this->clearOrganizationModulesCache($organizationId);

        return $activation;
    }

    private function clearOrganizationModulesCache(int $organizationId): void
    {
        $patterns = [
            "org_module_access_{$organizationId}_*",
            "org_module_permission_{$organizationId}_*",
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }
} 