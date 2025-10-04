<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\Organization;

class ScheduledReport extends Model
{
    use SoftDeletes;

    protected $table = 'scheduled_reports';

    protected $fillable = [
        'dashboard_id',
        'user_id',
        'organization_id',
        'name',
        'description',
        'frequency',
        'cron_expression',
        'time_of_day',
        'days_of_week',
        'day_of_month',
        'export_formats',
        'attach_excel',
        'attach_pdf',
        'recipients',
        'cc_recipients',
        'email_subject',
        'email_body',
        'filters',
        'widgets',
        'include_raw_data',
        'is_active',
        'next_run_at',
        'last_run_at',
        'last_run_status',
        'last_run_error',
        'run_count',
        'success_count',
        'failure_count',
        'start_date',
        'end_date',
        'max_runs',
        'metadata',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'export_formats' => 'array',
        'recipients' => 'array',
        'cc_recipients' => 'array',
        'filters' => 'array',
        'widgets' => 'array',
        'metadata' => 'array',
        'attach_excel' => 'boolean',
        'attach_pdf' => 'boolean',
        'include_raw_data' => 'boolean',
        'is_active' => 'boolean',
        'day_of_month' => 'integer',
        'run_count' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'max_runs' => 'integer',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected $attributes = [
        'is_active' => true,
        'attach_excel' => false,
        'attach_pdf' => true,
        'include_raw_data' => false,
        'run_count' => 0,
        'success_count' => 0,
        'failure_count' => 0,
        'frequency' => 'daily',
        'time_of_day' => '09:00:00',
    ];

    // Relationships

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByFrequency($query, string $frequency)
    {
        return $query->where('frequency', $frequency);
    }

    public function scopeDueForRun($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('next_run_at')
                  ->orWhere('next_run_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('max_runs')
                  ->orWhereRaw('run_count < max_runs');
            });
    }

    // Methods

    public function markAsStarted(): void
    {
        $this->update([
            'last_run_at' => now(),
            'last_run_status' => 'pending',
        ]);
    }

    public function markAsSuccess(): void
    {
        $this->update([
            'last_run_status' => 'success',
            'last_run_error' => null,
            'run_count' => $this->run_count + 1,
            'success_count' => $this->success_count + 1,
            'next_run_at' => $this->calculateNextRunTime(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'last_run_status' => 'failed',
            'last_run_error' => $error,
            'run_count' => $this->run_count + 1,
            'failure_count' => $this->failure_count + 1,
            'next_run_at' => $this->calculateNextRunTime(),
        ]);
    }

    public function calculateNextRunTime(): ?\DateTime
    {
        if (!$this->is_active) {
            return null;
        }

        $now = now();

        switch ($this->frequency) {
            case 'daily':
                return $now->copy()->addDay()->setTimeFromTimeString($this->time_of_day);

            case 'weekly':
                $nextRun = $now->copy()->addWeek()->setTimeFromTimeString($this->time_of_day);
                // Корректируем день недели, если задан
                if (!empty($this->days_of_week)) {
                    $targetDay = min($this->days_of_week);
                    $nextRun->dayOfWeek = $targetDay;
                }
                return $nextRun;

            case 'monthly':
                $nextRun = $now->copy()->addMonth()->setTimeFromTimeString($this->time_of_day);
                if ($this->day_of_month) {
                    $nextRun->day = min($this->day_of_month, $nextRun->daysInMonth);
                }
                return $nextRun;

            case 'custom':
                // Используем cron_expression для расчета
                // Требуется библиотека cron-expression
                return null; // TODO: реализовать через cron parser

            default:
                return null;
        }
    }

    public function shouldRun(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Проверяем start_date
        if ($this->start_date && now()->lt($this->start_date)) {
            return false;
        }

        // Проверяем end_date
        if ($this->end_date && now()->gt($this->end_date)) {
            return false;
        }

        // Проверяем max_runs
        if ($this->max_runs && $this->run_count >= $this->max_runs) {
            return false;
        }

        // Проверяем next_run_at
        if (!$this->next_run_at || now()->lt($this->next_run_at)) {
            return false;
        }

        return true;
    }

    public function getSuccessRate(): float
    {
        if ($this->run_count === 0) {
            return 0.0;
        }

        return round(($this->success_count / $this->run_count) * 100, 2);
    }
}

