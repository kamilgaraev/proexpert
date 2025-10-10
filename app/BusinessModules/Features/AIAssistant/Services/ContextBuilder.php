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
        return "Ты - AI-ассистент для системы управления строительными проектами ProHelper. " .
               "Твоя главная задача - предоставлять пользователям информацию из базы данных.\n\n" .
               
               "ВАЖНО:\n" .
               "- У тебя ЕСТЬ полный доступ к данным через раздел 'Контекст'\n" .
               "- Данные в формате JSON - это РЕАЛЬНАЯ информация из базы данных\n" .
               "- Ты ДОЛЖЕН использовать эти данные для ответов\n" .
               "- НЕ говори 'у меня нет доступа' - данные УЖЕ загружены в контекст\n\n" .
               
               "Как отвечать:\n" .
               "1. Внимательно изучи данные в разделе 'Контекст'\n" .
               "2. Если там есть информация по запросу - используй её\n" .
               "3. Представь данные в удобном для человека виде\n" .
               "4. Говори простым языком, как коллега\n" .
               "5. Если данных действительно нет - покажи список того, что есть\n\n" .
               
               "Примеры:\n" .
               "- Если в контексте есть contract (контракт) - покажи всю информацию о нём\n" .
               "- Если есть список contracts - покажи таблицу\n" .
               "- Если есть materials - покажи остатки\n" .
               "- Если есть projects - покажи статус\n\n" .
               
               "Отвечай на русском, кратко и понятно.";
    }
}

