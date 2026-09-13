<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\Models\ScheduleTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BimConstructionProgressGroup extends Model
{
    protected $table = 'bim_construction_progress_groups';

    protected $fillable = [
        'organization_id', 'project_id', 'version_id', 'schedule_task_id', 'title', 'floor', 'zone', 'work_kind',
        'status', 'revision', 'idempotency_key', 'created_by', 'updated_by', 'ended_by', 'ended_at', 'end_reason',
    ];

    protected $casts = ['revision' => 'integer', 'ended_at' => 'datetime'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignArtifactVersion::class, 'version_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'schedule_task_id');
    }

    public function elements(): HasMany
    {
        return $this->hasMany(BimConstructionProgressGroupElement::class, 'group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
