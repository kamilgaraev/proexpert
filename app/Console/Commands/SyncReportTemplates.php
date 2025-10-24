<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ReportTemplate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SyncReportTemplates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:sync-templates 
                            {--force : Force sync even if templates exist}
                            {--type= : Sync only specific report type}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Синхронизация системных шаблонов отчетов из JSON файлов';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Начинаем синхронизацию системных шаблонов отчетов...');
        
        $templatesPath = config_path('report-templates');
        
        if (!is_dir($templatesPath)) {
            $this->error("Директория {$templatesPath} не найдена!");
            return self::FAILURE;
        }

        $type = $this->option('type');
        $force = $this->option('force');
        
        $pattern = $type ? "{$templatesPath}/{$type}.json" : "{$templatesPath}/*.json";
        $jsonFiles = glob($pattern);

        if (empty($jsonFiles)) {
            $this->warn('Файлы шаблонов не найдены.');
            return self::SUCCESS;
        }

        $synced = 0;
        $errors = 0;

        foreach ($jsonFiles as $jsonFile) {
            $reportType = basename($jsonFile, '.json');
            
            $this->line("Обработка шаблонов для типа: {$reportType}");
            
            try {
                $templates = json_decode(File::get($jsonFile), true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->error("  ✗ Ошибка парсинга JSON: " . json_last_error_msg());
                    $errors++;
                    continue;
                }

                foreach ($templates as $templateData) {
                    $result = $this->syncTemplate($templateData, $force);
                    
                    if ($result['success']) {
                        $this->info("  ✓ {$templateData['name']} - {$result['action']}");
                        $synced++;
                    } else {
                        $this->error("  ✗ {$templateData['name']} - {$result['message']}");
                        $errors++;
                    }
                }
                
            } catch (\Exception $e) {
                $this->error("  ✗ Ошибка обработки файла: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Синхронизация завершена: {$synced} синхронизировано, {$errors} ошибок");
        
        return self::SUCCESS;
    }

    /**
     * Синхронизация одного шаблона.
     */
    protected function syncTemplate(array $data, bool $force): array
    {
        try {
            // Ищем существующий системный шаблон
            $existing = ReportTemplate::where('report_type', $data['report_type'])
                ->where('name', $data['name'])
                ->whereNull('organization_id')
                ->whereNull('user_id')
                ->first();

            if ($existing && !$force) {
                return [
                    'success' => true,
                    'action' => 'уже существует (пропущен)'
                ];
            }

            // Подготавливаем данные для сохранения
            $templateData = [
                'name' => $data['name'],
                'report_type' => $data['report_type'],
                'is_default' => $data['is_default'] ?? false,
                'columns_config' => $data['columns_config'],
                'organization_id' => null,
                'user_id' => null,
            ];

            if ($existing) {
                $existing->update($templateData);
                $action = 'обновлен';
            } else {
                ReportTemplate::create($templateData);
                $action = 'создан';
            }

            return [
                'success' => true,
                'action' => $action
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync report template', [
                'template' => $data['name'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

