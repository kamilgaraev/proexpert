<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateLibraryItemPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'library_item_id',
        'normative_rate_id',
        'normative_rate_code',
        'name',
        'description',
        'measurement_unit',
        'sort_order',
        'quantity_formula',
        'default_quantity',
        'coefficient',
        'parameters_mapping',
        'metadata',
    ];

    protected $casts = [
        'default_quantity' => 'decimal:4',
        'coefficient' => 'decimal:4',
        'parameters_mapping' => 'array',
        'metadata' => 'array',
    ];

    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(EstimateLibraryItem::class, 'library_item_id');
    }

    public function normativeRate(): BelongsTo
    {
        return $this->belongsTo(NormativeRate::class, 'normative_rate_id');
    }

    public function scopeByLibraryItem($query, int $libraryItemId)
    {
        return $query->where('library_item_id', $libraryItemId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function calculateQuantity(array $parameters = []): float
    {
        if (!$this->quantity_formula) {
            return (float) $this->default_quantity;
        }

        try {
            $formula = $this->quantity_formula;
            
            foreach ($parameters as $key => $value) {
                $formula = str_replace("{{$key}}", $value, $formula);
            }
            
            if (preg_match('/[a-zA-Z{]/', $formula)) {
                return (float) $this->default_quantity;
            }
            
            $result = eval("return $formula;");
            
            return (float) ($result * $this->coefficient);
        } catch (\Throwable $e) {
            return (float) $this->default_quantity;
        }
    }

    public function hasFormula(): bool
    {
        return !empty($this->quantity_formula);
    }
}
