<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrigadeInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'brigade_id',
        'contractor_organization_id',
        'project_id',
        'message',
        'status',
        'starts_at',
        'expires_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function brigade(): BelongsTo
    {
        return $this->belongsTo(BrigadeProfile::class, 'brigade_id');
    }

    public function contractorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'contractor_organization_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
