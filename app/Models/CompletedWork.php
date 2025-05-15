<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompletedWork extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'work_type_id',
        'user_id',
        'quantity',
        'price',
        'total_amount',
        'completion_date',
        'notes',
        'status',
        'additional_info',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'completion_date' => 'date',
        'additional_info' => 'array',
    ];

    /**
     * Получить организацию, которой принадлежит запись.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить проект, к которому относится запись.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Получить вид выполненной работы.
     */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    /**
     * Получить пользователя, создавшего запись о выполненной работе.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить договор, к которому относится выполненная работа.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Получить файлы, прикрепленные к выполненной работе.
     */
    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
