<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'version',
        'type',
        'billing_model',
        'category',
        'description',
        'pricing_config',
        'features',
        'permissions',
        'dependencies',
        'conflicts',
        'limits',
        'class_name',
        'config_file',
        'icon',
        'display_order',
        'is_active',
        'is_system_module',
        'can_deactivate',
        'last_scanned_at',
    ];

    protected $casts = [
        'pricing_config' => 'array',
        'features' => 'array',
        'permissions' => 'array',
        'dependencies' => 'array',
        'conflicts' => 'array',
        'limits' => 'array',
        'is_active' => 'boolean',
        'is_system_module' => 'boolean',
        'can_deactivate' => 'boolean',
        'last_scanned_at' => 'datetime',
        'display_order' => 'integer',
    ];

    public function activations(): HasMany
    {
        return $this->hasMany(OrganizationModuleActivation::class);
    }

    public function activeActivations(): HasMany
    {
        return $this->hasMany(OrganizationModuleActivation::class)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isAvailableForOrganization(int $organizationId): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return $this->activeActivations()
            ->where('organization_id', $organizationId)
            ->exists();
    }

    public function getActivationForOrganization(int $organizationId): ?OrganizationModuleActivation
    {
        return $this->activations()
            ->where('organization_id', $organizationId)
            ->first();
    }

    public function getPrice(): float
    {
        return (float) ($this->pricing_config['base_price'] ?? 0);
    }

    public function getCurrency(): string
    {
        return $this->pricing_config['currency'] ?? 'RUB';
    }

    public function getDurationDays(): int
    {
        return (int) ($this->pricing_config['duration_days'] ?? 30);
    }

    public function isFree(): bool
    {
        return $this->billing_model === 'free' || $this->getPrice() == 0;
    }

    public function isSubscription(): bool
    {
        return $this->billing_model === 'subscription';
    }

    public function isOneTime(): bool
    {
        return $this->billing_model === 'one_time';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Получить публичные данные модуля для фронтенда (без внутренних полей)
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'type' => $this->type,
            'billing_model' => $this->billing_model,
            'category' => $this->category,
            'description' => $this->description,
            'pricing_config' => $this->pricing_config,
            'features' => $this->features,
            'permissions' => $this->permissions,
            'dependencies' => $this->dependencies,
            'conflicts' => $this->conflicts,
            'limits' => $this->limits,
            'icon' => $this->icon,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'is_system_module' => $this->is_system_module,
            'can_deactivate' => $this->can_deactivate,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Получить публичные данные для коллекции модулей
     */
    public static function toPublicCollection($modules): array
    {
        return $modules->map(function ($module) {
            return $module->toPublicArray();
        })->toArray();
    }
}
