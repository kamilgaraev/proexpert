<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomReportExecution extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    const UPDATED_AT = null;

    protected $fillable = [
        'custom_report_id',
        'user_id',
        'organization_id',
        'applied_filters',
        'execution_time_ms',
        'result_rows_count',
        'export_format',
        'export_file_id',
        'status',
        'error_message',
        'query_sql',
        'completed_at',
    ];

    protected $casts = [
        'applied_filters' => 'array',
        'execution_time_ms' => 'integer',
        'result_rows_count' => 'integer',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function customReport(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function exportFile(): BelongsTo
    {
        return $this->belongsTo(ReportFile::class, 'export_file_id');
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(int $executionTime, int $rowsCount, ?string $exportFileId = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'execution_time_ms' => $executionTime,
            'result_rows_count' => $rowsCount,
            'export_file_id' => $exportFileId,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function scopeForReport($query, int $reportId)
    {
        return $query->where('custom_report_id', $reportId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}

