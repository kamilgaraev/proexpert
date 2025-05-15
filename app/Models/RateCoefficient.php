<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\RateCoefficient\RateCoefficientTypeEnum;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\Models\Organization;
use Carbon\Carbon;

class RateCoefficient extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'value',
        'type',
        'applies_to',
        'scope',
        'description',
        'is_active',
        'valid_from',
        'valid_to',
        'conditions',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'type' => RateCoefficientTypeEnum::class,
        'applies_to' => RateCoefficientAppliesToEnum::class,
        'scope' => RateCoefficientScopeEnum::class,
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'conditions' => 'array',
    ];

    /**
     * Организация, к которой относится коэффициент.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Можно добавить scopes для удобного поиска, например:
    // public function scopeActive($query)
    // {
    //     return $query->where('is_active', true)
    //         ->where(function ($q) {
    //             $q->whereNull('valid_from')->orWhere('valid_from', '<=', Carbon::now());
    //         })
    //         ->where(function ($q) {
    //             $q->whereNull('valid_to')->orWhere('valid_to', '>=', Carbon::now());
    //         });
    // }
} 