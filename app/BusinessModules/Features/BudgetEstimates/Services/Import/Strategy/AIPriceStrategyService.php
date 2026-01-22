<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategy;

use App\BusinessModules\Features\BudgetEstimates\Enums\PriceStrategyEnum;
use App\BusinessModules\Addons\AIEstimates\Services\YandexGPT\YandexGPTClient;
use Illuminate\Support\Facades\Log;

class AIPriceStrategyService
{
    private YandexGPTClient $aiService;

    public function __construct()
    {
        // Предполагаем, что сервис доступен через контейнер или создаем его
        // В реальном проекте лучше инжектить через конструктор, но здесь делаем fallback
        $this->aiService = app(YandexGPTClient::class);
    }

    /**
     * Определить стратегию выбора цены из ячейки
     * 
     * @param array $samples Примеры ячеек с ценами (строки с \n)
     * @param array $headers Заголовки таблицы (для контекста)
     * @return string Одна из констант PriceStrategyEnum
     */
    public function detectStrategy(array $samples, array $headers = []): string
    {
        if (empty($samples)) {
            return PriceStrategyEnum::DEFAULT;
        }

        try {
            $prompt = $this->buildPrompt($samples, $headers);
            
            Log::info('[AIPriceStrategy] Sending request to AI', ['samples_count' => count($samples)]);
            
            // Используем YandexGPT (клиент из AIEstimates)
            // Метод generateEstimate ожидает userPrompt и systemPrompt
            // Мы передадим пустой systemPrompt и весь контекст в userPrompt
            $response = $this->aiService->generateEstimate(
                userPrompt: $prompt, 
                systemPrompt: 'Ты - эксперт по анализу сметных данных.',
                options: [
                    'temperature' => 0.1,
                    'max_tokens' => 50
                ]
            );
            
            // Клиент возвращает массив ['content' => ..., 'tokens_used' => ...]
            $content = $response['content'] ?? null;
            $strategy = $this->parseResponse($content);
            
            Log::info('[AIPriceStrategy] Strategy detected', ['strategy' => $strategy, 'raw_response' => $content]);
            
            return $strategy;
            
        } catch (\Throwable $e) {
            Log::error('[AIPriceStrategy] Failed to detect strategy: ' . $e->getMessage());
            // Fallback: для смет чаще всего актуальная цена - максимальная (текущая > базисная)
            return PriceStrategyEnum::MAX; 
        }
    }

    private function buildPrompt(array $samples, array $headers): string
    {
        $samplesStr = implode("\n---\n", $samples);
        $headersStr = implode(", ", $headers);

        return <<<EOT
Ты - эксперт по сметному делу. Твоя задача - понять формат записи цен в Excel-файле.
В сметных программах (Гранд-Смета и др.) в одной ячейке часто пишут две цены:
1. Базисную (в ценах 2001 года, маленькая).
2. Текущую (реальную рыночную, большая).

Иногда порядок обратный. Иногда записана формула.

Вот примеры ячеек из колонки "Стоимость":
{$samplesStr}

Контекст (заголовки колонок): {$headersStr}

ЗАДАЧА: Определи, какое число является ТЕКУЩЕЙ (АКТУАЛЬНОЙ) сметной стоимостью, которую нужно брать в расчет?
Верни только одно слово:
- "TOP" (если актуальная цена сверху/первая)
- "BOTTOM" (если актуальная цена снизу/последняя)
- "MAX" (если актуальная цена всегда больше базисной)

Ответ (одно слово):
EOT;
    }

    private function parseResponse(?string $response): string
    {
        if (empty($response)) {
            return PriceStrategyEnum::MAX;
        }

        $cleaned = mb_strtoupper(trim($response));
        
        if (str_contains($cleaned, 'TOP')) return PriceStrategyEnum::TOP;
        if (str_contains($cleaned, 'BOTTOM')) return PriceStrategyEnum::BOTTOM;
        if (str_contains($cleaned, 'MAX')) return PriceStrategyEnum::MAX;
        
        return PriceStrategyEnum::MAX; // Default fallback
    }
}
