<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetArticleMapping extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'organization_id',
        'budget_article_id',
        'system',
        'one_c_base_id',
        'integration_profile_id',
        'external_code',
        'external_name',
        'mapping_status',
        'mapping_payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'mapping_payload' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(BudgetArticle::class, 'budget_article_id');
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
