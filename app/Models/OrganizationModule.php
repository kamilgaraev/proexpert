<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'features',
        'permissions',
        'category',
        'icon',
        'is_active',
        'is_premium',
        'display_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'features' => 'array',
        'permissions' => 'array',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
        'display_order' => 'integer',
    ];

    public function activations(): HasMany
    {
        return $this->hasMany(OrganizationModuleActivation::class);
    }

    public function isAvailableForOrganization(int $organizationId): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return $this->activations()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function getActivationForOrganization(int $organizationId): ?OrganizationModuleActivation
    {
        return $this->activations()
            ->where('organization_id', $organizationId)
            ->first();
    }
} 