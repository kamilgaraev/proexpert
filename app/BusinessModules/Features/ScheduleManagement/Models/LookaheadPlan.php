<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class LookaheadPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'schedule_id',
        'created_by_user_id',
        'title',
        'start_date',
        'end_date',
        'status',
        'metadata',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(LookaheadPlanTask::class);
    }

    public function dailyPlans(): HasMany
    {
        return $this->hasMany(DailyWorkPlan::class);
    }
}
