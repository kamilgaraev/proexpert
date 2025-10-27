<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class NormativeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'collection_id',
        'section_id',
        'code',
        'name',
        'description',
        'measurement_unit',
        'base_price',
        'materials_cost',
        'machinery_cost',
        'labor_cost',
        'labor_hours',
        'machinery_hours',
        'base_price_year',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'materials_cost' => 'decimal:2',
        'machinery_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'labor_hours' => 'decimal:4',
        'machinery_hours' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(NormativeCollection::class, 'collection_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(NormativeSection::class, 'section_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(NormativeRateResource::class, 'rate_id');
    }

    public function estimateItems(): HasMany
    {
        return $this->hasMany(EstimateItem::class, 'normative_rate_id');
    }

    public function scopeByCollection($query, int $collectionId)
    {
        return $query->where('collection_id', $collectionId);
    }

    public function scopeBySection($query, int $sectionId)
    {
        return $query->where('section_id', $sectionId);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function scopeSearch($query, string $searchTerm)
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('russian', ?)",
            [$searchTerm]
        );
    }

    public function scopeFuzzySearch($query, string $searchTerm)
    {
        return $query->whereRaw(
            "similarity(name, ?) > 0.3 OR similarity(code, ?) > 0.3",
            [$searchTerm, $searchTerm]
        )->orderByRaw(
            "GREATEST(similarity(name, ?), similarity(code, ?)) DESC",
            [$searchTerm, $searchTerm]
        );
    }

    public function scopeWithUsageStats($query)
    {
        return $query->leftJoin('estimate_items', 'normative_rates.id', '=', 'estimate_items.normative_rate_id')
            ->select('normative_rates.*')
            ->selectRaw('COUNT(DISTINCT estimate_items.estimate_id) as usage_count')
            ->groupBy('normative_rates.id');
    }

    public function getFullCodeAttribute(): string
    {
        return "{$this->collection->code}.{$this->code}";
    }

    public function getTotalCostAttribute(): float
    {
        return (float) ($this->materials_cost + $this->machinery_cost + $this->labor_cost);
    }

    public function hasResources(): bool
    {
        return $this->resources()->exists();
    }

    public function getUsageCount(): int
    {
        return $this->estimateItems()->distinct('estimate_id')->count('estimate_id');
    }
}

