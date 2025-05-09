<?php

namespace App\Models\Models\Log;

use Illuminate\Database\Eloquent\Model;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkCompletionLog extends Model
{
    protected $table = 'work_completion_logs';

    protected $fillable = [
        'project_id',
        'work_type_id',
        'user_id',
        'quantity',
        'unit_price',
        'total_price',
        'completion_date',
        'notes',
        'organization_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'completion_date' => 'date',
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    /**
     * Получить проект, к которому относится лог.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Получить вид работы, к которому относится лог.
     */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class, 'work_type_id');
    }

    /**
     * Получить пользователя (прораба), который создал лог.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
