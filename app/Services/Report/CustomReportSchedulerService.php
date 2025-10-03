<?php

namespace App\Services\Report;

use App\Models\CustomReport;
use App\Models\CustomReportSchedule;
use App\Services\Report\CustomReportExecutionService;
use App\Services\Logging\LoggingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class CustomReportSchedulerService
{
    public function __construct(
        protected CustomReportExecutionService $executionService,
        protected LoggingService $logging
    ) {}

    public function createSchedule(CustomReport $report, array $scheduleData): CustomReportSchedule
    {
        $organizationId = $report->organization_id;
        
        $maxSchedules = config('custom-reports.limits.max_schedules_per_org', 10);
        $existingCount = CustomReportSchedule::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->count();

        if ($existingCount >= $maxSchedules) {
            throw new \Exception("Превышено максимальное количество активных расписаний ({$maxSchedules})");
        }

        $schedule = CustomReportSchedule::create([
            'custom_report_id' => $report->id,
            'organization_id' => $organizationId,
            'user_id' => $scheduleData['user_id'],
            'schedule_type' => $scheduleData['schedule_type'],
            'schedule_config' => $scheduleData['schedule_config'],
            'filters_preset' => $scheduleData['filters_preset'] ?? null,
            'recipient_emails' => $scheduleData['recipient_emails'],
            'export_format' => $scheduleData['export_format'] ?? 'excel',
            'is_active' => true,
            'next_run_at' => $this->calculateNextRunTime(
                $scheduleData['schedule_type'],
                $scheduleData['schedule_config']
            ),
        ]);

        $report->update(['is_scheduled' => true]);

        $this->logging->business('custom_report_schedule.created', [
            'schedule_id' => $schedule->id,
            'report_id' => $report->id,
            'organization_id' => $organizationId,
            'schedule_type' => $schedule->schedule_type,
        ]);

        return $schedule;
    }

    public function updateSchedule(CustomReportSchedule $schedule, array $data): CustomReportSchedule
    {
        $oldData = $schedule->toArray();

        $schedule->update($data);

        if (isset($data['schedule_type']) || isset($data['schedule_config'])) {
            $schedule->updateNextRunTime(
                $this->calculateNextRunTime(
                    $schedule->schedule_type,
                    $schedule->schedule_config
                )
            );
        }

        $this->logging->business('custom_report_schedule.updated', [
            'schedule_id' => $schedule->id,
            'report_id' => $schedule->custom_report_id,
            'changes' => array_diff_assoc($data, $oldData),
        ]);

        return $schedule->fresh();
    }

    public function executeScheduledReports(): void
    {
        $dueSchedules = CustomReportSchedule::due()->with('customReport')->get();

        $this->logging->technical('scheduled_reports.execution.started', [
            'due_schedules_count' => $dueSchedules->count(),
        ]);

        $successCount = 0;
        $failedCount = 0;

        foreach ($dueSchedules as $schedule) {
            try {
                $this->executeSchedule($schedule);
                $successCount++;
            } catch (\Exception $e) {
                $failedCount++;
                
                $this->logging->technical('scheduled_report.execution.failed', [
                    'schedule_id' => $schedule->id,
                    'report_id' => $schedule->custom_report_id,
                    'error' => $e->getMessage(),
                ], 'error');
            }
        }

        $this->logging->business('scheduled_reports.execution.completed', [
            'total' => $dueSchedules->count(),
            'success' => $successCount,
            'failed' => $failedCount,
        ]);
    }

    public function executeSchedule(CustomReportSchedule $schedule): void
    {
        $report = $schedule->customReport;

        if (!$report) {
            throw new \Exception("Отчет не найден для расписания #{$schedule->id}");
        }

        $result = $this->executionService->executeReport(
            $report,
            $schedule->organization_id,
            $schedule->filters_preset ?? [],
            $schedule->export_format,
            $schedule->user_id
        );

        if (is_array($result) && isset($result['success']) && !$result['success']) {
            throw new \Exception($result['error'] ?? 'Ошибка выполнения отчета');
        }

        $execution = $report->executions()->latest()->first();

        if ($execution && $execution->export_file_id) {
            $this->sendReportByEmail(
                $report,
                $schedule->recipient_emails,
                $execution->exportFile->path ?? null
            );
        }

        $schedule->markAsExecuted($execution->id ?? null);
        
        $nextRunTime = $this->calculateNextRunTime(
            $schedule->schedule_type,
            $schedule->schedule_config
        );
        
        $schedule->updateNextRunTime($nextRunTime);

        $this->logging->business('scheduled_report.executed', [
            'schedule_id' => $schedule->id,
            'report_id' => $report->id,
            'execution_id' => $execution->id ?? null,
            'next_run_at' => $nextRunTime,
        ]);
    }

    public function calculateNextRunTime(string $scheduleType, array $config): Carbon
    {
        $now = Carbon::now();

        return match($scheduleType) {
            CustomReportSchedule::TYPE_DAILY => $this->calculateDailyNextRun($config, $now),
            CustomReportSchedule::TYPE_WEEKLY => $this->calculateWeeklyNextRun($config, $now),
            CustomReportSchedule::TYPE_MONTHLY => $this->calculateMonthlyNextRun($config, $now),
            CustomReportSchedule::TYPE_CUSTOM_CRON => $this->calculateCronNextRun($config, $now),
            default => $now->addDay(),
        };
    }

    protected function calculateDailyNextRun(array $config, Carbon $now): Carbon
    {
        $time = $config['time'] ?? '09:00';
        [$hour, $minute] = explode(':', $time);

        $nextRun = $now->copy()->setTime((int) $hour, (int) $minute, 0);

        if ($nextRun->isPast()) {
            $nextRun->addDay();
        }

        return $nextRun;
    }

    protected function calculateWeeklyNextRun(array $config, Carbon $now): Carbon
    {
        $dayOfWeek = $config['day_of_week'] ?? 1;
        $time = $config['time'] ?? '09:00';
        [$hour, $minute] = explode(':', $time);

        $nextRun = $now->copy()->next($dayOfWeek)->setTime((int) $hour, (int) $minute, 0);

        if ($now->dayOfWeek === $dayOfWeek) {
            $todayAtTime = $now->copy()->setTime((int) $hour, (int) $minute, 0);
            if ($todayAtTime->isFuture()) {
                $nextRun = $todayAtTime;
            }
        }

        return $nextRun;
    }

    protected function calculateMonthlyNextRun(array $config, Carbon $now): Carbon
    {
        $dayOfMonth = $config['day_of_month'] ?? 1;
        $time = $config['time'] ?? '09:00';
        [$hour, $minute] = explode(':', $time);

        $nextRun = $now->copy()->day($dayOfMonth)->setTime((int) $hour, (int) $minute, 0);

        if ($nextRun->isPast()) {
            $nextRun->addMonth();
        }

        return $nextRun;
    }

    protected function calculateCronNextRun(array $config, Carbon $now): Carbon
    {
        $cronExpression = $config['cron_expression'] ?? '0 9 * * *';
        
        $cron = new \Cron\CronExpression($cronExpression);
        
        return Carbon::instance($cron->getNextRunDate());
    }

    public function sendReportByEmail(CustomReport $report, array $recipients, ?string $filePath): void
    {
        if (empty($recipients) || !$filePath) {
            return;
        }

        try {
            Mail::send('emails.scheduled_report', [
                'report_name' => $report->name,
                'report_description' => $report->description,
                'generated_at' => now()->format('d.m.Y H:i'),
            ], function ($message) use ($recipients, $report, $filePath) {
                $message->to($recipients)
                    ->subject("Отчет: {$report->name}")
                    ->attach(Storage::path($filePath));
            });

            $this->logging->business('scheduled_report.email.sent', [
                'report_id' => $report->id,
                'recipients_count' => count($recipients),
            ]);

        } catch (\Exception $e) {
            $this->logging->technical('scheduled_report.email.failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ], 'error');
        }
    }

    public function deactivateSchedule(CustomReportSchedule $schedule): void
    {
        $schedule->deactivate();

        $hasOtherActiveSchedules = CustomReportSchedule::where('custom_report_id', $schedule->custom_report_id)
            ->where('id', '!=', $schedule->id)
            ->where('is_active', true)
            ->exists();

        if (!$hasOtherActiveSchedules) {
            $schedule->customReport->update(['is_scheduled' => false]);
        }

        $this->logging->business('custom_report_schedule.deactivated', [
            'schedule_id' => $schedule->id,
            'report_id' => $schedule->custom_report_id,
        ]);
    }

    public function deleteSchedule(CustomReportSchedule $schedule): void
    {
        $reportId = $schedule->custom_report_id;
        $scheduleId = $schedule->id;

        $schedule->delete();

        $hasOtherSchedules = CustomReportSchedule::where('custom_report_id', $reportId)
            ->exists();

        if (!$hasOtherSchedules) {
            CustomReport::find($reportId)?->update(['is_scheduled' => false]);
        }

        $this->logging->business('custom_report_schedule.deleted', [
            'schedule_id' => $scheduleId,
            'report_id' => $reportId,
        ]);
    }
}

