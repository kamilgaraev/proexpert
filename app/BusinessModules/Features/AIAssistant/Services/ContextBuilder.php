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

    public function buildContext(string $query, int $organizationId): array
    {
        $intent = $this->intentRecognizer->recognize($query);
        
        $this->logging->technical('ai.intent.recognized', [
            'intent' => $intent,
            'query' => $query,
            'organization_id' => $organizationId,
        ]);
        
        $context = [
            'organization' => $this->getOrganizationContext($organizationId),
        ];

        // Выполнение Actions на основе распознанного намерения
        $actionResult = $this->executeAction($intent, $organizationId, $query);
        
        if ($actionResult) {
            $context[$intent] = $actionResult;
        }

        return $context;
    }

    protected function executeAction(string $intent, int $organizationId, string $query): ?array
    {
        $actionClass = $this->getActionClass($intent);
        
        if (!$actionClass || !class_exists($actionClass)) {
            return null;
        }

        try {
            $action = app($actionClass);
            
            // Извлекаем параметры из запроса при необходимости
            $params = $this->extractParams($intent, $query);
            
            $result = $action->execute($organizationId, $params);
            
            $this->logging->technical('ai.action.executed', [
                'action' => $actionClass,
                'intent' => $intent,
                'organization_id' => $organizationId,
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
            'project_status' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectStatusAction::class,
            'project_budget' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectBudgetAction::class,
            'project_risks' => \App\BusinessModules\Features\AIAssistant\Actions\Projects\AnalyzeProjectRisksAction::class,
            'contract_search' => \App\BusinessModules\Features\AIAssistant\Actions\Contracts\SearchContractsAction::class,
            'contract_details' => \App\BusinessModules\Features\AIAssistant\Actions\Contracts\GetContractDetailsAction::class,
            'material_stock' => \App\BusinessModules\Features\AIAssistant\Actions\Materials\CheckMaterialStockAction::class,
            'material_forecast' => \App\BusinessModules\Features\AIAssistant\Actions\Materials\ForecastMaterialNeedsAction::class,
        ];

        return $actionMap[$intent] ?? null;
    }

    protected function extractParams(string $intent, string $query): array
    {
        $params = [];
        
        // Извлечение параметров в зависимости от намерения
        switch ($intent) {
            case 'contract_search':
            case 'contract_details':
                // Попытка извлечь номер контракта
                if (preg_match('/№\s*(\d+[\/\-]\d+)/', $query, $matches)) {
                    $params['contract_number'] = $matches[1];
                }
                // Попытка извлечь название контрагента
                if (preg_match('/с\s+([А-Яа-яA-Za-z\s]+)(?:\s|$|,|\.)/u', $query, $matches)) {
                    $params['contractor_name'] = trim($matches[1]);
                }
                break;
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
        return "Ты - AI-ассистент для системы управления строительными проектами. " .
               "Твоя задача - помогать пользователям анализировать проекты, контракты, материалы и финансы. " .
               "Отвечай кратко, по делу, на русском языке. " .
               "Если нужна дополнительная информация, спрашивай. " .
               "Используй данные из контекста для точных ответов.";
    }
}

