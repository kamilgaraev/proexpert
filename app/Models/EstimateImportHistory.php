<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateImportHistory extends Model
{
    use HasFactory;

    protected $table = 'estimate_import_history';

    protected $fillable = [
        'organization_id',
        'user_id',
        'estimate_id',
        'file_name',
        'file_path',
        'file_size',
        'file_format',
        'status',
        'items_total',
        'items_imported',
        'items_skipped',
        'mapping_data',
        'result_log',
        'processing_time_ms',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'items_total' => 'integer',
        'items_imported' => 'integer',
        'items_skipped' => 'integer',
        'mapping_data' => 'array',
        'result_log' => 'array',
        'processing_time_ms' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->items_total === 0) {
            return 0;
        }
        return ($this->items_imported / $this->items_total) * 100;
    }
}

