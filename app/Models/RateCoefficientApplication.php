<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RateCoefficientApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'coefficient_id',
        'rate_id',
        'section_id',
        'collection_id',
        'application_level',
        'conditions',
        'is_mandatory',
        'metadata',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'metadata' => 'array',
    ];

    public function coefficient(): BelongsTo
    {
        return $this->belongsTo(RegionalCoefficient::class, 'coefficient_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(NormativeRate::class, 'rate_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(NormativeSection::class, 'section_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(NormativeCollection::class, 'collection_id');
    }

    public function scopeByCoefficient($query, int $coefficientId)
    {
        return $query->where('coefficient_id', $coefficientId);
    }

    public function scopeByLevel($query, string $level)
    {
        return $query->where('application_level', $level);
    }

    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    public function scopeForRate($query, int $rateId)
    {
        return $query->where('application_level', 'rate')
            ->where('rate_id', $rateId);
    }

    public function scopeForSection($query, int $sectionId)
    {
        return $query->where('application_level', 'section')
            ->where('section_id', $sectionId);
    }

    public function scopeForCollection($query, int $collectionId)
    {
        return $query->where('application_level', 'collection')
            ->where('collection_id', $collectionId);
    }

    public function appliesTo(NormativeRate $rate): bool
    {
        switch ($this->application_level) {
            case 'rate':
                return $this->rate_id === $rate->id;
                
            case 'section':
                return $this->section_id === $rate->section_id;
                
            case 'collection':
                return $this->collection_id === $rate->collection_id;
                
            default:
                return false;
        }
    }
}
