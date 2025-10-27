<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NormativeBaseType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'version',
        'effective_date',
        'last_updated_date',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'last_updated_date' => 'date',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function collections(): HasMany
    {
        return $this->hasMany(NormativeCollection::class, 'base_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function getFullNameAttribute(): string
    {
        return $this->version ? "{$this->name} ({$this->version})" : $this->name;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}

