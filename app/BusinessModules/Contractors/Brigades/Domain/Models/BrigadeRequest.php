<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrigadeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'contractor_organization_id',
        'project_id',
        'title',
        'description',
        'specialization_name',
        'city',
        'team_size_min',
        'team_size_max',
        'status',
        'published_at',
    ];

    protected $casts = [
        'team_size_min' => 'integer',
        'team_size_max' => 'integer',
        'published_at' => 'datetime',
    ];

    public function contractorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'contractor_organization_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(BrigadeResponse::class, 'request_id');
    }
}
