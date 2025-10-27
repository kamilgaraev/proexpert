<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NormativeCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'base_type_id',
        'code',
        'name',
        'description',
        'version',
        'effective_date',
        'sort_order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function baseType(): BelongsTo
    {
        return $this->belongsTo(NormativeBaseType::class, 'base_type_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(NormativeSection::class, 'collection_id')->orderBy('sort_order');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(NormativeRate::class, 'collection_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByBaseType($query, int $baseTypeId)
    {
        return $query->where('base_type_id', $baseTypeId);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    public function getFullCodeAttribute(): string
    {
        return "{$this->baseType->code}-{$this->code}";
    }

    public function getRatesCountAttribute(): int
    {
        return $this->rates()->count();
    }
}
