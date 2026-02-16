<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomReport extends Model
{
    use HasFactory, SoftDeletes;

    public const CHART_TYPE_TABLE = 'table';
    public const CHART_TYPE_BAR = 'bar';
    public const CHART_TYPE_LINE = 'line';
    public const CHART_TYPE_PIE = 'pie';

    protected $fillable = [
        'name',
        'description',
        'organization_id',
        'user_id',
        'report_category',
        'data_sources',
        'query_config',
        'columns_config',
        'filters_config',
        'aggregations_config',
        'sorting_config',
        'visualization_config',
        'is_shared',
        'is_favorite',
        'is_scheduled',
        'execution_count',
        'last_executed_at',
    ];

    protected $casts = [
        'data_sources' => 'array',
        'query_config' => 'array',
        'columns_config' => 'array',
        'filters_config' => 'array',
        'aggregations_config' => 'array',
        'sorting_config' => 'array',
        'visualization_config' => 'array',
        'is_shared' => 'boolean',
        'is_favorite' => 'boolean',
        'is_scheduled' => 'boolean',
        'execution_count' => 'integer',
        'last_executed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(CustomReportExecution::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CustomReportSchedule::class);
    }

    public function incrementExecutionCount(): void
    {
        $this->increment('execution_count');
        $this->update(['last_executed_at' => now()]);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeShared($query)
    {
        return $query->where('is_shared', true);
    }

    public function scopePersonal($query, int $userId)
    {
        return $query->where('user_id', $userId)->where('is_shared', false);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('report_category', $category);
    }

    public function scopeFavorites($query, int $userId)
    {
        return $query->where('user_id', $userId)->where('is_favorite', true);
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public function canBeEditedBy(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public function canBeViewedBy(int $userId, int $organizationId): bool
    {
        return $this->organization_id === $organizationId && 
               ($this->is_shared || $this->user_id === $userId);
    }
}

