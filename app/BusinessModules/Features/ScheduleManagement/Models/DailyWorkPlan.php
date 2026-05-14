<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use App\Models\ProjectSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class DailyWorkPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'schedule_id',
        'lookahead_plan_id',
        'created_by_user_id',
        'work_date',
        'status',
        'published_at',
        'submitted_at',
        'accepted_at',
        'accepted_by_user_id',
        'returned_at',
        'returned_by_user_id',
        'return_reason',
        'closed_at',
        'closed_by_user_id',
        'revision_of_daily_plan_id',
        'revision_number',
        'revised_at',
        'revised_by_user_id',
        'revision_reason',
        'summary_comment',
        'metadata',
    ];

    protected $casts = [
        'work_date' => 'date',
        'published_at' => 'datetime',
        'submitted_at' => 'datetime',
        'accepted_at' => 'datetime',
        'returned_at' => 'datetime',
        'closed_at' => 'datetime',
        'revised_at' => 'datetime',
        'revision_number' => 'integer',
        'metadata' => 'array',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function lookaheadPlan(): BelongsTo
    {
        return $this->belongsTo(LookaheadPlan::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_daily_plan_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DailyWorkPlanAssignment::class);
    }
}
