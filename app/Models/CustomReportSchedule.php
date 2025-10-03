<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class CustomReportSchedule extends Model
{
    use HasFactory;

    const TYPE_DAILY = 'daily';
    const TYPE_WEEKLY = 'weekly';
    const TYPE_MONTHLY = 'monthly';
    const TYPE_CUSTOM_CRON = 'custom_cron';

    const FORMAT_CSV = 'csv';
    const FORMAT_EXCEL = 'excel';
    const FORMAT_PDF = 'pdf';

    protected $fillable = [
        'custom_report_id',
        'organization_id',
        'user_id',
        'schedule_type',
        'schedule_config',
        'filters_preset',
        'recipient_emails',
        'export_format',
        'is_active',
        'last_run_at',
        'next_run_at',
        'last_execution_id',
    ];

    protected $casts = [
        'schedule_config' => 'array',
        'filters_preset' => 'array',
        'recipient_emails' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function customReport(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastExecution(): BelongsTo
    {
        return $this->belongsTo(CustomReportExecution::class, 'last_execution_id');
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function updateNextRunTime(Carbon $nextRunAt): void
    {
        $this->update(['next_run_at' => $nextRunAt]);
    }

    public function markAsExecuted(int $executionId): void
    {
        $this->update([
            'last_run_at' => now(),
            'last_execution_id' => $executionId,
        ]);
    }

    public function isDue(): bool
    {
        return $this->is_active && 
               $this->next_run_at && 
               $this->next_run_at->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDue($query)
    {
        return $query->active()
                    ->whereNotNull('next_run_at')
                    ->where('next_run_at', '<=', now());
    }

    public function scopeForReport($query, int $reportId)
    {
        return $query->where('custom_report_id', $reportId);
    }
}

