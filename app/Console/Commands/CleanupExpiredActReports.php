<?php

namespace App\Console\Commands;

use App\Services\ActReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredActReports extends Command
{
    protected $signature = 'act-reports:cleanup 
                          {--dry-run : Показать какие отчеты будут удалены без фактического удаления}';

    protected $description = 'Удаляет просроченные отчеты актов и их файлы с S3';

    protected ActReportService $actReportService;

    public function __construct(ActReportService $actReportService)
    {
        parent::__construct();
        $this->actReportService = $actReportService;
    }

    public function handle(): int
    {
        $this->info('Начинаю очистку просроченных отчетов актов...');

        if ($this->option('dry-run')) {
            $this->info('РЕЖИМ ТЕСТИРОВАНИЯ - файлы не будут удалены');
            $expiredReports = \App\Models\ActReport::where('expires_at', '<', now())->get();
            
            if ($expiredReports->isEmpty()) {
                $this->info('Просроченных отчетов не найдено.');
                return self::SUCCESS;
            }

            $this->info("Найдено {$expiredReports->count()} просроченных отчетов:");
            
            $headers = ['ID', 'Номер отчета', 'Название', 'Формат', 'Истек', 'Размер файла'];
            $rows = [];
            
            foreach ($expiredReports as $report) {
                $rows[] = [
                    $report->id,
                    $report->report_number,
                    \Illuminate\Support\Str::limit($report->title, 30),
                    $report->format,
                    $report->expires_at->format('d.m.Y H:i'),
                    $report->getFileSizeFormatted()
                ];
            }
            
            $this->table($headers, $rows);
            
            return self::SUCCESS;
        }

        try {
            $deletedCount = $this->actReportService->deleteExpiredReports();
            
            if ($deletedCount > 0) {
                $this->info("Успешно удалено {$deletedCount} просроченных отчетов актов.");
                Log::info("Очистка просроченных отчетов актов завершена", [
                    'deleted_count' => $deletedCount
                ]);
            } else {
                $this->info('Просроченных отчетов актов не найдено.');
            }
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка при очистке просроченных отчетов: ' . $e->getMessage());
            Log::error('Ошибка при очистке просроченных отчетов актов', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return self::FAILURE;
        }
    }
} 