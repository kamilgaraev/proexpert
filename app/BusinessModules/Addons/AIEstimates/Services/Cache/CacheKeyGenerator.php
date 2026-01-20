<?php

namespace App\BusinessModules\Addons\AIEstimates\Services\Cache;

use App\BusinessModules\Addons\AIEstimates\DTOs\AIEstimateRequestDTO;

class CacheKeyGenerator
{
    public function generate(AIEstimateRequestDTO $request): string
    {
        // Создаем хеш из параметров запроса
        $params = [
            'description' => mb_strtolower(trim($request->description)),
            'area' => $request->area ? round($request->area, 0) : null, // округляем до целых
            'building_type' => $request->buildingType ? mb_strtolower($request->buildingType) : null,
            'region' => $request->region ? mb_strtolower($request->region) : null,
        ];

        // Убираем null значения
        $params = array_filter($params, fn($v) => $v !== null);

        // Создаем ключ кеша
        $hash = md5(json_encode($params, JSON_UNESCAPED_UNICODE));

        return "ai-estimate:org-{$request->organizationId}:project-{$request->projectId}:{$hash}";
    }

    public function shouldCache(AIEstimateRequestDTO $request): bool
    {
        // Не кешируем запросы с файлами (они уникальны)
        if ($request->hasFiles()) {
            return false;
        }

        // Проверяем что кеширование включено
        if (!config('ai-estimates.cache.enabled', true)) {
            return false;
        }

        // Кешируем только если есть минимальные параметры
        return !empty($request->description) && !empty($request->area);
    }

    public function getOrganizationCacheTags(int $organizationId): array
    {
        return [
            'ai-estimates',
            "ai-estimates:org-{$organizationId}",
        ];
    }

    public function getProjectCacheTags(int $organizationId, int $projectId): array
    {
        return [
            'ai-estimates',
            "ai-estimates:org-{$organizationId}",
            "ai-estimates:project-{$projectId}",
        ];
    }
}
