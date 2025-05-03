<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialWriteOff extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'material_id',
        'work_type_id',
        'user_id',
        'quantity',
        'write_off_date',
        'notes',
        'status',
        'additional_info',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'write_off_date' => 'date',
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
     * Получить списанный материал.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Получить вид работ, на который был списан материал.
     */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    /**
     * Получить пользователя, создавшего запись о списании.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить файлы, прикрепленные к списанию.
     */
    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
