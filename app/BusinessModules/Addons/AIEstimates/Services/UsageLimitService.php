<?php

namespace App\BusinessModules\Addons\AIEstimates\Services;

use App\BusinessModules\Addons\AIEstimates\AIEstimatesModule;
use App\BusinessModules\Addons\AIEstimates\Models\AIGenerationHistory;
use App\Models\OrganizationModuleActivation;

class UsageLimitService
{
    public function canGenerate(int $organizationId): bool
    {
        // Проверяем активацию модуля
        $activation = $this->getActivation($organizationId);
        
        if (!$activation) {
            return false;
        }

        // Получаем лимиты из настроек модуля
        $module = app(AIEstimatesModule::class);
        $limits = $module->getLimits();
        
        // Получаем текущее использование
        $usage = $this->getCurrentMonthUsage($organizationId);

        return $usage < $limits['max_generations_per_month'];
    }

    public function getCurrentMonthUsage(int $organizationId): int
    {
        return AIGenerationHistory::forOrganization($organizationId)
            ->thisMonth()
            ->count();
    }

    public function getRemainingGenerations(int $organizationId): int
    {
        $module = app(AIEstimatesModule::class);
        $limits = $module->getLimits();
        $usage = $this->getCurrentMonthUsage($organizationId);

        return max(0, $limits['max_generations_per_month'] - $usage);
    }

    public function getLimitInfo(int $organizationId): array
    {
        $module = app(AIEstimatesModule::class);
        $limits = $module->getLimits();
        $usage = $this->getCurrentMonthUsage($organizationId);

        return [
            'max_generations_per_month' => $limits['max_generations_per_month'],
            'used' => $usage,
            'remaining' => max(0, $limits['max_generations_per_month'] - $usage),
            'can_generate' => $usage < $limits['max_generations_per_month'],
            'reset_date' => now()->endOfMonth()->format('Y-m-d'),
        ];
    }

    public function incrementUsage(int $organizationId): void
    {
        // Использование инкрементируется автоматически при создании AIGenerationHistory
        // Этот метод можно использовать для дополнительного трекинга если нужно
    }

    protected function getActivation(int $organizationId): ?OrganizationModuleActivation
    {
        return OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', fn($q) => $q->where('slug', 'ai-estimates'))
            ->first();
    }

    public function isModuleActive(int $organizationId): bool
    {
        return $this->getActivation($organizationId) !== null;
    }

    public function checkFileSizeLimit(int $fileSize): bool
    {
        $module = app(AIEstimatesModule::class);
        $limits = $module->getLimits();
        
        $maxSizeMB = $limits['max_file_size_mb'];
        $maxSizeBytes = $maxSizeMB * 1024 * 1024;

        return $fileSize <= $maxSizeBytes;
    }

    public function checkFilesCountLimit(int $filesCount): bool
    {
        $module = app(AIEstimatesModule::class);
        $limits = $module->getLimits();

        return $filesCount <= $limits['max_files_per_request'];
    }
}
