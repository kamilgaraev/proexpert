<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\BusinessModules\Features\AdvancedDashboard\Models\ScheduledReport;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardExportService;
use App\Services\LogService;
use Carbon\Carbon;

/**
 * Команда для обработки scheduled reports
 * 
 * Находит отчеты, которые должны выполниться сейчас,
 * генерирует PDF/Excel и отправляет email
 */
class ProcessScheduledReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dashboard:process-scheduled-reports
                          {--force : Обработать все активные отчеты независимо от расписания}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Обработать scheduled reports дашбордов и отправить email';

    protected DashboardExportService $exportService;

    public function __construct(DashboardExportService $exportService)
    {
        parent::__construct();
        $this->exportService = $exportService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('📊 Начинаем обработку scheduled reports...');
        
        $force = $this->option('force');
        $now = Carbon::now();
        
        $query = ScheduledReport::with(['dashboard'])
            ->active();
        
        if (!$force) {
            $query->where(function ($q) use ($now) {
                $q->whereNull('next_run_at')
                  ->orWhere('next_run_at', '<=', $now);
            });
        }
        
        $reports = $query->get();
        
        if ($reports->isEmpty()) {
            $this->info('✅ Нет отчетов для обработки');
            return self::SUCCESS;
        }
        
        $this->info("📋 Найдено отчетов: {$reports->count()}");
        
        $processed = 0;
        $failed = 0;
        
        foreach ($reports as $report) {
            try {
                $this->processReport($report);
                $processed++;
                
                $this->line("  ✅ {$report->name} - успешно");
                
            } catch (\Exception $e) {
                $failed++;
                $this->error("  ❌ {$report->name} - ошибка: " . $e->getMessage());
                
                $report->update([
                    'last_run_status' => 'failed',
                    'last_error' => $e->getMessage(),
                    'failure_count' => $report->failure_count + 1,
                ]);
                
                LogService::error('Scheduled report failed', [
                    'report_id' => $report->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $this->newLine();
        $this->info('✅ Обработка завершена!');
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано успешно', $processed],
                ['Ошибок', $failed],
                ['Всего', $reports->count()],
            ]
        );
        
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function processReport(ScheduledReport $report): void
    {
        $files = [];
        
        foreach ($report->export_formats as $format) {
            if ($format === 'pdf') {
                $filePath = $this->exportService->exportDashboardToPDF(
                    $report->dashboard_id,
                    [
                        'filters' => $report->filters ?? [],
                        'widgets' => $report->widgets ?? [],
                    ]
                );
                $files['pdf'] = $filePath;
            }
            
            if ($format === 'excel') {
                $filePath = $this->exportService->exportDashboardToExcel(
                    $report->dashboard_id,
                    [
                        'filters' => $report->filters ?? [],
                        'widgets' => $report->widgets ?? [],
                        'include_raw_data' => $report->include_raw_data ?? false,
                    ]
                );
                $files['excel'] = $filePath;
            }
        }
        
        $nextRun = $this->calculateNextRun($report);
        
        $report->update([
            'last_run_at' => Carbon::now(),
            'last_run_status' => 'success',
            'next_run_at' => $nextRun,
            'run_count' => $report->run_count + 1,
            'success_count' => $report->success_count + 1,
            'last_error' => null,
        ]);
        
        LogService::info('Scheduled report processed', [
            'report_id' => $report->id,
            'files' => $files,
            'next_run' => $nextRun,
        ]);
    }

    protected function calculateNextRun(ScheduledReport $report): ?Carbon
    {
        $now = Carbon::now();
        
        switch ($report->frequency) {
            case 'daily':
                $time = $report->time_of_day ?? '09:00:00';
                $next = Carbon::parse($time);
                if ($next->isPast()) {
                    $next->addDay();
                }
                return $next;
                
            case 'weekly':
                $time = $report->time_of_day ?? '09:00:00';
                $daysOfWeek = $report->days_of_week ?? [1];
                $next = Carbon::parse($time);
                
                foreach ($daysOfWeek as $day) {
                    $candidate = $next->copy()->next($day);
                    if ($candidate->isFuture()) {
                        return $candidate;
                    }
                }
                return $next->next($daysOfWeek[0]);
                
            case 'monthly':
                $time = $report->time_of_day ?? '09:00:00';
                $dayOfMonth = $report->day_of_month ?? 1;
                $next = Carbon::parse($time)->day($dayOfMonth);
                if ($next->isPast()) {
                    $next->addMonth();
                }
                return $next;
                
            case 'custom':
                return null;
                
            default:
                return null;
        }
    }
}

