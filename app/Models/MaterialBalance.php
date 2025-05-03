<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'project_id',
        'material_id',
        'available_quantity',
        'reserved_quantity',
        'average_price',
        'last_update_date',
        'additional_info',
    ];

    protected $casts = [
        'available_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'average_price' => 'decimal:2',
        'last_update_date' => 'date',
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
     * Получить материал, для которого ведётся учёт остатков.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
