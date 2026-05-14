<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property string $set_number
 * @property string $title
 * @property ExecutiveDocumentStatusEnum $status
 */
final class ExecutiveDocumentSet extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'created_by',
        'set_number',
        'title',
        'status',
        'stage_name',
        'zone_name',
        'planned_transmittal_date',
        'transmitted_at',
        'metadata',
    ];

    protected $casts = [
        'status' => ExecutiveDocumentStatusEnum::class,
        'planned_transmittal_date' => 'date',
        'transmitted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ExecutiveDocument::class, 'document_set_id');
    }

    public function transmittal(): HasOne
    {
        return $this->hasOne(ExecutiveDocumentTransmittal::class, 'document_set_id')->latestOfMany();
    }
}
