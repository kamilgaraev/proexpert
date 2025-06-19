<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompletedWorkMaterial extends Pivot
{
    use HasFactory;

    protected $table = 'completed_work_materials';

    public $incrementing = true;

    protected $fillable = [
        'completed_work_id',
        'material_id',
        'quantity',
        'unit_price',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function completedWork(): BelongsTo
    {
        return $this->belongsTo(CompletedWork::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
} 