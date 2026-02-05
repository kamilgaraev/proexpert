<?php

namespace App\BusinessModules\Features\SiteRequests\Models;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Группа заявок (папка)
 */
class SiteRequestGroup extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'site_request_groups';

    protected $fillable = [
        'organization_id',
        'project_id',
        'user_id',
        'title',
        'status',
        'description',
    ];

    protected $casts = [
        'status' => SiteRequestStatusEnum::class,
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(SiteRequest::class, 'site_request_group_id');
    }
}
