<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BudgetImportBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'organization_id',
        'budget_version_id',
        'source_format',
        'status',
        'template_code',
        'mapping_mode',
        'uploaded_by',
        'preview_summary',
        'error_summary',
        'committed_at',
        'committed_by',
    ];

    protected $casts = [
        'preview_summary' => 'array',
        'error_summary' => 'array',
        'committed_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BudgetImportRow::class, 'budget_import_batch_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
