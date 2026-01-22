<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategy;

use App\BusinessModules\Addons\AIEstimates\Services\YandexGPT\YandexGPTClient;
use Illuminate\Support\Facades\Log;

class AIRowClassifierService
{
    private YandexGPTClient $aiService;

    public function __construct()
    {
        $this->aiService = app(YandexGPTClient::class);
    }

    /**
     * Классифицировать пакет строк
     * 
     * @param array $rowsNames Массив ['row_id' => 'Текст ячейки']
     * @return array Массив ['row_id' => 'TYPE'] (SECTION, ITEM, SUMMARY, IGNORE)
     */
    public function classifyBatch(array $rowsNames): array
    {
        if (empty($rowsNames)) {
            return [];
        }

        // Подготовка данных для промпта (нумеруем, чтобы AI не сбился)
        $batchText = "";
        foreach ($rowsNames as $id => $name) {
            // Очищаем от лишних пробелов и сокращаем длину для экономии
            $cleanName = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 100);
            $batchText .= "ID{$id}: {$cleanName}\n";
        }

        $prompt = <<<EOT
Ты - анализатор структуры строительной сметы. Твоя задача - определить тип каждой строки.

Типы:
- SECTION: Заголовок раздела/главы (Примеры: "Раздел 1", "Глава 2", "Земляные работы")
- ITEM: Сметная позиция, работа, материал, ресурс (Примеры: "Разработка грунта", "Бетон М200", "Кирпич")
- SUMMARY: Итоги, накрутки, налоги, справочная инфо (Примеры: "Итого", "Всего", "Накладные", "ФОТ", "В т.ч. зарплата", "Материалы", "Машины")
- IGNORE: Мусор, номера страниц, пустые строки, заголовки колонок (Примеры: "Наименование", "1", "Лист 5")

Важно: "Материалы", "Машины", "ФОТ" внутри раздела - это SUMMARY (или IGNORE), но НЕ SECTION и НЕ ITEM.

Список строк:
{$batchText}

Верни JSON объект, где ключ - ID строки, значение - ТИП.
Пример: {"ID1": "SECTION", "ID2": "ITEM", "ID3": "SUMMARY"}
EOT;

        try {
            $response = $this->aiService->generateEstimate(
                userPrompt: $prompt,
                systemPrompt: 'Ты возвращаешь только валидный JSON.',
                options: [
                    'temperature' => 0.1,
                    'max_tokens' => 2000 // Достаточно для батча
                ]
            );

            $content = $response['content'] ?? '{}';
            
            // Очистка от markdown ```json ... ```
            $content = preg_replace('/^```json|```$/m', '', $content);
            $result = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('[AIRowClassifier] Invalid JSON from AI', ['error' => json_last_error_msg(), 'content' => $content]);
                return [];
            }

            // Нормализация ключей (убираем ID префикс)
            $normalized = [];
            foreach ($result as $key => $type) {
                $originalId = str_replace('ID', '', $key);
                $normalized[$originalId] = strtoupper($type);
            }

            return $normalized;

        } catch (\Throwable $e) {
            Log::error('[AIRowClassifier] Batch classification failed: ' . $e->getMessage());
            return [];
        }
    }
}
