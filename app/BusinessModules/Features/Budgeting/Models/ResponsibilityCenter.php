<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ResponsibilityCenter extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'organization_id',
        'parent_id',
        'center_type',
        'code',
        'name',
        'owner_user_id',
        'approver_user_id',
        'linked_entity_type',
        'linked_entity_id',
        'active_from',
        'active_to',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active_from' => 'date',
        'active_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approver_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'responsibility_center_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
