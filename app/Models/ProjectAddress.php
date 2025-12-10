<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAddress extends Model
{
    protected $fillable = [
        'project_id',
        'raw_address',
        'country',
        'region',
        'city',
        'district',
        'street',
        'house',
        'postal_code',
        'latitude',
        'longitude',
        'geocoded_at',
        'geocoding_provider',
        'geocoding_confidence',
        'geocoding_error',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geocoding_confidence' => 'decimal:2',
        'geocoded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the project that owns the address
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Check if address is geocoded
     */
    public function isGeocoded(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Get full formatted address
     */
    public function getFormattedAddress(): string
    {
        $parts = array_filter([
            $this->country,
            $this->region,
            $this->city,
            $this->street,
            $this->house,
        ]);

        return implode(', ', $parts) ?: $this->raw_address;
    }
}

