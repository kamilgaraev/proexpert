<?php

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RealSmetaImportTest extends TestCase
{
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
        $user = User::first() ?: User::factory()->create();
        $organization = Organization::first() ?: Organization::factory()->create();
        
        // Смете ТРЕБУЕТСЯ проект по ограничениям БД
        $project = \App\Models\Project::first() ?: \App\Models\Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Тестовый проект для импорта'
        ]);
        
        /** @var EstimateImportService $importService */
        $importService = app(EstimateImportService::class);

        // 2. Имитация загрузки файла
        $uploadedFile = new UploadedFile($filePath, basename($filePath), null, null, true);
        $fileId = $importService->uploadFile($uploadedFile, $user->id, $organization->id);

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
        
        $result = $importService->execute($fileId, $mapping, $settings);

        $this->assertEquals('completed', $result['status']);
        $estimateId = $result['estimate_id'];
        
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
