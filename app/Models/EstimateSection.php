<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_id',
        'parent_section_id',
        'section_number',
        'name',
        'description',
        'sort_order',
        'is_summary',
        'section_total_amount',
    ];

    protected $casts = [
        'is_summary' => 'boolean',
        'section_total_amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(EstimateSection::class, 'parent_section_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(EstimateSection::class, 'parent_section_id')->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class);
    }

    public function scopeRootSections($query)
    {
        return $query->whereNull('parent_section_id')->orderBy('sort_order');
    }

    public function scopeByEstimate($query, int $estimateId)
    {
        return $query->where('estimate_id', $estimateId);
    }

    public function isRootSection(): bool
    {
        return $this->parent_section_id === null;
    }

    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    public function getFullSectionNumberAttribute(): string
    {
        if ($this->parent) {
            return $this->parent->full_section_number . '.' . $this->section_number;
        }
        return $this->section_number;
    }
}

