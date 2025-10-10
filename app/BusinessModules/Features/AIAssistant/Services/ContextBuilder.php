<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Cache;

class ContextBuilder
{
    protected IntentRecognizer $intentRecognizer;
    protected LoggingService $logging;

    public function __construct(
        IntentRecognizer $intentRecognizer,
        LoggingService $logging
    ) {
        $this->intentRecognizer = $intentRecognizer;
        $this->logging = $logging;
    }

    public function buildContext(string $query, int $organizationId, ?int $userId = null, ?string $previousIntent = null, ?array $conversationContext = []): array
    {
        // Распознаем намерение с учетом предыдущего контекста
        $intent = $this->intentRecognizer->recognize($query, $previousIntent);
        
        $this->logging->technical('ai.intent.recognized', [
            'intent' => $intent,
            'previous_intent' => $previousIntent,
            'query' => $query,
            'organization_id' => $organizationId,
        ]);
        
        $context = [
            'intent' => $intent,  // Сохраняем распознанный intent
            'organization' => $this->getOrganizationContext($organizationId),
        ];

        // Выполнение Actions на основе распознанного намерения
        $actionResult = $this->executeAction($intent, $organizationId, $query, $userId, $conversationContext);
        
        if ($actionResult) {
            $context[$intent] = $actionResult;
        }

        return $context;
    }

