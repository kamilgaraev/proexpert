<?php

namespace App\Models;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'material_id',
        'estimate_item_id',
        'project_material_delivery_id',
        'material_name',
        'quantity',
        'measurement_unit',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    // === RELATIONSHIPS ===

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'estimate_item_id');
    }

    public function projectMaterialDelivery(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterialDelivery::class, 'project_material_delivery_id');
    }
}

