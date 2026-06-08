<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class BudgetPeriod extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'organization_id',
        'code',
        'name',
        'period_type',
        'starts_at',
        'ends_at',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class, 'budget_period_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