    protected function executeAction(string $intent, int $organizationId, string $query, ?int $userId = null, array $conversationContext = []): ?array
    {
        $actionClass = $this->getActionClass($intent);
        
        if (!$actionClass || !class_exists($actionClass)) {
            return null;
        }

        try {
            $action = app($actionClass);
            
            // Извлекаем параметры из запроса при необходимости
            $params = $this->extractParams($intent, $query, $conversationContext);
            
            // Добавляем user_id в параметры для Actions, которым это нужно
            if ($userId) {
                $params['user_id'] = $userId;
            }
            
            $result = $action->execute($organizationId, $params);
            
            $this->logging->technical('ai.action.executed', [
                'action' => $actionClass,
                'intent' => $intent,
                'organization_id' => $organizationId,
                'params' => $params,
                'result_keys' => $result ? array_keys($result) : [],
                'has_data' => !empty($result),
                'success' => true,
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logging->technical('ai.action.error', [
                'action' => $actionClass,
                'intent' => $intent,
                'error' => $e->getMessage(),
            ], 'error');
            
            return null;
        }
    }

    protected function getActionClass(string $intent): ?string
    {
        $actionMap = [
            // Проекты
            'project_details' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectDetailsAction::class,
            'project_search' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\SearchProjectsAction::class,
            'project_status' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectStatusAction::class,
            'project_budget' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectBudgetAction::class,
            'project_risks' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\AnalyzeProjectRisksAction::class,
            
            // Контракты
            'contract_search' => \App\BusinessModules\Features\AIAssistant\Actions\Contracts\SearchContractsAction::class,
            'contract_details' => \App\BusinessModules\Features\AIAssistant\Actions\Contracts\GetContractDetailsAction::class,
            
            // Материалы
            'material_stock' => \App\BusinessModules\Features\AIAssistant\Actions\Materials\CheckMaterialStockAction::class,
            'material_forecast' => \App\BusinessModules\Features\AIAssistant\Actions\Materials\ForecastMaterialNeedsAction::class,
            
            // Системная информация
            'user_info' => \App\BusinessModules\Features\AIAssistant\Actions\System\GetUserInfoAction::class,
            'team_info' => \App\BusinessModules\Features\AIAssistant\Actions\System\GetTeamInfoAction::class,
            'organization_info' => \App\BusinessModules\Features\AIAssistant\Actions\System\GetOrganizationInfoAction::class,
            'help' => \App\BusinessModules\Features\AIAssistant\Actions\System\GetHelpAction::class,
            'greeting' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectStatusAction::class, // Используем статус проектов
        ];

        return $actionMap[$intent] ?? null;
    }

    protected function extractParams(string $intent, string $query, array $conversationContext = []): array
    {
        // Используем универсальный метод извлечения всех параметров
        $params = $this->intentRecognizer->extractAllParams($query);
        
        // Умная обработка порядковых номеров из последних списков
        if (isset($params['contract_id']) && $params['contract_id'] <= 10 && isset($conversationContext['last_contracts'])) {
            $index = $params['contract_id'] - 1;
            if (isset($conversationContext['last_contracts'][$index])) {
                $params['contract_id'] = $conversationContext['last_contracts'][$index]['id'];
            }
        }
        
        if (isset($params['project_id']) && $params['project_id'] <= 10 && isset($conversationContext['last_projects'])) {
            $index = $params['project_id'] - 1;
            if (isset($conversationContext['last_projects'][$index])) {
                $params['project_id'] = $conversationContext['last_projects'][$index]['id'];
            }
        }
        
        return $params;
    }

    public function getOrganizationContext(int $organizationId): array
    {
        $cacheKey = "org_context:{$organizationId}";

        return Cache::remember($cacheKey, 300, function () use ($organizationId) {
            $org = Organization::find($organizationId);
            
            if (!$org) {
                return [];
            }

            $projectsCount = Project::where('organization_id', $organizationId)->count();
            $activeProjectsCount = Project::where('organization_id', $organizationId)
                ->where('status', 'active')
                ->count();

            return [
                'name' => $org->name,
                'projects_count' => $projectsCount,
                'active_projects_count' => $activeProjectsCount,
            ];
        });
    }

    public function getProjectContext(int $projectId): array
    {
        $project = Project::with(['organization'])->find($projectId);
        
        if (!$project) {
            return [];
        }

        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'budget' => $project->budget_amount,
            'start_date' => $project->start_date?->format('Y-m-d'),
            'end_date' => $project->end_date?->format('Y-m-d'),
        ];
    }

    protected function findProjectByName(int $organizationId, string $projectName): ?Project
    {
        return Project::where('organization_id', $organizationId)
            ->where('name', 'LIKE', "%{$projectName}%")
            ->first();
    }

    public function buildSystemPrompt(): string
    {
        return "Ты - помощник по строительным проектам. Общайся как живой человек, коллега по работе.\n\n" .
               
               "КАК ОБЩАТЬСЯ:\n" .
               "❌ НЕ НАДО: 'В системе 4 проекта: 1 проект в статусе «active»'\n" .
               "✅ НАДО: 'Все нормально! У нас 4 проекта, один активный, один завершили, два отменили'\n\n" .
               
               "❌ НЕ НАДО: 'Контракт №3213 от 2025-10-10, сумма 321 312.00 руб.'\n" .
               "✅ НАДО: 'Это контракт на 321 тысячу с Рыкуновым, уже завершен'\n\n" .
               
               "ПРАВИЛА:\n" .
               "1. Говори ПРОСТО и ПО-ЧЕЛОВЕЧЕСКИ\n" .
               "2. Без формальностей типа 'В системе зарегистрировано'\n" .
               "3. Используй разговорные слова: 'нормально', 'все ок', 'вот', 'смотри'\n" .
               "4. Округляй числа: не '321,312.00 руб', а '321 тысячу'\n" .
               "5. На 'Как дела?' отвечай ЖИВО: 'Все нормально!' или 'Порядок!'\n\n" .
               
               "ЧТО ЕСТЬ В КОНТЕКСТЕ (ниже):\n" .
               "Вся нужная инфа УЖЕ там. Просто ПОКАЖИ что видишь.\n" .
               "Если спрашивают 'больше' или 'подробнее' - дай ВСЁ что есть.\n\n" .
               
               "СЛОВАРЬ:\n" .
               "- ГП = валовая прибыль\n" .
               "- Акты = документы выполненных работ\n" .
               "- Счета = счета на оплату\n" .
               "- Заказчик (customer) = тот, кто заказал проект у НАС\n" .
               "- Подрядчик (contractor) = тот, кто работает на НАС по контракту\n" .
               "- Проект = объект строительства (заказчик платит НАМ)\n" .
               "- Контракт = договор с подрядчиком (МЫ платим подрядчику)\n\n" .
               
               "ПРИМЕРЫ ЖИВЫХ ОТВЕТОВ:\n" .
               
               "Вопрос: 'Как дела?'\n" .
               "Ответ: 'Все нормально! У нас 4 проекта идут, один активный, остальные завершены или отменены.'\n\n" .
               
               "Вопрос: 'Контракт 123'\n" .
               "Ответ: 'Вот контракт №123 - это с Рыкуновым на 321 тыщу. Уже завершен, был для торгового центра. Если нужно подробнее - спрашивай!'\n\n" .
               
               "Вопрос: 'А подробнее?'\n" .
               "Ответ: 'Смотри: контракт на 321 тыщу, с валовой прибылью 10% (это 32 тыщи). Подрядчик - ИП Рыкунов, работа была с июня по апрель. Есть 2 акта на 150 и 170 тыщ, все оплачено.'\n\n" .
               
               "❌ ЗАПРЕЩЕНО говорить:\n" .
               "- 'В системе зарегистрировано'\n" .
               "- 'На данный момент'\n" .
               "- 'Пожалуйста, уточните'\n" .
               "- 'К сожалению, нет информации' (если она ЕСТЬ в контексте)\n\n" .
               
               "Будь живым! Общайся как с другом по работе.";
    }
}

