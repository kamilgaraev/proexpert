<?php

namespace App\BusinessModules\Features\AIAssistant;

use App\Modules\Contracts\ModuleInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class AIAssistantModule implements ModuleInterface
{
    public function getSlug(): string
    {
        return 'ai-assistant';
    }

    public function getName(): string
    {
        return 'AI Ассистент';
    }

    public function getDescription(): string
    {
        return 'Умный помощник с искусственным интеллектом для анализа проектов, генерации отчетов и автоматизации рутинных задач';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getType(): ModuleType
    {
        return ModuleType::ADDON;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getPermissions(): array
    {
        return [
            'ai_assistant.chat',
            'ai_assistant.reports.generate',
            'ai_assistant.analytics.view',
            'ai_assistant.settings.manage',
            'ai_assistant.usage.view',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Чат-ассистент с контекстом проектов',
            'Генерация отчетов и аналитики',
            'Умные рекомендации по оптимизации',
            'Предупреждения о рисках',
            'Поиск по базе знаний',
            'Real-time уведомления',
            'История диалогов',
            'Интеграция с существующей аналитикой',
        ];
    }

    public function getDependencies(): array
    {
        return [
            'organizations',
            'users',
            'project-management',
        ];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getLimits(): array
    {
        return [
            'max_ai_requests_per_month' => 5000,
            'conversation_history_days' => 90,
            'max_file_upload_mb' => 10,
            'max_concurrent_chats' => 10,
        ];
    }

    public function isActive(): bool
    {
        return config('ai-assistant.enabled', true);
    }

    public function canDeactivate(): bool
    {
        return true;
    }

    public function isSystemModule(): bool
    {
        return false;
    }

    public function getIcon(): string
    {
        return 'bot';
    }

    public function getDisplayOrder(): int
    {
        return 99;
    }

    public function getManifest(): array
    {
        return [
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'version' => $this->getVersion(),
            'description' => $this->getDescription(),
            'type' => $this->getType()->value,
            'billing_model' => $this->getBillingModel()->value,
            'permissions' => $this->getPermissions(),
            'features' => $this->getFeatures(),
            'dependencies' => $this->getDependencies(),
            'conflicts' => $this->getConflicts(),
            'limits' => $this->getLimits(),
        ];
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }

    public function canActivate(int $organizationId): bool
    {
        $organization = \App\Models\Organization::find($organizationId);
        
        if (!$organization) {
            return false;
        }

        $billingEngine = app(\App\Modules\Core\BillingEngine::class);
        $module = \App\Models\Module::where('slug', $this->getSlug())->first();
        
        return $module ? $billingEngine->canAfford($organization, $module) : false;
    }
}

