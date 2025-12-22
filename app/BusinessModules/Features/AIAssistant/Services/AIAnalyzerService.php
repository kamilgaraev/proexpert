<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\Services\Logging\LoggingService;

class AIAnalyzerService
{
    protected LLMProviderInterface $llmProvider;
    protected LoggingService $logging;

    public function __construct(
        LLMProviderInterface $llmProvider,
        LoggingService $logging
    ) {
        $this->llmProvider = $llmProvider;
        $this->logging = $logging;
    }

    /**
     * Анализ бюджета
     */
    public function analyzeBudget(array $data): array
    {
        $prompt = $this->buildBudgetPrompt($data);
        
        $messages = [
            ['role' => 'system', 'content' => 'Ты опытный проектный менеджер строительных проектов с большим опытом финансового анализа.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $response = $this->llmProvider->chat($messages, ['temperature' => 0.3]);
            return $this->parseBudgetResponse($response['content']);
        } catch (\Exception $e) {
            $this->logging->technical('ai.analysis.budget.error', ['error' => $e->getMessage()], 'error');
            return $this->getErrorResponse('budget');
        }
    }

    /**
     * Анализ графика
     */
    public function analyzeSchedule(array $data): array
    {
        $prompt = $this->buildSchedulePrompt($data);
        
        $messages = [
            ['role' => 'system', 'content' => 'Ты опытный руководитель проектов со знанием методологий управления временем и критическим путем.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $response = $this->llmProvider->chat($messages, ['temperature' => 0.3]);
            return $this->parseScheduleResponse($response['content']);
        } catch (\Exception $e) {
            $this->logging->technical('ai.analysis.schedule.error', ['error' => $e->getMessage()], 'error');
            return $this->getErrorResponse('schedule');
        }
    }

    /**
     * Анализ материалов
     */
    public function analyzeMaterials(array $data): array
    {
        $prompt = $this->buildMaterialsPrompt($data);
        
        $messages = [
            ['role' => 'system', 'content' => 'Ты опытный снабженец на строительстве с глубоким знанием логистики и управления запасами.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $response = $this->llmProvider->chat($messages, ['temperature' => 0.3]);
            return $this->parseMaterialsResponse($response['content']);
        } catch (\Exception $e) {
            $this->logging->technical('ai.analysis.materials.error', ['error' => $e->getMessage()], 'error');
            return $this->getErrorResponse('materials');
        }
    }

    /**
     * Анализ рабочих и бригад
     */
    public function analyzeWorkers(array $data): array
    {
        $prompt = $this->buildWorkersPrompt($data);
        
        $messages = [
            ['role' => 'system', 'content' => 'Ты опытный HR-менеджер в строительстве со знанием управления персоналом и производительностью труда.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $response = $this->llmProvider->chat($messages, ['temperature' => 0.3]);
            return $this->parseWorkersResponse($response['content']);
        } catch (\Exception $e) {
            $this->logging->technical('ai.analysis.workers.error', ['error' => $e->getMessage()], 'error');
            return $this->getErrorResponse('workers');
        }
    }

    /**
     * Анализ контрактов
     */
    public function analyzeContracts(array $data): array
    {
        $prompt = $this->buildContractsPrompt($data);
        
        $messages = [
            ['role' => 'system', 'content' => 'Ты юрист и специалист по управлению контрактами в строительстве.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $response = $this->llmProvider->chat($messages, ['temperature' => 0.3]);
            return $this->parseContractsResponse($response['content']);
        } catch (\Exception $e) {
            $this->logging->technical('ai.analysis.contracts.error', ['error' => $e->getMessage()], 'error');
            return $this->getErrorResponse('contracts');
        }
    }

    /**
     * Анализ рисков
     */
    public function analyzeRisks(array $allData): array
    {
        $prompt = $this->buildRisksPrompt($allData);
        
        $messages = [
            ['role' => 'system', 'content' => 'Ты risk-менеджер со специализацией в строительных проектах.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $response = $this->llmProvider->chat($messages, ['temperature' => 0.4]);
            return $this->parseRisksResponse($response['content']);
        } catch (\Exception $e) {
            $this->logging->technical('ai.analysis.risks.error', ['error' => $e->getMessage()], 'error');
            return $this->getErrorResponse('risks');
        }
    }

    /**
     * Анализ эффективности (KPI)
     */
    public function analyzePerformance(array $kpiData): array
    {
        $prompt = $this->buildPerformancePrompt($kpiData);
        
        $messages = [
            ['role' => 'system', 'content' => 'Ты аналитик с опытом оценки эффективности строительных проектов.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $response = $this->llmProvider->chat($messages, ['temperature' => 0.3]);
            return $this->parsePerformanceResponse($response['content']);
        } catch (\Exception $e) {
            $this->logging->technical('ai.analysis.performance.error', ['error' => $e->getMessage()], 'error');
            return $this->getErrorResponse('performance');
        }
    }

    /**
     * Генерация общих рекомендаций
     */
    public function generateRecommendations(array $allData, array $allAnalyses): array
    {
        $prompt = $this->buildRecommendationsPrompt($allData, $allAnalyses);
        
        $messages = [
            ['role' => 'system', 'content' => 'Ты опытный консультант по оптимизации строительных проектов.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $response = $this->llmProvider->chat($messages, ['temperature' => 0.5]);
            return $this->parseRecommendationsResponse($response['content']);
        } catch (\Exception $e) {
            $this->logging->technical('ai.analysis.recommendations.error', ['error' => $e->getMessage()], 'error');
            return $this->getErrorResponse('recommendations');
        }
    }

    /**
     * Построить промпт для анализа бюджета
     */
    private function buildBudgetPrompt(array $data): string
    {
        $project = $data['project_name'] ?? 'Неизвестный проект';
        $planned = number_format($data['planned_budget'] ?? 0, 2, '.', ' ');
        $spent = number_format($data['spent_amount'] ?? 0, 2, '.', ' ');
        $percentage = $data['percentage_spent'] ?? 0;
        $remainingDays = $data['time_data']['remaining_days'] ?? 0;
        $completion = $data['completion_percentage'] ?? 0;
        
        $contractsInfo = '';
        if (isset($data['contracts']['contracts'])) {
            foreach ($data['contracts']['contracts'] as $contract) {
                $contractsInfo .= sprintf(
                    "- Контракт №%s (%s): %s руб., оплачено: %s руб.\n",
                    $contract['number'],
                    $contract['contractor'],
                    number_format($contract['total_amount'], 2, '.', ' '),
                    number_format($contract['paid'], 2, '.', ' ')
                );
            }
        }

        return <<<PROMPT
Проанализируй финансовое состояние строительного проекта и дай рекомендации.

ПРОЕКТ: "{$project}"
ПЛАНОВЫЙ БЮДЖЕТ: {$planned} руб.
ПОТРАЧЕНО: {$spent} руб. ({$percentage}%)
ДНЕЙ ДО ЗАВЕРШЕНИЯ: {$remainingDays}
ПРОЦЕНТ ВЫПОЛНЕНИЯ РАБОТ: {$completion}%

КОНТРАКТЫ:
{$contractsInfo}

ЗАДАЧИ:
1. Оцени вероятность перерасхода бюджета (0-100%)
2. Спрогнозируй итоговую стоимость проекта
3. Укажи проблемные зоны
4. Дай 3-5 конкретных рекомендаций по оптимизации бюджета

ВАЖНО: Ответь строго в формате JSON:
{
  "score": 0-100,
  "status": "good/warning/critical",
  "overrun_probability": 0-100,
  "projected_total_cost": число,
  "summary": "краткое резюме",
  "analysis": "детальный анализ",
  "problem_areas": [{"area": "...", "issue": "...", "impact": "..."}],
  "recommendations": [{"priority": "high/medium/low", "action": "...", "impact": "...", "estimated_cost": число}]
}
PROMPT;
    }

    /**
     * Построить промпт для анализа графика
     */
    private function buildSchedulePrompt(array $data): string
    {
        $project = $data['project_name'] ?? 'Неизвестный проект';
        $totalTasks = $data['tasks_summary']['total'] ?? 0;
        $completed = $data['tasks_summary']['completed'] ?? 0;
        $overdue = $data['tasks_summary']['overdue'] ?? 0;
        $completion = $data['tasks_summary']['completion_percentage'] ?? 0;
        $remainingDays = $data['project_dates']['remaining_days'] ?? 0;

        $overdueInfo = '';
        if (isset($data['overdue_tasks'])) {
            foreach ($data['overdue_tasks'] as $task) {
                $overdueInfo .= sprintf(
                    "- %s (просрочена на %d дней)\n",
                    $task['name'],
                    $task['days_overdue']
                );
            }
        }

        return <<<PROMPT
Проанализируй выполнение графика работ проекта.

ПРОЕКТ: "{$project}"
ВСЕГО ЗАДАЧ: {$totalTasks}
ВЫПОЛНЕНО: {$completed}
ПРОСРОЧЕНО: {$overdue}
ПРОЦЕНТ ВЫПОЛНЕНИЯ: {$completion}%
ДНЕЙ ДО ЗАВЕРШЕНИЯ: {$remainingDays}

ПРОСРОЧЕННЫЕ ЗАДАЧИ:
{$overdueInfo}

ЗАДАЧИ:
1. Оцени вероятность срыва сроков (0-100%)
2. Определи критические задачи
3. Спрогнозируй новую дату завершения если есть отставание
4. Дай 3-5 рекомендаций по ускорению работ

JSON формат:
{
  "score": 0-100,
  "status": "good/warning/critical",
  "delay_probability": 0-100,
  "projected_end_date": "YYYY-MM-DD или null",
  "summary": "...",
  "analysis": "...",
  "critical_tasks": ["задача1", "задача2"],
  "recommendations": [{"priority": "...", "action": "...", "impact": "...", "time_saved_days": число}]
}
PROMPT;
    }

    /**
     * Построить промпт для анализа материалов
     */
    private function buildMaterialsPrompt(array $data): string
    {
        $project = $data['project_name'] ?? 'Неизвестный проект';
        $daysOfSupply = $data['days_of_supply'] ?? 0;
        $deficitCount = $data['deficit_analysis']['deficit_count'] ?? 0;
        $totalCost = number_format($data['deficit_analysis']['total_cost'] ?? 0, 2, '.', ' ');

        $deficitInfo = '';
        if (isset($data['deficit_analysis']['deficit_items'])) {
            foreach ($data['deficit_analysis']['deficit_items'] as $item) {
                $deficitInfo .= sprintf(
                    "- %s: нужно %s %s, в наличии %s, дефицит %s\n",
                    $item['material'],
                    number_format($item['required'], 2, '.', ''),
                    $item['unit'],
                    number_format($item['available'], 2, '.', ''),
                    number_format($item['deficit'], 2, '.', '')
                );
            }
        }

        return <<<PROMPT
Проанализируй обеспеченность проекта материалами.

ПРОЕКТ: "{$project}"
МАТЕРИАЛОВ ХВАТИТ НА: {$daysOfSupply} дней
ДЕФИЦИТ ПОЗИЦИЙ: {$deficitCount}
СТОИМОСТЬ ЗАКУПОК: {$totalCost} руб.

ДЕФИЦИТ:
{$deficitInfo}

ЗАДАЧИ:
1. Оцени критичность ситуации с материалами
2. Определи приоритетные закупки
3. Рассчитай оптимальные сроки закупок
4. Дай 3-5 рекомендаций по оптимизации снабжения

JSON:
{
  "score": 0-100,
  "status": "good/warning/critical",
  "supply_adequacy": "...",
  "summary": "...",
  "analysis": "...",
  "priority_purchases": [{"material": "...", "quantity": число, "deadline": "YYYY-MM-DD", "reason": "..."}],
  "recommendations": [{"priority": "...", "action": "...", "impact": "...", "cost": число}]
}
PROMPT;
    }

    /**
     * Построить промпт для анализа рабочих
     */
    private function buildWorkersPrompt(array $data): string
    {
        $project = $data['project_name'] ?? 'Неизвестный проект';
        $totalWorks = $data['total_completed_works'] ?? 0;
        $valuePerDay = number_format($data['productivity']['value_per_day'] ?? 0, 2, '.', ' ');
        $trend = $data['productivity']['productivity_trend'] ?? 'stable';
        $brigadesCount = $data['brigade_analysis']['total_brigades'] ?? 0;

        return <<<PROMPT
Проанализируй эффективность работы бригад на проекте.

ПРОЕКТ: "{$project}"
ВЫПОЛНЕНО РАБОТ: {$totalWorks}
БРИГАД: {$brigadesCount}
ПРОИЗВОДИТЕЛЬНОСТЬ: {$valuePerDay} руб/день
ТРЕНД: {$trend}

ЗАДАЧИ:
1. Оцени эффективность использования рабочей силы
2. Определи потребность в дополнительных бригадах
3. Выяви проблемные зоны
4. Дай 3-5 рекомендаций по оптимизации

JSON:
{
  "score": 0-100,
  "status": "good/warning/critical",
  "efficiency_rating": "...",
  "summary": "...",
  "analysis": "...",
  "additional_workers_needed": {"brigades": число, "specialization": "...", "justification": "..."},
  "recommendations": [{"priority": "...", "action": "...", "impact": "...", "cost": число}]
}
PROMPT;
    }

    /**
     * Построить промпт для анализа контрактов
     */
    private function buildContractsPrompt(array $data): string
    {
        $project = $data['project_name'] ?? 'Неизвестный проект';
        $totalContracts = $data['summary']['total_contracts'] ?? 0;
        $problemContracts = $data['problem_contracts_count'] ?? 0;
        $completion = $data['summary']['overall_completion'] ?? 0;

        $problemInfo = '';
        if (isset($data['problem_contracts'])) {
            foreach ($data['problem_contracts'] as $contract) {
                $problemInfo .= sprintf(
                    "- Контракт №%s (%s): %s\n",
                    $contract['number'],
                    $contract['contractor'],
                    implode(', ', array_column($contract['problems'] ?? [], 'description'))
                );
            }
        }

        return <<<PROMPT
Проанализируй состояние контрактов проекта.

ПРОЕКТ: "{$project}"
ВСЕГО КОНТРАКТОВ: {$totalContracts}
ПРОБЛЕМНЫХ: {$problemContracts}
ОБЩЕЕ ВЫПОЛНЕНИЕ: {$completion}%

ПРОБЛЕМНЫЕ КОНТРАКТЫ:
{$problemInfo}

ЗАДАЧИ:
1. Оцени риски связанные с контрактами
2. Определи приоритетные действия
3. Выяви потенциальные юридические проблемы
4. Дай 3-5 рекомендаций

JSON:
{
  "score": 0-100,
  "status": "good/warning/critical",
  "summary": "...",
  "analysis": "...",
  "legal_risks": [{"contract": "...", "risk": "...", "severity": "high/medium/low"}],
  "recommendations": [{"priority": "...", "action": "...", "impact": "..."}]
}
PROMPT;
    }

    /**
     * Построить промпт для анализа рисков
     */
    private function buildRisksPrompt(array $allData): string
    {
        $budgetHealth = $allData['budget']['budget_health'] ?? 'unknown';
        $scheduleHealth = $allData['schedule']['schedule_health'] ?? 'unknown';
        $materialsHealth = $allData['materials']['materials_health'] ?? 'unknown';
        $workersHealth = $allData['workers']['workers_health'] ?? 'unknown';
        $contractsHealth = $allData['contracts']['contracts_health'] ?? 'unknown';

        return <<<PROMPT
Проведи комплексную оценку рисков проекта на основе всех данных.

СОСТОЯНИЕ РАЗДЕЛОВ:
- Бюджет: {$budgetHealth}
- График: {$scheduleHealth}
- Материалы: {$materialsHealth}
- Рабочие: {$workersHealth}
- Контракты: {$contractsHealth}

ЗАДАЧИ:
1. Определи ТОП-5 критических рисков
2. Оцени вероятность и влияние каждого риска
3. Предложи меры по митигации
4. Дай общую оценку рискованности проекта

JSON:
{
  "score": 0-100,
  "status": "good/warning/critical",
  "summary": "...",
  "analysis": "...",
  "top_risks": [{"risk": "...", "probability": 0-100, "impact": "high/medium/low", "mitigation": "..."}],
  "overall_risk_level": "low/medium/high/critical",
  "recommendations": [{"priority": "...", "action": "...", "impact": "..."}]
}
PROMPT;
    }

    /**
     * Построить промпт для анализа эффективности
     */
    private function buildPerformancePrompt(array $kpiData): string
    {
        $cpi = $kpiData['cpi']['value'] ?? 1.0;
        $spi = $kpiData['spi']['value'] ?? 1.0;
        $healthScore = $kpiData['project_health_index']['score'] ?? 50;

        return <<<PROMPT
Проанализируй показатели эффективности проекта.

ПОКАЗАТЕЛИ:
- CPI (индекс выполнения бюджета): {$cpi}
- SPI (индекс выполнения графика): {$spi}
- Общее здоровье проекта: {$healthScore}/100

ЗАДАЧИ:
1. Интерпретируй показатели эффективности
2. Сравни с эталонными значениями
3. Определи области для улучшения
4. Дай 3-5 рекомендаций по повышению эффективности

JSON:
{
  "score": 0-100,
  "status": "good/warning/critical",
  "summary": "...",
  "analysis": "...",
  "benchmark_comparison": "...",
  "improvement_areas": ["область1", "область2"],
  "recommendations": [{"priority": "...", "action": "...", "expected_improvement": "..."}]
}
PROMPT;
    }

    /**
     * Построить промпт для общих рекомендаций
     */
    private function buildRecommendationsPrompt(array $allData, array $allAnalyses): string
    {
        // Собираем все критические проблемы
        $criticalIssues = [];
        foreach ($allAnalyses as $section => $analysis) {
            if (isset($analysis['status']) && $analysis['status'] === 'critical') {
                $criticalIssues[] = "$section: " . ($analysis['summary'] ?? 'Критическая проблема');
            }
        }

        $issuesText = implode("\n", $criticalIssues);

        return <<<PROMPT
На основе комплексного анализа проекта сформируй итоговые рекомендации.

КРИТИЧЕСКИЕ ПРОБЛЕМЫ:
{$issuesText}

ЗАДАЧИ:
1. Определи ТОП-3 приоритетных действия (немедленные)
2. Предложи 5-7 среднесрочных улучшений
3. Дай 3-5 долгосрочных стратегических рекомендаций
4. Укажи потенциальную экономию/ускорение

JSON:
{
  "summary": "общее резюме",
  "critical_actions": [{"action": "...", "deadline": "...", "impact": "...", "cost": число}],
  "optimization_opportunities": [{"area": "...", "action": "...", "benefit": "...", "effort": "low/medium/high"}],
  "long_term_improvements": [{"recommendation": "...", "benefit": "...", "timeframe": "..."}],
  "estimated_savings": {"money": число, "time_days": число}
}
PROMPT;
    }

    /**
     * Парсинг ответа AI для бюджета
     */
    private function parseBudgetResponse(string $content): array
    {
        return $this->parseJsonResponse($content, 'budget');
    }

    /**
     * Парсинг ответа AI для графика
     */
    private function parseScheduleResponse(string $content): array
    {
        return $this->parseJsonResponse($content, 'schedule');
    }

    /**
     * Парсинг ответа AI для материалов
     */
    private function parseMaterialsResponse(string $content): array
    {
        return $this->parseJsonResponse($content, 'materials');
    }

    /**
     * Парсинг ответа AI для рабочих
     */
    private function parseWorkersResponse(string $content): array
    {
        return $this->parseJsonResponse($content, 'workers');
    }

    /**
     * Парсинг ответа AI для контрактов
     */
    private function parseContractsResponse(string $content): array
    {
        return $this->parseJsonResponse($content, 'contracts');
    }

    /**
     * Парсинг ответа AI для рисков
     */
    private function parseRisksResponse(string $content): array
    {
        return $this->parseJsonResponse($content, 'risks');
    }

    /**
     * Парсинг ответа AI для эффективности
     */
    private function parsePerformanceResponse(string $content): array
    {
        return $this->parseJsonResponse($content, 'performance');
    }

    /**
     * Парсинг ответа AI для рекомендаций
     */
    private function parseRecommendationsResponse(string $content): array
    {
        return $this->parseJsonResponse($content, 'recommendations');
    }

    /**
     * Общий метод парсинга JSON из ответа AI
     */
    private function parseJsonResponse(string $content, string $section): array
    {
        // Пытаемся извлечь JSON из ответа
        $content = trim($content);
        
        // Убираем markdown форматирование если есть
        $content = preg_replace('/^```json\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        
        // Ищем JSON в тексте
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $json = $matches[0];
            $decoded = json_decode($json, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Если не удалось распарсить, возвращаем структурированный ответ
        $this->logging->technical('ai.analysis.parse_error', [
            'section' => $section,
            'content' => substr($content, 0, 500),
        ], 'warning');

        return [
            'score' => 50,
            'status' => 'unknown',
            'summary' => 'Не удалось обработать ответ AI',
            'analysis' => $content,
            'recommendations' => [],
        ];
    }

    /**
     * Получить ответ при ошибке
     */
    private function getErrorResponse(string $section): array
    {
        return [
            'score' => 0,
            'status' => 'unknown',
            'summary' => 'Ошибка при анализе раздела',
            'analysis' => 'Не удалось выполнить анализ из-за технической ошибки.',
            'recommendations' => [],
            'error' => true,
        ];
    }
}

