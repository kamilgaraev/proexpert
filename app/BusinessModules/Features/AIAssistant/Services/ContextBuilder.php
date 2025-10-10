<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

class ContextBuilder
{
    protected IntentRecognizer $intentRecognizer;

    public function __construct(IntentRecognizer $intentRecognizer)
    {
        $this->intentRecognizer = $intentRecognizer;
    }

    public function buildContext(string $query, int $organizationId): array
    {
        $intent = $this->intentRecognizer->recognize($query);
        
        $context = [
            'organization' => $this->getOrganizationContext($organizationId),
        ];

        if ($intent === 'project_status' || $intent === 'project_budget' || $intent === 'project_risks') {
            $projectName = $this->intentRecognizer->extractProjectName($query);
            if ($projectName) {
                $project = $this->findProjectByName($organizationId, $projectName);
                if ($project) {
                    $context['project'] = $this->getProjectContext($project->id);
                }
            }
        }

        return $context;
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

