<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Analysis;

use App\BusinessModules\Features\AIAssistant\Services\SystemAnalysisService;
use App\Models\User;

class GenerateSystemAnalysisAction
{
    protected SystemAnalysisService $analysisService;

    public function __construct(SystemAnalysisService $analysisService)
    {
        $this->analysisService = $analysisService;
    }

    /**
     * Запустить комплексный анализ проекта
     * Этот Action используется для интеграции с чатом AIAssistant
     *
     * @param int $projectId
     * @param int $organizationId
     * @param User $user
     * @param array $options Опции анализа (какие разделы включить)
     * @return array
     */
    public function execute(int $projectId, int $organizationId, User $user, array $options = []): array
    {
        // Делегируем основную работу сервису
        return $this->analysisService->analyzeProject($projectId, $organizationId, $user, $options);
    }

    /**
     * Запустить анализ всех проектов организации
     *
     * @param int $organizationId
     * @param User $user
     * @param array $options
     * @return array
     */
    public function executeForOrganization(int $organizationId, User $user, array $options = []): array
    {
        return $this->analysisService->analyzeOrganization($organizationId, $user, $options);
    }
}

