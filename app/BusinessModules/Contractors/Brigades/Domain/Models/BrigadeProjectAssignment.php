<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrigadeProjectAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'brigade_id',
        'project_id',
        'contractor_organization_id',
        'status',
        'starts_at',
        'ends_at',
        'notes',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function brigade(): BelongsTo
    {
        return $this->belongsTo(BrigadeProfile::class, 'brigade_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contractorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'contractor_organization_id');
    }
}
