<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AcceptanceScope extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'project_location_id',
        'created_by_user_id',
        'title',
        'description',
        'status',
        'planned_acceptance_date',
        'accepted_at',
        'handed_over_at',
        'reopened_at',
        'metadata',
    ];

    protected $casts = [
        'planned_acceptance_date' => 'date',
        'accepted_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'reopened_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class, 'project_location_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(AcceptanceChecklist::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AcceptanceSession::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AcceptanceFinding::class);
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(AcceptanceSignoff::class);
    }

    public function handoverPackage(): HasOne
    {
        return $this->hasOne(HandoverPackage::class);
    }
}
