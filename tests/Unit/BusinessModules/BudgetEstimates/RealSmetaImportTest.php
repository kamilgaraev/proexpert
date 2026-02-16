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
        $filePath = 'c:\Users\kamilgaraev\Desktop\prohelper\smeta-stroitelstvo-skladskogo-zdaniya.xlsx';
        
        if (!file_exists($filePath)) {
            $this->markTestSkipped("Файл не найден по пути: {$filePath}");
        }

        // 1. Подготовка контекста (замените ID на реальные если нужно, или тест создаст временные)
        $user = User::first() ?: User::factory()->create();
        $organization = Organization::first() ?: Organization::factory()->create();
        
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
            'type' => 'estimate',
            'organization_id' => $organization->id,
            'project_id' => null,
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
