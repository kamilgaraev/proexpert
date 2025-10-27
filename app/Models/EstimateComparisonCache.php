<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class EstimateComparisonCache extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'estimate_comparison_cache';

    protected $fillable = [
        'estimate_id_1',
        'estimate_id_2',
        'comparison_type',
        'diff_data',
        'summary',
        'created_at',
        'expires_at',
    ];

    protected $casts = [
        'diff_data' => 'array',
        'summary' => 'array',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function estimate1(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'estimate_id_1');
    }

    public function estimate2(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'estimate_id_2');
    }

    public function scopeForEstimates($query, int $estimateId1, int $estimateId2)
    {
        return $query->where(function ($q) use ($estimateId1, $estimateId2) {
            $q->where('estimate_id_1', $estimateId1)
              ->where('estimate_id_2', $estimateId2);
        })->orWhere(function ($q) use ($estimateId1, $estimateId2) {
            $q->where('estimate_id_1', $estimateId2)
              ->where('estimate_id_2', $estimateId1);
        });
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }

        return Carbon::parse($this->expires_at)->isPast();
    }

    public function setTtl(int $hours = 24): void
    {
        $this->expires_at = now()->addHours($hours);
        $this->save();
    }

    public static function cleanupExpired(): int
    {
        return self::expired()->delete();
    }
}

