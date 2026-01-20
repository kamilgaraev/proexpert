<?php

namespace App\BusinessModules\Addons\AIEstimates\Services\YandexGPT;

use App\BusinessModules\Addons\AIEstimates\DTOs\AIEstimateRequestDTO;

class PromptBuilder
{
    public function buildSystemPrompt(): string
    {
        return <<<PROMPT
Ты - эксперт по строительным сметам РФ с глубокими знаниями ГЭСН, ФЕР и строительных нормативов.

Твоя задача: генерировать структуру строительной сметы на основе описания проекта.

ФОРМАТ ОТВЕТА: Возвращай ТОЛЬКО валидный JSON без дополнительного текста в следующем формате:

{
  "sections": [
    {
      "name": "Название раздела (например: Земляные работы, Фундамент, Стены и перегородки)",
      "order": 1,
      "items": [
        {
          "work_type": "Тип работы согласно ГЭСН",
          "description": "Подробное описание работы",
          "unit": "ед. изм. (м², м³, м.п., т, шт)",
          "quantity": число,
          "confidence": число от 0.0 до 1.0
        }
      ]
    }
  ]
}

ПРАВИЛА:
1. Используй только официальную терминологию ГЭСН и ФЕР
2. Указывай правильные единицы измерения согласно ГОСТ и СНиП
3. Разбивай смету на логические разделы (Земляные работы, Фундамент, Стены, Кровля, Отделка и т.д.)
4. Указывай реалистичные объемы работ
5. Confidence должен отражать уверенность в расчете (0.0-1.0)
6. Не добавляй никакого текста кроме JSON
7. Учитывай региональную специфику строительства
8. Включай все основные виды работ для полноценного строительства

ВАЖНО: Ответ должен быть только JSON, без пояснений и комментариев!
PROMPT;
    }

    public function buildUserPrompt(AIEstimateRequestDTO $request, array $context = []): string
    {
        $prompt = "Составь подробную строительную смету для следующего проекта:\n\n";
        
        // Основное описание
        $prompt .= "ОПИСАНИЕ ПРОЕКТА:\n";
        $prompt .= $request->description . "\n\n";

        // Параметры проекта
        if ($request->area || $request->buildingType || $request->region) {
            $prompt .= "ПАРАМЕТРЫ:\n";
            
            if ($request->area) {
                $prompt .= "- Общая площадь: {$request->area} м²\n";
            }
            
            if ($request->buildingType) {
                $prompt .= "- Тип здания: {$request->buildingType}\n";
            }
            
            if ($request->region) {
                $prompt .= "- Регион строительства: {$request->region}\n";
            }
            
            $prompt .= "\n";
        }

        // Данные из распознанных файлов
        if (!empty($context['ocr_data'])) {
            $prompt .= "ДАННЫЕ ИЗ ЗАГРУЖЕННЫХ ДОКУМЕНТОВ:\n";
            foreach ($context['ocr_data'] as $fileData) {
                $prompt .= "Файл: {$fileData['filename']}\n";
                if (!empty($fileData['structured_data'])) {
                    $prompt .= "Извлеченные данные:\n";
                    if (!empty($fileData['structured_data']['areas'])) {
                        $prompt .= "- Площади: " . implode(', ', $fileData['structured_data']['areas']) . " м²\n";
                    }
                    if (!empty($fileData['structured_data']['dimensions'])) {
                        $prompt .= "- Размеры найдены в документе\n";
                    }
                }
                $prompt .= "\n";
            }
        }

        // Анализ похожих проектов
        if (!empty($context['similar_projects'])) {
            $prompt .= "АНАЛИЗ ПОХОЖИХ ПРОЕКТОВ ОРГАНИЗАЦИИ:\n";
            $prompt .= "Для уточнения объемов работ проанализированы {$context['similar_projects']['count']} похожих проектов:\n";
            
            if (!empty($context['similar_projects']['average_sections'])) {
                $prompt .= "Типичные разделы смет:\n";
                foreach ($context['similar_projects']['average_sections'] as $section) {
                    $prompt .= "- {$section}\n";
                }
            }
            
            if (!empty($context['similar_projects']['typical_volumes'])) {
                $prompt .= "\nТипичные объемы для площади {$request->area} м²:\n";
                $prompt .= json_encode($context['similar_projects']['typical_volumes'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            }
            
            $prompt .= "\n";
        }

        // Указания по расчету
        $prompt .= "ТРЕБОВАНИЯ К СМЕТЕ:\n";
        $prompt .= "1. Составь смету для всех этапов строительства от земляных работ до чистовой отделки\n";
        $prompt .= "2. Рассчитай реалистичные объемы работ на основе указанной площади\n";
        $prompt .= "3. Используй корректные единицы измерения\n";
        $prompt .= "4. Укажи confidence (уверенность) для каждой позиции\n";
        $prompt .= "5. Группируй работы по логическим разделам\n";
        $prompt .= "6. Вернв ТОЛЬКО JSON без дополнительного текста\n";

        return $prompt;
    }

    public function buildContextFromOCR(array $ocrResults): array
    {
        $context = ['ocr_data' => []];

        foreach ($ocrResults as $fileData) {
            $context['ocr_data'][] = [
                'filename' => $fileData['filename'] ?? 'unknown',
                'text' => $fileData['text'] ?? '',
                'structured_data' => $fileData['structured_data'] ?? [],
            ];
        }

        return $context;
    }

    public function buildContextFromHistory(array $similarProjects): array
    {
        return ['similar_projects' => $similarProjects];
    }
}
