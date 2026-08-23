<?php

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\EstimateItem;
use App\Models\ImportSession;
use App\Models\Organization;
use App\Models\User;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealSmetaImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Этот тест предназначен для ручного запуска пользователем на реальных данных.
     * Он проверяет, что файл импортируется с сохранением иерархии разделов.
     */
    public function test_real_smeta_import_hierarchy()
    {
        $fileName = 'smeta-stroitelstvo-skladskogo-zdaniya.xlsx';
        $filePath = base_path($fileName);
        
        if (!file_exists($filePath)) {
            // Пробуем также путь в корне /var/www/prohelper если base_path не помог (хотя обычно это он и есть)
            if (!file_exists($filePath)) {
                $this->markTestSkipped("Файл не найден по пути: {$filePath}. Пожалуйста, убедись, что файл лежит в корне проекта (рядом с artisan)");
            }
        }

        // 1. Подготовка контекста (используем существующие или создаем новые)
        Queue::fake();
        Storage::fake('s3');

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        
        // Смете ТРЕБУЕТСЯ проект по ограничениям БД
        $project = \App\Models\Project::first() ?: \App\Models\Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Тестовый проект для импорта'
        ]);
        
        /** @var EstimateImportService $importService */
        $importService = app(EstimateImportService::class);

        // 2. Подготовка файла в изолированном S3-хранилище теста
        $storedPath = "org-{$organization->id}/estimate-imports/real-smeta.xlsx";
        Storage::disk('s3')->put($storedPath, (string) file_get_contents($filePath));
        $session = ImportSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => 'uploading',
            'file_path' => $storedPath,
            'file_name' => basename($filePath),
            'file_size' => filesize($filePath),
            'file_format' => 'xlsx',
            'options' => [],
            'stats' => ['progress' => 0],
        ]);
        $fileId = $session->id;

        // 3. Детекция формата и маппинг
        $format = $importService->detectFormat($fileId);
        $mapping = $format['detected_columns'];
        
        // 4. Выполнение импорта (синхронно для теста)
        $settings = [
            'name' => 'Тест реальной сметы ' . now()->toDateTimeString(),
            'type' => 'local',
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ];

        echo "\nНачинаю импорт файла: " . basename($filePath) . "\n";
        
        $session->refresh();
        $options = $session->options ?? [];
        $options['matching_config'] = $mapping;
        $options['estimate_settings'] = $settings;
        $options['validate_only'] = false;
        $session->update([
            'options' => $options,
            'status' => 'queued',
        ]);

        app(ImportPipelineService::class)->run($session->fresh());

        $session = \App\Models\ImportSession::query()->findOrFail($fileId);
        $this->assertEquals('completed', $session->status, $session->error_message ?? '');

        $estimateId = $session->stats['estimate_id'] ?? null;
        $this->assertNotNull($estimateId);
        
        $estimate = Estimate::with(['sections', 'items'])->find($estimateId);
        
        echo "Импорт завершен успешно!\n";
        echo "Создано разделов: " . $estimate->sections->count() . "\n";
        echo "Создано позиций: " . $estimate->items->count() . "\n";

        // Проверка иерархии
        $sectionsCount = $estimate->sections->count();
        $this->assertGreaterThan(0, $sectionsCount, "Разделы не были созданы. Иерархия плоская.");

        // Вывод структуры для анализа
        echo "\nСтруктура разделов:\n";
        foreach ($estimate->sections as $section) {
            $itemsCount = EstimateItem::where('estimate_section_id', $section->id)->count();
            echo "- {$section->name} (Позиций: {$itemsCount})\n";
        }

        echo "\nПроверка завершена.\n";
    }
}
