<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'estimate_id',
        'created_by_user_id',
        'snapshot_type',
        'label',
        'description',
        'snapshot_data',
        'data_size',
        'checksum',
        'created_at',
        'metadata',
    ];

    protected $casts = [
        'snapshot_data' => 'array',
        'created_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeByEstimate($query, int $estimateId)
    {
        return $query->where('estimate_id', $estimateId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('snapshot_type', $type);
    }

    public function scopeManual($query)
    {
        return $query->where('snapshot_type', 'manual');
    }

    public function scopeAutomatic($query)
    {
        return $query->whereIn('snapshot_type', ['auto_approval', 'auto_periodic', 'before_major_change']);
    }

    public function scopeRecent($query, int $count = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($count);
    }

    public function isManual(): bool
    {
        return $this->snapshot_type === 'manual';
    }

    public function calculateChecksum(): string
    {
        return hash('sha256', json_encode($this->snapshot_data));
    }

    public function verifyIntegrity(): bool
    {
        if (!$this->checksum) {
            return true;
        }

        return $this->checksum === $this->calculateChecksum();
    }

    public function getDataSizeInMb(): float
    {
        return round($this->data_size / 1024 / 1024, 2);
    }
}
