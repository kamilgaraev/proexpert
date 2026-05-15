<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Models;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ChangeClaim extends Model
{
    use SoftDeletes;

    protected $table = 'change_management_claims';

    protected $fillable = [
        'organization_id',
        'project_id',
        'change_request_id',
        'created_by_user_id',
        'claim_number',
        'title',
        'description',
        'amount',
        'status',
        'evidence',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'evidence' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
