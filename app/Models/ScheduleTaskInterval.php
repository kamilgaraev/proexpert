<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleTaskInterval extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_task_id',
        'start_date',
        'end_date',
        'duration_days',
        'sort_order',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'duration_days' => 'integer',
        'sort_order' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'schedule_task_id');
    }
}
