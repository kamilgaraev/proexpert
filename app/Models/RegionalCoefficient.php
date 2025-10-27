<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class RegionalCoefficient extends Model
{
    use HasFactory;

    protected $fillable = [
        'coefficient_type',
        'name',
        'description',
        'region_code',
        'region_name',
        'coefficient_value',
        'effective_from',
        'effective_to',
        'applies_to',
        'application_rules',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'coefficient_value' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(RateCoefficientApplication::class, 'coefficient_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('coefficient_type', $type);
    }

    public function scopeByRegion($query, string $regionCode)
    {
        return $query->where('region_code', $regionCode);
    }

    public function scopeEffectiveOn($query, Carbon $date)
    {
        return $query->where(function ($q) use ($date) {
            $q->whereNull('effective_from')
              ->orWhere('effective_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('effective_to')
              ->orWhere('effective_to', '>=', $date);
        });
    }

    public function isEffectiveOn(Carbon $date): bool
    {
        $effectiveFrom = $this->effective_from ? Carbon::parse($this->effective_from) : null;
        $effectiveTo = $this->effective_to ? Carbon::parse($this->effective_to) : null;
        
        if ($effectiveFrom && $date->lt($effectiveFrom)) {
            return false;
        }
        
        if ($effectiveTo && $date->gt($effectiveTo)) {
            return false;
        }
        
        return true;
    }

    public function getFormattedCoefficientAttribute(): string
    {
        return number_format($this->coefficient_value, 4, '.', '');
    }

    public function getFullNameAttribute(): string
    {
        $name = $this->name;
        
        if ($this->region_name) {
            $name .= " ({$this->region_name})";
        }
        
        return $name;
    }
}
