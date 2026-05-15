<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ChangeManagementRfi extends Model
{
    use SoftDeletes;

    protected $table = 'change_management_rfis';

    protected $fillable = [
        'organization_id',
        'project_id',
        'created_by_user_id',
        'rfi_number',
        'subject',
        'question',
        'addressee_type',
        'status',
        'response_due_date',
        'answer',
        'sent_at',
        'answered_at',
        'accepted_at',
        'closed_at',
        'attachments',
        'metadata',
    ];

    protected $casts = [
        'response_due_date' => 'date',
        'sent_at' => 'datetime',
        'answered_at' => 'datetime',
        'accepted_at' => 'datetime',
        'closed_at' => 'datetime',
        'attachments' => 'array',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
