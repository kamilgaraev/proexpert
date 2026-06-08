<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class BudgetLine extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'budget_version_id',
        'budget_article_id',
        'responsibility_center_id',
        'project_id',
        'contract_id',
        'counterparty_id',
        'currency',
        'description',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(BudgetArticle::class, 'budget_article_id');
    }

    public function responsibilityCenter(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityCenter::class, 'responsibility_center_id');
    }

    public function amounts(): HasMany
    {
        return $this->hasMany(BudgetAmount::class, 'budget_line_id');
    }
}
