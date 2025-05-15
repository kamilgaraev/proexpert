<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot; // Используем Pivot для связующей таблицы
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkTypeMaterial extends Pivot // Наследуемся от Pivot
{
    use HasFactory, SoftDeletes;

    protected $table = 'work_type_materials';

    // Указываем, что ID автоинкрементируемый, если это так (для Pivot это не всегда по умолчанию)
    public $incrementing = true;

    protected $fillable = [
        'organization_id',
        'work_type_id',
        'material_id',
        'default_quantity',
        'notes',
    ];

    protected $casts = [
        'default_quantity' => 'decimal:4',
    ];

    /**
     * Организация, к которой относится эта норма.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Вид работ, к которому относится норма.
     */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    /**
     * Материал, для которого установлена норма.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
} 