<?php

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\ModuleSubscription;
use App\Modules\Core\AccessController;
use App\Modules\Core\ModuleRegistry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationModuleService
{
    protected AccessController $accessController;
    protected ModuleRegistry $moduleRegistry;

    public function __construct(
        AccessController $accessController,
        ModuleRegistry $moduleRegistry
    ) {
        $this->accessController = $accessController;
        $this->moduleRegistry = $moduleRegistry;
    }

    public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
    {
        return $this->accessController->hasModuleAccess($organizationId, $moduleSlug);
    }

    public function getOrganizationModulesWithStatus(int $organizationId): Collection
    {
        $organization = Organization::findOrFail($organizationId);
        
        return Module::with(['activations' => function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId)
                  ->where('is_active', true);
        }])->get()->map(function ($module) use ($organizationId) {
            $activation = $module->activations->first();
            
            return [
                'module' => $module,
                'is_active' => $activation ? true : false,
                'expires_at' => $activation?->expires_at,
                'status' => $this->getModuleStatus($activation),
                'days_remaining' => $activation ? $activation->expires_at?->diffInDays(now()) : 0
            ];
        });
    }

    public function getModulesByCategory(): Collection
    {
        return Module::select('id', 'name', 'slug', 'category', 'description', 'price_monthly', 'price_yearly')
                     ->orderBy('category')
                     ->orderBy('name')
                     ->get()
                     ->groupBy('category');
    }

    public function activateModule(int $organizationId, string $moduleId): array
    {
        $organization = Organization::findOrFail($organizationId);
        $module = Module::findOrFail($moduleId);

        return DB::transaction(function () use ($organization, $module) {
            $activation = OrganizationModuleActivation::updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'module_id' => $module->id,
                ],
                [
                    'is_active' => true,
                    'activated_at' => now(),
                    'expires_at' => now()->addMonth(),
                ]
            );

            return [
                'success' => true,
                'activation' => $activation,
                'message' => "Модуль '{$module->name}' успешно активирован"
            ];
        });
    }

    public function deactivateModule(int $organizationId, string $moduleId): array
    {
        $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('module_id', $moduleId)
            ->where('is_active', true)
            ->first();

        if (!$activation) {
            return [
                'success' => false,
                'message' => 'Активация модуля не найдена'
            ];
        }

        $activation->update([
            'is_active' => false,
            'deactivated_at' => now()
        ]);

        return [
            'success' => true,
            'message' => 'Модуль успешно деактивирован'
        ];
    }

    public function renewModule(int $organizationId, string $moduleId, int $days): array
    {
        $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('module_id', $moduleId)
            ->where('is_active', true)
            ->first();

        if (!$activation) {
            return [
                'success' => false,
                'message' => 'Активация модуля не найдена'
            ];
        }

        $newExpiresAt = $activation->expires_at 
            ? Carbon::parse($activation->expires_at)->addDays($days)
            : now()->addDays($days);

        $activation->update([
            'expires_at' => $newExpiresAt
        ]);

        return [
            'success' => true,
            'activation' => $activation->fresh(),
            'message' => "Модуль продлен на {$days} дней"
        ];
    }

    protected function getModuleStatus(?OrganizationModuleActivation $activation): string
    {
        if (!$activation) {
            return 'inactive';
        }

        if (!$activation->is_active) {
            return 'inactive';
        }

        if ($activation->expires_at && $activation->expires_at->isPast()) {
            return 'expired';
        }

        if ($activation->expires_at && $activation->expires_at->diffInDays(now()) <= 7) {
            return 'expiring_soon';
        }

        return 'active';
    }
}