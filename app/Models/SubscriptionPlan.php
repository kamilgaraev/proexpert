<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billing_cycle',
        'duration_in_days',
        'trial_days',
        'max_projects',
        'max_storage_gb',
        'max_users',
        'max_contractor_invitations',
        'features',
        'included_packages',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'trial_days' => 'integer',
        'included_packages' => 'array',
        'features' => AsArrayObject::class, // Для удобной работы с JSON-полем
        'is_active' => 'boolean',
        'max_projects' => 'integer',
        'max_storage_gb' => 'integer',
        'max_users' => 'integer',
        'max_contractor_invitations' => 'integer',
        'duration_in_days' => 'integer',
        'display_order' => 'integer',
    ];

    /**
     * Получить активные тарифные планы, отсортированные по порядку отображения.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('display_order');
    }
} 
